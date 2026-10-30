import { useEffect, useMemo, useState } from 'react'
import { request } from '../../../api/request'
import {
    TotalStudentsIcon,
    RespondedIcon,
    NoResponseIcon,
} from '../../../components/Icons/AttendanceIcons'
import RecordApprovedIcon from '../../../assets/icons/RecordApprovedIcon.svg'
import RecordCalendarIcon from '../../../assets/icons/RecordCalendarIcon.svg'
import RecordClockIcon from '../../../assets/icons/RecordClockIcon.svg'
import RecordProgramIcon from '../../../assets/icons/RecordProgramIcon.svg'
import LoadingCircle from '../../../components/feedback/LoadingCircle'
import UserAvatar from '../../../components/identity/UserAvatar'
import RecordDay from '../../../components/RecordDay'
import VenueLabel from '../../../components/VenueLabel'
import SearchPill from '../../../components/search/SearchPill'
import './SubmissionDrawer.css'

const PAGE_SIZE = 10

export default function SubmissionDrawer({ submission, onClose, onUpdated, onExport }) {
    const [detail, setDetail] = useState(null)
    const [loading, setLoading] = useState(true)
    const [busy, setBusy] = useState(false)
    const [error, setError] = useState('')
    const [search, setSearch] = useState('')
    const [status, setStatus] = useState('all')
    const [page, setPage] = useState(1)

    useEffect(() => {
        const controller = new AbortController()

        setLoading(true)
        setError('')

        request(`/attendance/review/${encodeURIComponent(submission.id)}`, {
            signal: controller.signal,
        })
            .then((result) => setDetail(result.data))
            .catch((err) => {
                if (err.name !== 'AbortError') {
                    setError(err.message)
                }
            })
            .finally(() => {
                if (!controller.signal.aborted) {
                    setLoading(false)
                }
            })

        return () => controller.abort()
    }, [submission.id])

    useEffect(() => {
        const onKeyDown = (event) => {
            if (event.key === 'Escape') onClose()
        }

        window.addEventListener('keydown', onKeyDown)
        return () => window.removeEventListener('keydown', onKeyDown)
    }, [onClose])

    const students = useMemo(() => {
        const list = detail?.students || []

        return list.filter((student) => {
            const matchesYear = !submission.year_level
                || Number(student.year_level) === Number(submission.year_level)
            const studentName = String(student.student_name ?? '').toLowerCase()
            const matchesSearch = studentName.includes(String(search).toLowerCase())
            const matchesStatus = status === 'all'
                || (status === 'present'
                    ? student.status === 'present'
                    : student.status !== 'present')

            return matchesYear && matchesSearch && matchesStatus
        })
    }, [detail, search, status, submission.year_level])

    const pageCount = Math.max(1, Math.ceil(students.length / PAGE_SIZE))
    const pageStudents = students.slice((page - 1) * PAGE_SIZE, page * PAGE_SIZE)
    const firstVisibleStudent = students.length ? (page - 1) * PAGE_SIZE + 1 : 0
    const lastVisibleStudent = Math.min(page * PAGE_SIZE, students.length)
    const noResponseCount = detail?.no_response_count
        ?? Math.max(0, (detail?.total_students || 0) - (detail?.responded_count || 0))

    const submitApproval = async () => {
        setBusy(true)
        setError('')

        try {
            await request(`/attendance/review/${encodeURIComponent(submission.id)}/approve`, {
                method: 'PATCH',
            })
            onUpdated()
        } catch (err) {
            setError(err.message)
        } finally {
            setBusy(false)
        }
    }

    const renderSubmittedBy = () => {
        const isApproved = submission.status === 'approved'
        const displayedUser = isApproved ? detail.approved_by : detail.submitted_by
        const displayedName = displayedUser?.name || (isApproved ? 'Unknown' : submission.secretary)

        return (
        <div className="ph-drawer-submitter">
            <UserAvatar
                as="div"
                className="ph-drawer-user-avatar"
                name={displayedName}
                program={isApproved ? displayedUser?.program : submission.program}
            />
            <section>
                {isApproved && (
                    <strong>{detail.gathering || submission.gathering}</strong>
                )}
                {isApproved ? (
                    <span>Approved by: {displayedName}</span>
                ) : (
                    <strong>{displayedName}</strong>
                )}

                {isApproved ? (
                    <>
                        <small className="ph-record-date-time">
                            <img src={RecordCalendarIcon} alt="" />
                            <span>{detail.submission_date || submission.submission_date || '—'}</span>
                            <img className="ph-record-clock-icon" src={RecordClockIcon} alt="" />
                            <span className="ph-record-time-value">
                                {detail.time
                                    || detail.students?.find((student) => student.batch && student.batch !== '---')?.batch
                                    || '—'}
                            </span>
                        </small>
                        <small>
                            <img className="ph-record-program-icon" src={RecordProgramIcon} alt="" />
                            {submission.program} · {submission.year_label}
                        </small>
                    </>
                ) : (
                    <small>
                        {[detail.submitted_by?.role, 'Spiritual Secretary'].filter(Boolean).join(' · ')}
                    </small>
                )}
            </section>
        </div>
        )
    }

    return (
        <div
            className="ph-submission-drawer-backdrop"
            onMouseDown={(event) => event.target === event.currentTarget && onClose()}
        >
            <section
                className={`ph-submission-drawer ${submission.status === 'approved' ? 'ph-record-detail' : ''}`}
                role="dialog"
                aria-modal="true"
                aria-labelledby="ph-drawer-title"
            >
                <div className="ph-submission-drawer-scroll">
                    <header className="ph-drawer-header">
                        <div>
                            <h2 id="ph-drawer-title">
                                {submission.status === 'pending'
                                    ? 'Review Submission'
                                    : submission.status === 'approved'
                                        ? 'Record Details'
                                        : 'Submission Details'}
                            </h2>
                            <p>
                                {[
                                    detail?.gathering || submission.gathering,
                                    detail?.week_range?.label || submission.week_range?.label || detail?.day || submission.date,
                                    submission.status === 'approved' ? null : detail?.time,
                                ].filter(Boolean).join(' · ')}
                            </p>
                        </div>
                    </header>

                    {loading && (
                        <p className="ph-drawer-state">
                            <LoadingCircle label="Loading submission…" />
                        </p>
                    )}
                    {error && <p className="ph-drawer-error" role="alert">{error}</p>}

                    {!loading && detail && (
                        <>
                            <div className="ph-drawer-submitted-card">
                                {submission.status === 'approved' && (
                                    <div className="ph-record-approved-banner">
                                        <img src={RecordApprovedIcon} alt="" />
                                        Sent to Chancellor &amp; Coordinator
                                    </div>
                                )}
                                {submission.status !== 'approved' && (
                                    <span className="ph-drawer-submitted-label">Submitted by</span>
                                )}
                                {renderSubmittedBy()}
                            </div>

                            <section className={submission.status === 'approved' ? 'ph-record-summary' : undefined}>
                                {submission.status === 'approved' && <h3>Attendance Summary</h3>}
                                <div className="ph-drawer-kpis">
                                    <article>
                                        <i><TotalStudentsIcon /></i>
                                        <strong>{detail.total_students ?? detail.students?.length ?? 0}</strong>
                                        <span>Total Students</span>
                                    </article>
                                    <article>
                                        <i><RespondedIcon /></i>
                                        <strong>{detail.responded_count ?? 0}</strong>
                                        <span>Responded</span>
                                    </article>
                                    <article>
                                        <i><NoResponseIcon /></i>
                                        <strong>{noResponseCount}</strong>
                                        <span>No Response</span>
                                    </article>
                                </div>
                            </section>

                            <div className="ph-drawer-list-heading">
                                <h3>{submission.status === 'approved' ? 'Student List' : 'Attendance List'}</h3>
                                <div className="ph-drawer-controls">
                                    <select
                                        aria-label="Filter students by attendance status"
                                        value={status}
                                        onChange={(event) => {
                                            setStatus(event.target.value)
                                            setPage(1)
                                        }}
                                    >
                                        <option value="all">All Status</option>
                                        <option value="present">Present</option>
                                        <option value="absent">No response</option>
                                    </select>
                                    <SearchPill
                                        className="ph-drawer-search-pill"
                                        ariaLabel="Search students"
                                        placeholder="Search Student…"
                                        value={search}
                                        onChange={(event) => {
                                            setSearch(event.target.value)
                                            setPage(1)
                                        }}
                                    />
                                </div>
                            </div>

                            <div className="ph-drawer-table-wrap">
                                <table className="ph-drawer-table">
                                    <thead>
                                        <tr>
                                            <th>Name</th>
                                            <th>Time</th>
                                            <th>Venue</th>
                                            <th>Day</th>
                                            <th>Status</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {pageStudents.length ? pageStudents.map((student, index) => (
                                            <tr key={student.record_id || student.student_id || index}>
                                                <td>{student.student_name}</td>
                                                <td>{student.batch || '—'}</td>
                                                <td><VenueLabel value={student.venue} /></td>
                                                <td><RecordDay value={student.day || detail.day} status={student.status} /></td>
                                                <td>
                                                    <span className={`ph-drawer-attendance-status ${student.status === 'present' ? 'present' : 'no-response'}`}>
                                                        <i />
                                                        {student.status === 'present' ? 'Present' : 'No response'}
                                                    </span>
                                                </td>
                                            </tr>
                                        )) : (
                                            <tr>
                                                <td colSpan="5" className="ph-drawer-empty">
                                                    No students match these filters.
                                                </td>
                                            </tr>
                                        )}
                                    </tbody>
                                </table>
                            </div>

                            <div className="ph-drawer-pagination">
                                <span>
                                    Showing {firstVisibleStudent} to {lastVisibleStudent} of {students.length}
                                </span>
                                <div>
                                    <button
                                        type="button"
                                        disabled={page <= 1}
                                        aria-label="Previous students"
                                        onClick={() => setPage((value) => Math.max(1, value - 1))}
                                    >
                                        ‹
                                    </button>
                                    <b>{page} / {pageCount}</b>
                                    <button
                                        type="button"
                                        disabled={page >= pageCount}
                                        aria-label="Next students"
                                        onClick={() => setPage((value) => Math.min(pageCount, value + 1))}
                                    >
                                        ›
                                    </button>
                                </div>
                            </div>
                        </>
                    )}
                </div>

                <footer className="ph-drawer-footer">
                    {submission.status === 'pending' && (
                        <>
                            <button
                                type="button"
                                className="ph-drawer-close"
                                disabled={busy}
                                onClick={onClose}
                            >
                                Close
                            </button>
                            <button
                                type="button"
                                className="ph-drawer-approve"
                                disabled={loading || busy}
                                onClick={submitApproval}
                            >
                                <span aria-hidden="true">✓</span>
                                {busy ? 'Saving…' : 'Approve'}
                            </button>
                        </>
                    )}

                    {submission.status === 'approved' && onExport && (
                        <button
                            type="button"
                            className="ph-record-detail-export"
                            onClick={() => onExport(submission)}
                        >
                            <span aria-hidden="true">↓</span> Export
                        </button>
                    )}
                </footer>
            </section>
        </div>
    )
}
