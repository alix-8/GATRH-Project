import { useEffect, useState } from 'react'
import { inlineImportError } from './inlineImportError'
import './PreviewImportPanel.css'

const ENTRY_RE = /^(\d+)[.)]\s*(.+)$/
const SKIP_RE = /^(gathering|prayer meeting|worship service|thanksgiving)\s*[:-]/i

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

function preserveHeader(rawText, serializedStudents) {
    const lines = (rawText || '').split(/\r\n|\r|\n/)
    const firstStudentLine = lines.findIndex((line) => ENTRY_RE.test(line.trim()))
    const header = firstStudentLine > 0 ? lines.slice(0, firstStudentLine).join('\n').trimEnd() : ''
    return header ? `${header}\n\n${serializedStudents}` : serializedStudents
}

export default function PreviewIndividualPanel({ rawText, onChange, errors, gatheringType, gatheringRangeLabel, onRowsParsed }) {
    const [students, setStudents] = useState([])

    useEffect(() => {
        const parsedStudents = parseStudents(rawText || '')
        setStudents(parsedStudents)
        onRowsParsed?.(parsedStudents.length)
    }, [rawText, onRowsParsed])

    if (!rawText?.trim()) {
        return <div className="import-preview-placeholder" />
    }

    const updateField = (index, field, value) => {
        const next = students.map((s, i) =>
            i === index ? { ...s, slot: { ...s.slot, [field]: value } } : s
        )
        setStudents(next)
        onChange?.(preserveHeader(rawText, serializeStudents(next)))
    }

    return (
        <div className="preview-cards">
            <div className="preview-header-row preview-header-row-single">
                <div className="preview-header-name">Student Name</div>
                <div className="preview-header-slot">Batch (Time)</div>
                <div className="preview-header-slot">Venue</div>
                <div className="preview-header-slot">Day</div>
            </div>

            {students.map((s, i) => {
                const isUnmatched = errors?.unmatched_lines?.some((u) => u.extracted_name === s.name)
                const timeError = inlineImportError({ errors, studentName: s.name, gatheringType, field: 'batch', value: s.slot.batch, venue: s.slot.venue, rangeLabel: gatheringRangeLabel })
                const venueError = inlineImportError({ errors, studentName: s.name, gatheringType, field: 'venue', value: s.slot.venue, venue: s.slot.venue, rangeLabel: gatheringRangeLabel })
                const dayError = inlineImportError({ errors, studentName: s.name, gatheringType, field: 'day', value: s.slot.day, venue: s.slot.venue, rangeLabel: gatheringRangeLabel })

                return (
                    <div key={i} className={`preview-card preview-card-single ${isUnmatched ? 'preview-card-error' : ''}`}>
                        <div className="preview-card-name">
                            <span className="preview-student-number">{s.number}.</span>
                            <span className="preview-student-name">{s.name}</span>
                        </div>

                        <label className="preview-input-field">
                            <input type="text" maxLength={10} className={`preview-input ${timeError ? 'preview-input-error' : ''}`} placeholder="Time" aria-invalid={Boolean(timeError)} value={s.slot.batch} onChange={(e) => updateField(i, 'batch', e.target.value)} />
                            {timeError && <span className="preview-inline-error">{timeError}</span>}
                        </label>
                        <label className="preview-input-field">
                            <input type="text" maxLength={25} className={`preview-input ${venueError ? 'preview-input-error' : ''}`} placeholder="Venue" aria-invalid={Boolean(venueError)} value={s.slot.venue} onChange={(e) => updateField(i, 'venue', e.target.value)} />
                            {venueError && <span className="preview-inline-error">{venueError}</span>}
                        </label>
                        <label className="preview-input-field">
                            <input type="text" maxLength={9} className={`preview-input ${dayError ? 'preview-input-error' : ''}`} placeholder="Day" aria-invalid={Boolean(dayError)} value={s.slot.day} onChange={(e) => updateField(i, 'day', e.target.value)} />
                            {dayError && <span className="preview-inline-error">{dayError}</span>}
                        </label>
                    </div>
                )
            })}
            {students.length === 0 && (
                <p className="import-preview-empty" role="alert">
                    No student rows found. Include numbered student entries, for example: 1. Student Name | Time | Venue | Day.
                </p>
            )}
        </div>
    )
}
