import { useEffect, useState } from 'react'
import './PreviewImportPanel.css'

const ENTRY_RE = /^(\d+)[.)]\s*(.+)$/
const SKIP_RE = /^(gathering|prayer meeting|worship service|thanksgiving)\s*[:\-]/i

function parseLine(rest) {
    const parts = rest.split('|').map((p) => p.trim())
    return { batch: parts[0] || '', venue: parts[1] || '', day: parts[2] || '' }
}

function serializeLine(slot) {
    return `${slot.batch} | ${slot.venue} | ${slot.day}`
}

function parseStudents(rawText) {
    const lines = rawText.split(/\r\n|\r|\n/)
    const students = []

    for (const rawLine of lines) {
        const line = rawLine.trim()
        if (!line || SKIP_RE.test(line) || line.includes('---') || line.includes('[')) continue

        const entryMatch = line.match(ENTRY_RE)
        if (!entryMatch) continue

        const [, number, rest] = entryMatch
        const pipeIndex = rest.indexOf('|')
        const name = pipeIndex === -1 ? rest.trim() : rest.slice(0, pipeIndex).trim()
        const slotText = pipeIndex === -1 ? '' : rest.slice(pipeIndex + 1)

        students.push({ number, name, slot: parseLine(slotText) })
    }
    return students
}

function serializeStudents(students) {
    return students.map((s) => `${s.number}. ${s.name} | ${serializeLine(s.slot)}`).join('\n')
}

const TIME_RE = /^\d{1,2}:\d{2}\s*(AM|PM|am|pm)?$/

export default function PreviewIndividualPanel({ rawText, onChange, errors, gatheringType }) {
    const [students, setStudents] = useState([])

    useEffect(() => {
        setStudents(parseStudents(rawText || ''))
    }, [rawText])

    if (!rawText?.trim()) {
        return <div className="import-preview-placeholder" />
    }

    const updateField = (index, field, value) => {
        const next = students.map((s, i) =>
            i === index ? { ...s, slot: { ...s.slot, [field]: value } } : s
        )
        setStudents(next)
        onChange?.(serializeStudents(next))
    }

    const fieldHasFormatError = (studentName, field, value) => {
        if (field === 'batch' && value && !TIME_RE.test(value.trim())) return true
        return errors?.format_errors?.some(
            (e) => e.student_name === studentName && e.field === (field === 'batch' ? 'batch' : field)
        )
    }

    const isIncomplete = (studentName) =>
        errors?.incomplete_records?.some((r) => r.student_name === studentName)

    return (
        <div className="preview-cards">
            <div className="preview-header-row preview-header-row-single">
                <div className="preview-header-name">Student Name</div>
                <div className="preview-header-slot">Batch (Time)</div>
                <div className="preview-header-slot">Venue</div>
                <div className="preview-header-slot">Date</div>
            </div>

            {students.map((s, i) => {
                const isUnmatched = errors?.unmatched_lines?.some((u) => u.extracted_name === s.name)
                const incomplete = isIncomplete(s.name)

                return (
                    <div key={i} className={`preview-card preview-card-single ${isUnmatched ? 'preview-card-error' : ''}`}>
                        <div className="preview-card-name">{s.number}. {s.name}</div>

                        <input
                            type="text"
                            className={`preview-input ${fieldHasFormatError(s.name, 'batch', s.slot.batch) ? 'preview-input-error' : ''} ${incomplete && !s.slot.batch ? 'preview-input-warning' : ''}`}
                            placeholder="Time"
                            value={s.slot.batch}
                            onChange={(e) => updateField(i, 'batch', e.target.value)}
                        />
                        <input
                            type="text"
                            className={`preview-input ${incomplete && !s.slot.venue ? 'preview-input-warning' : ''}`}
                            placeholder="Venue"
                            value={s.slot.venue}
                            onChange={(e) => updateField(i, 'venue', e.target.value)}
                        />
                        <input
                            type="text"
                            className={`preview-input ${fieldHasFormatError(s.name, 'day', s.slot.day) ? 'preview-input-error' : ''} ${incomplete && !s.slot.day ? 'preview-input-warning' : ''}`}
                            placeholder="YYYY-MM-DD"
                            value={s.slot.day}
                            onChange={(e) => updateField(i, 'day', e.target.value)}
                        />
                    </div>
                )
            })}
        </div>
    )
}