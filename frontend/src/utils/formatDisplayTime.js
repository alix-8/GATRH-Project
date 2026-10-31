// Presentation only: do not use this formatter to validate or save time inputs.
export default function formatDisplayTime(value, fallback = '—') {
    const text = String(value ?? '').trim()
    if (!text || ['—', '---', '-'].includes(text)) return fallback

    const match = /^(\d{1,2})(?::(\d{2})(?::(\d{2}))?)?\s*(am|pm)?$/i.exec(text)
    if (!match) return text

    const hour = Number(match[1])
    const minute = Number(match[2] ?? 0)
    const second = Number(match[3] ?? 0)
    const meridiem = match[4]?.toUpperCase()
    if (minute > 59 || second > 59 || hour > (meridiem ? 12 : 23) || (meridiem && hour === 0)) {
        return text
    }

    const period = meridiem || (hour >= 12 ? 'PM' : 'AM')
    return `${hour % 12 || 12}:${String(minute).padStart(2, '0')} ${period}`
}
