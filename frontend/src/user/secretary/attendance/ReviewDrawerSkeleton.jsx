export default function ReviewDrawerSkeleton() {
    return (
        <div className="review-drawer-skeleton" role="status" aria-label="Loading attendance details" aria-busy="true">
            <div className="review-drawer-skeleton-person"><i /><span><b /><b /></span></div>
            <div className="review-drawer-skeleton-metrics">{[0, 1, 2].map(index => <i key={index} />)}</div>
            <div className="review-drawer-skeleton-title" />
            {[0, 1, 2, 3, 4].map(index => (
                <div className="review-drawer-skeleton-row" key={index}><i /><span /><b /></div>
            ))}
        </div>
    )
}
