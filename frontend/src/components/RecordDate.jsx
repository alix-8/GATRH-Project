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

function isNoResponse(status) {
    return String(status ?? '').trim().toLowerCase().replace(/[\s-]+/g, '_') === 'no_response'
}

export default function RecordDate({ value, status, label = 'Date of submission', tooltip = 'date' }) {
    if (isNoResponse(status)) {
        return <>—</>
    }

    const values = (Array.isArray(value) ? value : [value]).filter(Boolean)
    const parsedDates = values.map(parseRecordDate).filter(Boolean)

    if (parsedDates.length === 0) {
        return value ? <>{Array.isArray(value) ? value.join(', ') : value}</> : <>—</>
    }

    const fullDates = [...new Set(parsedDates.map(date => date.toLocaleDateString('en-US', {
        month: 'long',
        day: 'numeric',
        year: 'numeric',
    })))]
    const visibleDates = [...new Set(parsedDates.map(date => date.toLocaleDateString('en-US', {
        month: 'short',
        day: 'numeric',
        year: 'numeric',
    })))]
    const tooltipText = tooltip === 'weekday'
        ? [...new Set(parsedDates.map(date => date.toLocaleDateString('en-US', { weekday: 'long' })))].join(', ')
        : `${label}: ${fullDates.join(', ')}`
    const dateTime = parsedDates.length === 1
        ? `${parsedDates[0].getFullYear()}-${String(parsedDates[0].getMonth() + 1).padStart(2, '0')}-${String(parsedDates[0].getDate()).padStart(2, '0')}`
        : undefined

    return (
        <time dateTime={dateTime} title={tooltipText}>
            {visibleDates.join(', ')}
        </time>
    )
}
