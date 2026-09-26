import SubmissionPagination from './SubmissionPagination'

export default function SubmissionsTable({ rows, loading, error, onOpen, firstItem, lastItem, total, page, lastPage, onPageChange }) {
    return (
        <section className="ph-submissions-table-panel" aria-label="Submissions list">
            <div className="ph-submissions-table-scroll">
                <table className="ph-submissions-table">
                    <thead><tr><th>Secretary</th><th>Gathering</th><th>Program</th><th>Year</th><th>Date</th><th>Status</th><th>Action</th></tr></thead>
                    <tbody>
                        {loading ? <tr><td className="ph-submissions-state" colSpan="7">Loading submissions…</td></tr>
                            : error ? <tr><td className="ph-submissions-state ph-submissions-error" colSpan="7">{error}</td></tr>
                                : rows.length === 0 ? <tr><td className="ph-submissions-state" colSpan="7">No submissions match these filters.</td></tr>
                                    : rows.map((row) => (
                                        <tr key={row.id}>
                                            <td><span className="ph-submitter-avatar">{row.secretary?.charAt(0).toUpperCase() || '?'}</span>{row.secretary}</td>
                                            <td>{row.gathering}</td><td>{row.program}</td><td>{row.year_label}</td><td>{row.date}</td>
                                            <td><span className={`ph-submission-status ${row.status}`}><i />{row.status_label}</span></td>
                                            <td><button type="button" className="ph-submission-review" onClick={() => onOpen(row)}>{row.status === 'pending' ? 'Review' : 'View'} <span>›</span></button></td>
                                        </tr>
                                    ))}
                    </tbody>
                </table>
            </div>
            <SubmissionPagination firstItem={firstItem} lastItem={lastItem} total={total} page={page} lastPage={lastPage} onPageChange={onPageChange} disabled={loading} />
        </section>
    )
}
