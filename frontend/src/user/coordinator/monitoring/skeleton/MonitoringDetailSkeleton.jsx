import './MonitoringSkeleton.css'
import './MonitoringDetailSkeleton.css'

function Line({ className = '' }) {
    return <span className={`monitoring-skeleton-block monitoring-detail-skeleton-line ${className}`} />
}

export default function MonitoringDetailSkeleton({ weekTabs, week }) {
    return (
        <div className="monitoring-detail-body monitoring-detail-skeleton" aria-busy="true" aria-label="Loading week details">
            <div className="monitoring-detail-skeleton-progress" aria-hidden="true">
                <div><Line /><Line className="monitoring-detail-skeleton-percentage" /></div>
                <Line className="monitoring-detail-skeleton-track" />
            </div>
            <div className="monitoring-detail-skeleton-program-head" aria-hidden="true">
                <span className="monitoring-skeleton-block monitoring-detail-skeleton-icon" />
                <div><Line /><Line /></div>
            </div>
            <h3 className="monitoring-detail-section-title">Week Details</h3>
            {weekTabs}
            <section className="monitoring-week-details">
                <h4>{week?.label}<small> - {week?.description}</small></h4>
                <div className="monitoring-week-detail-headings">
                    <span>Gathering</span><span>Date</span><span>Status</span><span>Remarks</span>
                </div>
                <div aria-hidden="true">
                    {['Prayer Meeting', 'Worship Service', 'Thanksgiving'].map(gathering => (
                        <div className="monitoring-week-detail-row" key={gathering}>
                            <span>{gathering}</span>
                            <Line /><Line /><Line />
                        </div>
                    ))}
                </div>
            </section>
        </div>
    )
}
