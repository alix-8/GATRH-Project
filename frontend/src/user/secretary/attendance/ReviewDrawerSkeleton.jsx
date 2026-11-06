<<<<<<< HEAD
export default function ReviewDrawerSkeleton() {
    return (
        <div className="review-drawer-skeleton" role="status" aria-label="Loading attendance details" aria-busy="true">
            <div className="review-drawer-skeleton-person"><i /><span><b /><b /></span></div>
            <div className="review-drawer-skeleton-metrics">{[0, 1, 2].map(index => <i key={index} />)}</div>
            <div className="review-drawer-skeleton-title" />
            {[0, 1, 2, 3, 4].map(index => (
                <div className="review-drawer-skeleton-row" key={index}><i /><span /><b /></div>
            ))}
=======
import './ReviewDrawerSkeleton.css'

function Block({ className = '' }) {
    return <span className={`review-drawer-skeleton-block ${className}`} />
}

export default function ReviewDrawerSkeleton() {
    return (
        <div className="review-drawer-skeleton" role="status" aria-label="Loading attendance details" aria-busy="true">
            <div aria-hidden="true">
                <div className="review-drawer-skeleton-submitter">
                    <Block className="review-drawer-skeleton-label" />
                    <div className="review-drawer-skeleton-person">
                        <Block className="review-drawer-skeleton-avatar" />
                        <div><Block className="review-drawer-skeleton-name" /><Block className="review-drawer-skeleton-role" /></div>
                    </div>
                </div>

                <div className="review-drawer-skeleton-stats">
                    {[0, 1, 2].map((item) => (
                        <div className="review-drawer-skeleton-stat" key={item}>
                            <Block className="review-drawer-skeleton-stat-icon" />
                            <Block className="review-drawer-skeleton-stat-value" />
                            <Block className="review-drawer-skeleton-stat-label" />
                        </div>
                    ))}
                </div>

                <div className="review-drawer-skeleton-list-heading">
                    <h3>Attendance List</h3>
                    <Block className="review-drawer-skeleton-filter" />
                    <Block className="review-drawer-skeleton-search" />
                </div>

                <div className="review-drawer-skeleton-table">
                    <div className="review-drawer-skeleton-table-head">
                        {['Name', 'Time', 'Day', 'Venue', 'Status'].map((heading) => <span key={heading}>{heading}</span>)}
                    </div>
                    {[0, 1, 2, 3, 4].map((row) => (
                        <div className="review-drawer-skeleton-table-row" key={row}>
                            {[0, 1, 2, 3, 4].map((cell) => <Block key={cell} />)}
                        </div>
                    ))}
                </div>
            </div>
>>>>>>> backend
        </div>
    )
}
