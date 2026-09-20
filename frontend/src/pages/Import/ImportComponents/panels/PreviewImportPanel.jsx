import { useMemo } from 'react'
import './PreviewImportPanel.css'

const SLOT_RE = /^(PM|WS|TG)[:\s]\s*(.*)$/i
const ENTRY_RE = /^\d+[.)]\s*(.+)$/
const HEADER_RE = /^\s*(.+?)\s*[-–:]\s*year\s*\d+/i

function parseSlotLine(line) {
    const parts = line.trim().split(/\s+/)
    return { day: parts[0] || '—', time: parts[1] || '—', venue: parts.slice(2).join(' ') || '—' }
}

function parseBatchPreview(rawText) {
    const lines = rawText.split(/\r\n|\r|\n/)
    const students = []
    let current = null
    let slotType = null
    let start = 0

    if (HEADER_RE.test((lines[0] || '').trim())) start = 1

    for (let i = start; i < lines.length; i++) {
        const line = lines[i].trim()
        if (!line) continue

        const entryMatch = line.match(ENTRY_RE)
        if (entryMatch) {
            if (current) students.push(current)
            current = { name: entryMatch[1].trim(), pm: null, ws: null, tg: null }
            slotType = null
            continue
        }

        if (!current) continue

        const slotMatch = line.match(SLOT_RE)
        if (slotMatch) {
            slotType = slotMatch[1].toLowerCase()
            const rest = slotMatch[2].trim()
            current[slotType] = rest ? parseSlotLine(rest) : null
            continue
        }

        if (slotType) {
            current[slotType] = parseSlotLine(line)
            slotType = null
        }
    }
    if (current) students.push(current)

    return students
}

export default function PreviewImportPanel({ rawText }) {
    const students = useMemo(() => parseBatchPreview(rawText || ''), [rawText])

    if (!rawText.trim()) {
        return <div className="import-preview-placeholder" />
    }

    return (
        <div className="batch-preview">
            <table className="batch-preview-table">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>PM</th>
                        <th>WS</th>
                        <th>TG</th>
                    </tr>
                </thead>
                <tbody>
                    {students.map((s, i) => (
                        <tr key={i}>
                            <td>{s.name}</td>
                            <td>{s.pm ? `${s.pm.day} ${s.pm.time}` : '—'}</td>
                            <td>{s.ws ? `${s.ws.day} ${s.ws.time}` : '—'}</td>
                            <td>{s.tg ? `${s.tg.day} ${s.tg.time}` : '—'}</td>
                        </tr>
                    ))}
                    {students.length === 0 && (
                        <tr><td colSpan={4} className="batch-preview-empty">No entries detected yet.</td></tr>
                    )}
                </tbody>
            </table>
        </div>
    )
}