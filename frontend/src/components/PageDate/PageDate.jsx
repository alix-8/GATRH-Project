import './PageDate.css'

function PageDate({ className = '' }) {
    const formattedDate = new Date().toLocaleDateString('en-US', {
        weekday: 'long',
        year: 'numeric',
        month: 'long',
        day: 'numeric',
    })

    return <span className={`page-date ${className}`.trim()}>{formattedDate}</span>
}

export default PageDate
