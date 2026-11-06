<<<<<<< HEAD
export default function SubmissionDrawerSkeleton({ isRecordView = false, approved = false }) {
    const label = isRecordView ? 'Loading attendance record details' : 'Loading submission details'

    return (
        <div className={`ph-drawer-loading${isRecordView ? ' ph-drawer-loading-record' : ''}`} role="status" aria-label={label} aria-busy="true">
            {approved && <div className="ph-drawer-loading-approved" aria-hidden="true" />}
            <div className="ph-drawer-loading-person"><i /><span><b /><b /></span></div>
            <div className="ph-drawer-loading-kpis">{[0, 1, 2].map(index => <i key={index} />)}</div>
            <div className="ph-drawer-loading-title" />
            {[0, 1, 2, 3, 4].map(index => <div className="ph-drawer-loading-row" key={index}><i /><span /><b /></div>)}
=======
import RecordApprovedIcon from '../../../assets/icons/RecordApprovedIcon.svg'
import './SubmissionDrawerSkeleton.css'

function Block({ className = '' }) {
    return <span className={`ph-drawer-skeleton-block ${className}`} />
}

export default function SubmissionDrawerSkeleton({ isRecordView, approved }) {
    return (
        <div className="ph-drawer-skeleton" role="status" aria-label={isRecordView ? 'Loading record details' : 'Loading submission details'} aria-busy="true">
            <div aria-hidden="true">
                <div className="ph-drawer-submitted-card ph-drawer-skeleton-submitted">
                    {approved && (
                        <div className="ph-record-approved-banner">
                            <img src={RecordApprovedIcon} alt="" />
                            Sent to Chancellor &amp; Coordinator
                        </div>
                    )}
                    {!isRecordView && <span className="ph-drawer-submitted-label">Submitted by</span>}
                    <div className="ph-drawer-skeleton-person">
                        <Block className="ph-drawer-skeleton-avatar" />
                        <div>
                            <Block className="ph-drawer-skeleton-person-name" />
                            <Block className="ph-drawer-skeleton-person-role" />
                            {isRecordView && <><Block className="ph-drawer-skeleton-person-date" /><Block className="ph-drawer-skeleton-person-program" /></>}
                        </div>
                    </div>
                </div>

                <section className={isRecordView ? 'ph-record-summary' : undefined}>
                    {isRecordView && <h3>Attendance Summary</h3>}
                    <div className="ph-drawer-kpis ph-drawer-skeleton-kpis">
                        {[0, 1, 2].map((item) => (
                            <article key={item}>
                                <Block className="ph-drawer-skeleton-kpi-icon" />
                                <Block className="ph-drawer-skeleton-kpi-value" />
                                <Block className="ph-drawer-skeleton-kpi-label" />
                            </article>
                        ))}
                    </div>
                </section>

                <div className="ph-drawer-list-heading ph-drawer-skeleton-list-heading">
                    <h3>{isRecordView ? 'Student List' : 'Attendance List'}</h3>
                    <div className="ph-drawer-skeleton-controls">
                        <Block className="ph-drawer-skeleton-filter" />
                        <Block className="ph-drawer-skeleton-search" />
                    </div>
                </div>

                <div className="ph-drawer-table-wrap">
                    <table className="ph-drawer-table ph-drawer-skeleton-table">
                        <thead><tr>{['Name', 'Time', 'Venue', 'Day', 'Status'].map((heading) => <th key={heading}>{heading}</th>)}</tr></thead>
                        <tbody>
                            {[0, 1, 2, 3, 4].map((row) => (
                                <tr key={row}>{[0, 1, 2, 3, 4].map((cell) => <td key={cell}><Block /></td>)}</tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>
>>>>>>> backend
        </div>
    )
}
