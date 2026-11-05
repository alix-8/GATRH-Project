export default function SubmissionDrawerSkeleton({ isRecordView = false, approved = false }) {
    const label = isRecordView ? 'Loading attendance record details' : 'Loading submission details'

    return (
        <div className={`ph-drawer-loading${isRecordView ? ' ph-drawer-loading-record' : ''}`} role="status" aria-label={label} aria-busy="true">
            {approved && <div className="ph-drawer-loading-approved" aria-hidden="true" />}
            <div className="ph-drawer-loading-person"><i /><span><b /><b /></span></div>
            <div className="ph-drawer-loading-kpis">{[0, 1, 2].map(index => <i key={index} />)}</div>
            <div className="ph-drawer-loading-title" />
            {[0, 1, 2, 3, 4].map(index => <div className="ph-drawer-loading-row" key={index}><i /><span /><b /></div>)}
        </div>
    )
}
