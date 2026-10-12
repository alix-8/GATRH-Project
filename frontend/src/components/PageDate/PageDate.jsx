import './PageDate.css'

function PageDate({ className = '' }) {
    const today = new Date()
    const formattedDate = today.toLocaleDateString('en-US', {
        year: 'numeric',
        month: 'long',
        day: 'numeric',
    })
    const weekday = today.toLocaleDateString('en-US', { weekday: 'long' })

    return (
        <time className={`page-date ${className}`.trim()}>
            <span className="page-date-value">{formattedDate}</span>
            <span className="page-date-weekday">{weekday}</span>
        </time>
    )
}

export default PageDate
