import { useEffect, useRef, useState } from 'react'
import { inlineImportError } from './inlineImportError'
import './PreviewImportPanel.css'

const SLOT_RE = /^(PM|WS|TG)[:\s]\s*(.*)$/i
const ENTRY_RE = /^(\d+)[.)]\s*(.+)$/
const SKIP_RE = /^(gathering|prayer meeting|worship service|thanksgiving)\s*[:-]/i
const NAME_RE = /^[\p{L}\p{M}\p{N}][\p{L}\p{M}\p{N}'’.,()& -]*$/u

function isUnnumberedStudentName(line) {
    return Boolean(line)
        && !ENTRY_RE.test(line)
        && !SKIP_RE.test(line)
        && !line.includes('|')
        && !line.includes(':')
        && !line.includes('---')
        && !line.includes('[')
        && NAME_RE.test(line)
}

function emptyStudent(number, name) {
    return {
        number,
        name,
        pm: { day: '', time: '', venue: '' },
        ws: { day: '', time: '', venue: '' },
        tg: { day: '', time: '', venue: '' },
    }
}

function parseSlotLine(line) {
    const parts = line.split('|').map((p) => p.trim())
    const clean = (v) => (v && !/^(day|time|venue)$/i.test(v) ? v : '')
    return {
        day: clean(parts[0]),
        time: clean(parts[1]),
        venue: clean(parts.slice(2).join(' | ')),
    }
}

function parseStudents(rawText) {
    const lines = rawText.split(/\r\n|\r|\n/)
    const students = []
    let current = null
    let slotType = null

    for (const rawLine of lines) {
        const line = rawLine.trim()
        if (!line || SKIP_RE.test(line) || line.includes('---') || line.includes('[')) continue

        const entryMatch = line.match(ENTRY_RE)
        if (entryMatch) {
            if (current) students.push(current)
            current = emptyStudent(entryMatch[1], entryMatch[2].trim())
            slotType = null
            continue
        }

        const slotMatch = line.match(SLOT_RE)
        if (slotMatch) {
            if (!current) continue
            slotType = slotMatch[1].toLowerCase()
            const rest = slotMatch[2].trim()
            if (rest) current[slotType] = parseSlotLine(rest)
            continue
        }

        if (isUnnumberedStudentName(line)) {
            if (current) students.push(current)
            current = emptyStudent(String(students.length + 1), line)
            slotType = null
            continue
        }

        if (!current) continue

        if (slotType) {
            current[slotType] = parseSlotLine(line)
            slotType = null
        }
    }
    if (current) students.push(current)
    return students
}

function serializeStudents(students) {
    return students.map((s) => {
        const slot = (v) => `${v.day || ''}| ${v.time || ''} | ${v.venue || ''}`
        return `${s.number}. ${s.name}\nPM: ${slot(s.pm)}\nWS: ${slot(s.ws)}\nTG: ${slot(s.tg)}`
    }).join('\n\n')
}

function preserveHeader(rawText, serializedStudents) {
    const lines = (rawText || '').split(/\r\n|\r|\n/)
    const firstStudentLine = lines.findIndex((line) => ENTRY_RE.test(line.trim()) || isUnnumberedStudentName(line.trim()))
    const header = firstStudentLine > 0 ? lines.slice(0, firstStudentLine).join('\n').trimEnd() : ''
    return header ? `${header}\n\n${serializedStudents}` : serializedStudents
}

export default function PreviewImportPanel({ rawText, onChange, errors, gatheringRanges, gatheringRangeLabels, onRowsParsed }) {
    const [students, setStudents] = useState([])
    const lastEmittedText = useRef(null)

    useEffect(() => {
        // Editing a field emits a serialized version of this same preview. Do not
        // parse it back immediately: parsing trims trailing spaces while typing.
        if (rawText === lastEmittedText.current) {
            lastEmittedText.current = null
            return
        }
        const parsedStudents = parseStudents(rawText || '')
        setStudents(parsedStudents)
        onRowsParsed?.(parsedStudents.length)
    }, [rawText, onRowsParsed])

    if (!rawText?.trim()) {
        return <div className="import-preview-placeholder" />
    }

    const updateSlot = (index, slotType, field, value) => {
        const next = students.map((s, i) =>
            i === index ? { ...s, [slotType]: { ...s[slotType], [field]: value } } : s
        )
        setStudents(next)
        const nextRawText = preserveHeader(rawText, serializeStudents(next))
        lastEmittedText.current = nextRawText
        onChange?.(nextRawText)
    }

    return (
        <div className="preview-cards">
            <div className="preview-header-row">
                <div className="preview-header-name">Student Name</div>
                <div className="preview-header-slot">PM Slot</div>
                <div className="preview-header-slot">WS Slot</div>
                <div className="preview-header-slot">TG Slot</div>
            </div>

            {students.map((s, i) => {
                const isUnmatched = errors?.unmatched_lines?.some((u) => u.extracted_name === s.name)
                return (
                    <div key={i} className={`preview-card ${isUnmatched ? 'preview-card-error' : ''}`}>
                        <div className="preview-card-name">{s.number}. {s.name}</div>

                        {['pm', 'ws', 'tg'].map((slotType) => (
                            <div className="preview-slot-col" key={slotType}>
                                {['day', 'time', 'venue'].map((field) => {
                                    const errorMessage = inlineImportError({
                                        errors,
                                        studentName: s.name,
                                        gatheringType: slotType,
                                        field,
                                        value: s[slotType][field],
                                        venue: s[slotType].venue,
                                        range: gatheringRanges?.[slotType],
                                        rangeLabel: gatheringRangeLabels?.[slotType],
                                    })
                                    return (
                                        <label className="preview-input-field" key={field}>
                                            <input
                                                type="text"
                                                maxLength={field === 'venue' ? 25 : field === 'day' ? 9 : 10}
                                                className={`preview-input ${errorMessage ? 'preview-input-error' : ''}`}
                                                placeholder={field === 'day' ? 'Day' : field === 'time' ? 'Time' : 'Venue'}
                                                aria-invalid={Boolean(errorMessage)}
                                                value={s[slotType][field]}
                                                onChange={(e) => updateSlot(i, slotType, field, e.target.value)}
                                            />
                                            {errorMessage && <span className="preview-inline-error">{errorMessage}</span>}
                                        </label>
                                    )
                                })}
                            </div>
                        ))}
                    </div>
                )
            })}
            {students.length === 0 && (
                <p className="import-preview-empty">No student rows found. Check the student names and attendance format.</p>
            )}
        </div>
    )
}
