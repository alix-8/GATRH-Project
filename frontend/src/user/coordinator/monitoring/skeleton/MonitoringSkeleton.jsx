import './MonitoringSkeleton.css'

function Placeholder({ className = '' }) {
    return <span className={`monitoring-skeleton-block ${className}`} />
}

export default function MonitoringSkeleton({ month, tableOnly = false }) {
    const [year, monthNumber] = month.split('-').map(Number)
    const firstDay = new Date(year, monthNumber - 1, 1)
    const leadingDays = (firstDay.getDay() - 3 + 7) % 7
    const daysInMonth = new Date(year, monthNumber, 0).getDate()
    const weekCount = Math.ceil((leadingDays + daysInMonth) / 7)

    const table = (
        <div className="monitoring-skeleton-table-scroll" aria-hidden="true">
            <div className="monitoring-skeleton-table" style={{ '--skeleton-week-count': weekCount, '--skeleton-week-width': `${54 / weekCount}fr` }}>
                <div className="monitoring-skeleton-table-heading">
                    <Placeholder /><Placeholder />
                    {Array.from({ length: weekCount }, (_, index) => <Placeholder key={index} />)}
                    <Placeholder /><Placeholder />
                </div>
                {[0, 1, 2].map(group => (
                    <div className="monitoring-skeleton-program" key={group}>
                        <Placeholder className="monitoring-skeleton-group-title" />
                        {[0, 1, 2, 3].map(row => (
                            <div className="monitoring-skeleton-table-row" key={row}>
                                <Placeholder className="monitoring-skeleton-line" />
                                <Placeholder className="monitoring-skeleton-row-fill" />
                            </div>
                        ))}
                    </div>
                ))}
            </div>
        </div>
    )

    if (tableOnly) {
        return <div role="status" aria-label="Loading monitoring records" aria-busy="true">{table}</div>
    }

    return (
        <div className="monitoring-skeleton" role="status" aria-label="Loading coordinator monitoring" aria-busy="true">
            <div className="monitoring-skeleton-layout" aria-hidden="true">
                <aside className="monitoring-skeleton-sidebar">
                    <Placeholder className="monitoring-skeleton-logo" />
                    <div className="monitoring-skeleton-sidebar-links">
                        {[0, 1, 2].map(index => (
                            <div className="monitoring-skeleton-sidebar-link" key={index}>
                                <Placeholder className="monitoring-skeleton-nav-icon" />
                                <Placeholder className="monitoring-skeleton-line" />
                            </div>
                        ))}
                    </div>
                    <div className="monitoring-skeleton-sidebar-footer">
                        <Placeholder className="monitoring-skeleton-line" />
                        <Placeholder className="monitoring-skeleton-nav-icon" />
                    </div>
                </aside>
                <div className="monitoring-skeleton-main">
                    <div className="monitoring-skeleton-header">
                        <div className="monitoring-skeleton-heading">
                            <Placeholder className="monitoring-skeleton-title" />
                            <Placeholder className="monitoring-skeleton-subtitle" />
                        </div>
                        <div className="monitoring-skeleton-date">
                            <Placeholder className="monitoring-skeleton-line" />
                            <Placeholder className="monitoring-skeleton-line" />
                        </div>
                    </div>
                    <div className="monitoring-skeleton-content">
                        <div className="monitoring-month-navigation">
                            <div className="monitoring-month-controls">
                                <Placeholder className="monitoring-month-skeleton-arrow" />
                                <Placeholder className="monitoring-month-skeleton-label" />
                                <Placeholder className="monitoring-month-skeleton-arrow" />
                            </div>
                        </div>
                        {table}
                    </div>
                </div>
            </div>
        </div>
    )
}
