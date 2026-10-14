import { useEffect, useState } from 'react'
import './PreviewImportPanel.css'

const SLOT_RE = /^(PM|WS|TG)[:\s]\s*(.*)$/i
const ENTRY_RE = /^(\d+)[.)]\s*(.+)$/
const SKIP_RE = /^(gathering|prayer meeting|worship service|thanksgiving)\s*[:\-]/i

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
            current = { number: entryMatch[1], name: entryMatch[2].trim(), pm: { day: '', time: '', venue: '' }, ws: { day: '', time: '', venue: '' }, tg: { day: '', time: '', venue: '' } }
            slotType = null
            continue
        }

        if (!current) continue

        const slotMatch = line.match(SLOT_RE)
        if (slotMatch) {
            slotType = slotMatch[1].toLowerCase()
            const rest = slotMatch[2].trim()
            if (rest) current[slotType] = parseSlotLine(rest)
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

function serializeStudents(students) {
    return students.map((s) => {
        const slot = (v) => `${v.day || ''}| ${v.time || ''} | ${v.venue || ''}`
        return `${s.number}. ${s.name}\nPM: ${slot(s.pm)}\nWS: ${slot(s.ws)}\nTG: ${slot(s.tg)}`
    }).join('\n\n')
}

const TIME_RE = /^(?:(?:[1-9]|1[0-2])\s*(?:AM|PM)|\d{1,2}:\d{2}\s*(?:AM|PM)?)$/i

function fieldError(slotType, field, value, errors) {
    if (!errors) return false
    if (field === 'time' && value && !TIME_RE.test(value.trim())) return true
    return errors.format_errors?.some((e) => e.gathering_type === slotType && e.field === field)
}

function isIncomplete(studentName, slotType, errors) {
    if (!errors) return false
    return errors.incomplete_records?.some((r) => r.student_name === studentName && r.gathering_type === slotType)
}

export default function PreviewImportPanel({ rawText, onChange, errors }) {
    const [students, setStudents] = useState([])

    useEffect(() => {
        setStudents(parseStudents(rawText || ''))
    }, [rawText])

    if (!rawText?.trim()) {
        return <div className="import-preview-placeholder" />
    }

    const updateSlot = (index, slotType, field, value) => {
        const next = students.map((s, i) =>
            i === index ? { ...s, [slotType]: { ...s[slotType], [field]: value } } : s
        )
        setStudents(next)
        onChange?.(serializeStudents(next))
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
                                    const hasError = fieldError(slotType, field, s[slotType][field], errors)
                                    const incomplete = isIncomplete(s.name, slotType, errors)
                                    return (
                                        <input
                                            key={field}
                                            type="text"
                                            className={`preview-input ${hasError ? 'preview-input-error' : ''} ${incomplete && !s[slotType][field] ? 'preview-input-warning' : ''}`}
                                            placeholder={field === 'day' ? 'Day' : field === 'time' ? 'Time' : 'Venue'}
                                            value={s[slotType][field]}
                                            onChange={(e) => updateSlot(i, slotType, field, e.target.value)}
                                        />
                                    )
                                })}
                            </div>
                        ))}
                    </div>
                )
            })}
        </div>
    )
}
