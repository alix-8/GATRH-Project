<?php

namespace App\Services\Admin;

use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use SimpleXMLElement;

class StudentImportFileReader
{
    private const XML_NAMESPACE = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';

    public function read(UploadedFile $file): array
    {
        return strtolower($file->getClientOriginalExtension()) === 'xlsx'
            ? $this->readXlsx($file->getRealPath())
            : $this->readCsv($file->getRealPath());
    }

    private function readCsv(string $path): array
    {
        $handle = fopen($path, 'r');
        if ($handle === false) {
            $this->invalidFile('Unable to read the CSV file.');
        }

        try {
            $rows = [];
            while (($row = fgetcsv($handle)) !== false) {
                $rows[] = $row;
            }

            return $rows;
        } finally {
            fclose($handle);
        }
    }

    private function readXlsx(string $path): array
    {
        $archive = file_get_contents($path);
        if ($archive === false) {
            $this->invalidFile('Unable to read the Excel file.');
        }

        $entries = $this->zipEntries($archive);
        $sheets = array_values(array_filter(array_keys($entries), fn ($name) => preg_match('~^xl/worksheets/sheet\d+\.xml$~', $name)));
        natsort($sheets);
        $sheetName = reset($sheets);
        if ($sheetName === false) {
            $this->invalidFile('The Excel file has no worksheet.');
        }

        $sharedStrings = [];
        if (isset($entries['xl/sharedStrings.xml'])) {
            $stringsXml = $this->parseXml($this->entryContents($archive, $entries['xl/sharedStrings.xml']));
            $stringsXml->registerXPathNamespace('s', self::XML_NAMESPACE);
            foreach ($stringsXml->xpath('//s:si') ?: [] as $item) {
                $sharedStrings[] = $this->textNodes($item);
            }
        }

        $sheet = $this->parseXml($this->entryContents($archive, $entries[$sheetName]));
        $sheet->registerXPathNamespace('s', self::XML_NAMESPACE);
        $rows = [];
        foreach ($sheet->xpath('//s:sheetData/s:row') ?: [] as $row) {
            $row->registerXPathNamespace('s', self::XML_NAMESPACE);
            $values = [];
            $nextColumn = 0;
            foreach ($row->xpath('./s:c') ?: [] as $cell) {
                $cell->registerXPathNamespace('s', self::XML_NAMESPACE);
                $reference = (string) $cell['r'];
                if (preg_match('/^([A-Z]+)\d+$/i', $reference, $matches)) {
                    $nextColumn = 0;
                    foreach (str_split(strtoupper($matches[1])) as $letter) {
                        $nextColumn = $nextColumn * 26 + ord($letter) - 64;
                    }
                    $nextColumn--;
                }

                if ($nextColumn > 100) {
                    $this->invalidFile('The Excel worksheet has too many columns.');
                }

                $type = (string) $cell['t'];
                $value = (string) (($cell->xpath('./s:v') ?: [])[0] ?? '');
                if ($type === 's') {
                    $value = $sharedStrings[(int) $value] ?? '';
                } elseif ($type === 'inlineStr') {
                    $value = $this->textNodes($cell);
                }
                $values[$nextColumn++] = $value;
            }
            if ($values !== []) {
                $rows[] = array_replace(array_fill(0, max(array_keys($values)) + 1, ''), $values);
            }
        }

        return $rows;
    }

    private function textNodes(SimpleXMLElement $element): string
    {
        $element->registerXPathNamespace('s', self::XML_NAMESPACE);

        return implode('', array_map('strval', $element->xpath('.//s:t') ?: []));
    }

    private function parseXml(string $xml): SimpleXMLElement
    {
        $parsed = @simplexml_load_string($xml, SimpleXMLElement::class, LIBXML_NONET);
        if ($parsed === false) {
            $this->invalidFile('The Excel worksheet is invalid.');
        }

        return $parsed;
    }

    private function zipEntries(string $archive): array
    {
        $end = strrpos($archive, "PK\x05\x06");
        if ($end === false || strlen($archive) < $end + 22) {
            $this->invalidFile('The Excel file is not a valid .xlsx workbook.');
        }

        $directory = unpack('vitems/Vsize/Voffset', substr($archive, $end + 10, 10));
        if ($directory === false || $directory['items'] > 1000
            || $directory['offset'] + $directory['size'] > $end) {
            $this->invalidFile('The Excel workbook is damaged.');
        }
        $position = $directory['offset'];
        $entries = [];
        for ($index = 0; $index < $directory['items']; $index++) {
            if ($position + 46 > $end || substr($archive, $position, 4) !== "PK\x01\x02") {
                $this->invalidFile('The Excel workbook is damaged.');
            }
            $entry = unpack('vmethod', substr($archive, $position + 10, 2))
                + unpack('Vcompressed/Vuncompressed', substr($archive, $position + 20, 8))
                + unpack('vnameLength/vextraLength/vcommentLength', substr($archive, $position + 28, 6))
                + unpack('VlocalOffset', substr($archive, $position + 42, 4));
            $name = substr($archive, $position + 46, $entry['nameLength']);
            $entries[$name] = $entry;
            $position += 46 + $entry['nameLength'] + $entry['extraLength'] + $entry['commentLength'];
            if ($position > $end) {
                $this->invalidFile('The Excel workbook is damaged.');
            }
        }

        return $entries;
    }

    private function entryContents(string $archive, array $entry): string
    {
        if ($entry['uncompressed'] > 10 * 1024 * 1024 || $entry['compressed'] > 2 * 1024 * 1024) {
            $this->invalidFile('The Excel worksheet is too large.');
        }

        $offset = $entry['localOffset'];
        if ($offset + 30 > strlen($archive) || substr($archive, $offset, 4) !== "PK\x03\x04") {
            $this->invalidFile('The Excel workbook is damaged.');
        }

        $header = unpack('vnameLength/vextraLength', substr($archive, $offset + 26, 4));
        if ($header === false) {
            $this->invalidFile('The Excel workbook is damaged.');
        }
        $start = $offset + 30 + $header['nameLength'] + $header['extraLength'];
        if ($start + $entry['compressed'] > strlen($archive)) {
            $this->invalidFile('The Excel workbook is damaged.');
        }
        $data = substr($archive, $start, $entry['compressed']);
        $contents = match ($entry['method']) {
            0 => $data,
            8 => @gzinflate($data, 10 * 1024 * 1024),
            default => false,
        };
        if ($contents === false || strlen($contents) !== $entry['uncompressed']) {
            $this->invalidFile('The Excel workbook is damaged or uses unsupported compression.');
        }

        return $contents;
    }

    private function invalidFile(string $message): never
    {
        throw ValidationException::withMessages(['file' => [$message]]);
    }
}
