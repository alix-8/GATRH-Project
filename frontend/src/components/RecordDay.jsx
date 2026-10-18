function parseRecordDate(value) {
    if (!value) return null

    if (value instanceof Date) {
        return Number.isNaN(value.getTime()) ? null : value
    }

    const text = String(value).trim()
    const isoDate = text.match(/^(\d{4})-(\d{2})-(\d{2})/)
    const date = isoDate
        ? new Date(Number(isoDate[1]), Number(isoDate[2]) - 1, Number(isoDate[3]))
        : new Date(text)

    return Number.isNaN(date.getTime()) ? null : date
}

function formatFullDate(date) {
    return date.toLocaleDateString('en-US', {
        month: 'long',
        day: 'numeric',
        year: 'numeric',
    })
}

export default function RecordDay({ value, status, className }) {
    if (String(status ?? '').trim().toLowerCase().replace(/[\s-]+/g, '_') === 'no_response') {
        return <>—</>
    }

    const values = (Array.isArray(value) ? value : [value]).filter(Boolean)
    const parsedDates = values.map(parseRecordDate).filter(Boolean)

    if (parsedDates.length === 0) {
        return value ? <>{value}</> : <>—</>
    }

    const dayNames = [...new Set(parsedDates.map(date => date.toLocaleDateString('en-US', { weekday: 'long' })))]
    const fullDates = [...new Set(parsedDates.map(formatFullDate))]
    const dateTime = parsedDates.length === 1
        ? `${parsedDates[0].getFullYear()}-${String(parsedDates[0].getMonth() + 1).padStart(2, '0')}-${String(parsedDates[0].getDate()).padStart(2, '0')}`
        : undefined

    return (
        <time className={className} dateTime={dateTime} title={fullDates.join(', ')} aria-label={fullDates.join(', ')}>
            {dayNames.join(', ')}
        </time>
    )
}
