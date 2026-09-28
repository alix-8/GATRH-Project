import { useEffect, useMemo, useState } from 'react'
import { request } from '../../../../api/request'
import { TotalStudentsIcon, RespondedIcon, NoResponseIcon } from '../../../../components/Icons/AttendanceIcons'
import { SearchIcon } from '../../../../components/Icons/Reusables'
import RecordApprovedIcon from '../../../../components/Icons/RecordApprovedIcon.svg'
import RecordCalendarIcon from '../../../../components/Icons/RecordCalendarIcon.svg'
import RecordClockIcon from '../../../../components/Icons/RecordClockIcon.svg'
import RecordProgramIcon from '../../../../components/Icons/RecordProgramIcon.svg'
import LoadingCircle from '../../../../components/Loading/LoadingCircle'
import './SubmissionDrawer.css'

const PAGE_SIZE = 10
const initials = (name = '') => name.trim().split(/\s+/).slice(0, 2).map((part) => part[0]?.toUpperCase()).join('')

export default function SubmissionDrawer({ submission, onClose, onUpdated, onExport }) {
    const [detail, setDetail] = useState(null)
    const [loading, setLoading] = useState(true)
    const [busy, setBusy] = useState(false)
    const [reason, setReason] = useState('')
    const [rejectDialogOpen, setRejectDialogOpen] = useState(false)
    const [error, setError] = useState('')
    const [search, setSearch] = useState('')
    const [status, setStatus] = useState('all')
    const [page, setPage] = useState(1)

    useEffect(() => {
        const controller = new AbortController()
        setLoading(true)
        setError('')
        request(`/attendance/review/${encodeURIComponent(submission.id)}`, { signal: controller.signal })
            .then((result) => setDetail(result.data))
            .catch((err) => { if (err.name !== 'AbortError') setError(err.message) })
            .finally(() => { if (!controller.signal.aborted) setLoading(false) })
        return () => controller.abort()
    }, [submission.id])

    useEffect(() => {
        const onKeyDown = (event) => {
            if (event.key === 'Escape' && rejectDialogOpen) setRejectDialogOpen(false)
            else if (event.key === 'Escape') onClose()
        }
        window.addEventListener('keydown', onKeyDown)
        return () => window.removeEventListener('keydown', onKeyDown)
    }, [onClose, rejectDialogOpen])

    const students = useMemo(() => {
        const list = detail?.students || []
        return list.filter((student) => {
            const matchesYear = !submission.year_level || Number(student.year_level) === Number(submission.year_level)
            const matchesSearch = student.student_name?.toLowerCase().includes(search.toLowerCase())
            const matchesStatus = status === 'all' || (status === 'present' ? student.status === 'present' : student.status !== 'present')
            return matchesYear && matchesSearch && matchesStatus
        })
    }, [detail, search, status])
    const pageCount = Math.max(1, Math.ceil(students.length / PAGE_SIZE))
    const pageStudents = students.slice((page - 1) * PAGE_SIZE, page * PAGE_SIZE)

    const submitDecision = async (decision) => {
        setBusy(true)
        setError('')
        try {
            await request(`/attendance/review/${encodeURIComponent(submission.id)}/${decision}`, {
                method: 'PATCH',
                ...(decision === 'reject' ? { body: JSON.stringify({ reason: reason.trim() }) } : {}),
            })
            onUpdated()
        } catch (err) {
            setError(err.message)
        } finally {
            setBusy(false)
        }
    }

    return (
        <div className="ph-submission-drawer-backdrop" onMouseDown={(event) => event.target === event.currentTarget && onClose()}>
            <section className={`ph-submission-drawer ${submission.status === 'approved' ? 'ph-record-detail' : ''}`} role="dialog" aria-modal="true" aria-labelledby="ph-drawer-title">
                <div className="ph-submission-drawer-scroll">
                    <header className="ph-drawer-header">
                        <div><h2 id="ph-drawer-title">{submission.status === 'pending' ? 'Review Submission' : submission.status === 'approved' ? 'Record Details' : 'Submission Details'}</h2>
                            <p>{submission.status === 'approved' ? 'View finalized attendance record details' : `${detail?.gathering || submission.gathering} · ${detail?.day || submission.date}`}</p></div>
                    </header>

                    {loading && <p className="ph-drawer-state"><LoadingCircle label="Loading submission…" /></p>}
                    {error && <p className="ph-drawer-error" role="alert">{error}</p>}
                    {!loading && detail && <>
                        <div className="ph-drawer-submitted-card">
                            {submission.status === 'approved' && <div className="ph-record-approved-banner"><img src={RecordApprovedIcon} alt="" /> Sent to Chancellor &amp; Coordinator</div>}
                            <div className="ph-drawer-submitter"><div>{initials(detail.submitted_by?.name || submission.secretary)}</div><section>
                                {submission.status === 'approved' && <strong>{detail.gathering || submission.gathering}</strong>}
                                <span>passed by: {detail.submitted_by?.name || submission.secretary}</span>
                                {submission.status === 'approved' ? <>
                                    <small className="ph-record-date-time">
                                        <img src={RecordCalendarIcon} alt="" />
                                        <span>{detail.day || submission.date}</span>
                                        <img className="ph-record-clock-icon" src={RecordClockIcon} alt="" />
                                        <span className="ph-record-time-value">{detail.time || detail.students?.find((student) => student.batch && student.batch !== '---')?.batch || '—'}</span>
                                    </small>
                                    <small><img className="ph-record-program-icon" src={RecordProgramIcon} alt="" />{submission.program} · {submission.year_label}</small>
                                </> : <small>{detail.submitted_by?.role || 'Secretary'}</small>}
                            </section></div>
                        </div>

                        <section className={submission.status === 'approved' ? 'ph-record-summary' : undefined}>
                        {submission.status === 'approved' && <h3>Attendance Summary</h3>}
                        <div className="ph-drawer-kpis">
                            <article><i><TotalStudentsIcon /></i><strong>{detail.total_students ?? detail.students?.length ?? 0}</strong><span>Total Students</span></article>
                            <article><i><RespondedIcon /></i><strong>{detail.responded_count ?? 0}</strong><span>Responded</span></article>
                            <article><i><NoResponseIcon /></i><strong>{detail.no_response_count ?? Math.max(0, (detail.total_students || 0) - (detail.responded_count || 0))}</strong><span>No Response</span></article>
                        </div>
                        </section>

                        <div className="ph-drawer-list-heading">
                            <h3>{submission.status === 'approved' ? 'Student List' : 'Attendance List'}</h3>
                            <div className="ph-drawer-controls">
                                <select aria-label="Filter students by attendance status" value={status} onChange={(event) => { setStatus(event.target.value); setPage(1) }}>
                                    <option value="all">All Status</option><option value="present">Present</option><option value="absent">No response</option>
                                </select>
                                <label><SearchIcon /><input placeholder="Search Student…" value={search} onChange={(event) => { setSearch(event.target.value); setPage(1) }} /></label>
                            </div>
                        </div>

                        <div className="ph-drawer-table-wrap"><table className="ph-drawer-table"><thead><tr><th>Name</th><th>Time</th><th>Date</th><th>Location</th><th>Status</th></tr></thead>
                            <tbody>{pageStudents.length ? pageStudents.map((student, index) => <tr key={student.record_id || student.student_id || index}>
                                <td>{student.student_name}</td><td>{student.batch || '—'}</td><td>{student.day || detail.day || '—'}</td><td>{student.venue || '—'}</td>
                                <td><span className={`ph-drawer-attendance-status ${student.status === 'present' ? 'present' : 'no-response'}`}><i />{student.status === 'present' ? 'Present' : 'No response'}</span></td>
                            </tr>) : <tr><td colSpan="5" className="ph-drawer-empty">No students match these filters.</td></tr>}</tbody>
                        </table></div>
                        <div className="ph-drawer-pagination"><span>Showing {students.length ? (page - 1) * PAGE_SIZE + 1 : 0} to {Math.min(page * PAGE_SIZE, students.length)} of {students.length}</span><div>
                            <button disabled={page <= 1} aria-label="Previous students" onClick={() => setPage((value) => Math.max(1, value - 1))}>‹</button><b>{page} / {pageCount}</b><button disabled={page >= pageCount} aria-label="Next students" onClick={() => setPage((value) => Math.min(pageCount, value + 1))}>›</button>
                        </div></div>
                        {onExport && <button type="button" className="ph-record-detail-export" onClick={() => onExport(submission)}><span aria-hidden="true">↓</span> Export</button>}
                        {submission.status === 'rejected' && <section className="ph-drawer-note"><h3>Program Head Note</h3><p>{detail.rejection_reason || 'No reason was provided for this rejected submission.'}</p></section>}
                    </>}
                </div>
                {submission.status === 'pending' && <footer className="ph-drawer-footer">
                        <button type="button" className="ph-drawer-reject" disabled={loading || busy} onClick={() => setRejectDialogOpen(true)}><span aria-hidden="true">×</span> Reject</button>
                        <button type="button" className="ph-drawer-approve" disabled={loading || busy} onClick={() => submitDecision('approve')}><span aria-hidden="true">✓</span> {busy ? 'Saving…' : 'Approve'}</button>
                </footer>}
            </section>
            {rejectDialogOpen && <div className="ph-rejection-modal-backdrop" onMouseDown={(event) => event.target === event.currentTarget && setRejectDialogOpen(false)}>
                <section className="ph-rejection-modal" role="dialog" aria-modal="true" aria-labelledby="ph-rejection-modal-title">
                    <h2 id="ph-rejection-modal-title">Note for rejection</h2>
                    <textarea autoFocus value={reason} onChange={(event) => setReason(event.target.value)} maxLength={50} placeholder="leave a message ..." aria-label="Rejection note" />
                    <small className="ph-rejection-character-count">{reason.length}/50</small>
                    {error && <p role="alert">{error}</p>}
                    <div><button type="button" className="ph-rejection-cancel" disabled={busy} onClick={() => setRejectDialogOpen(false)}>Cancel</button>
                        <button type="button" className="ph-rejection-confirm" disabled={busy || !reason.trim()} onClick={() => submitDecision('reject')}>{busy ? 'Rejecting…' : 'Reject'}</button></div>
                </section>
            </div>}
        </div>
    )
}
