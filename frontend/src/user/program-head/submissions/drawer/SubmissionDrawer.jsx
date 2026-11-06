import { useEffect, useMemo, useState } from 'react'
import { request } from '../../../../api/request'
import {
    TotalStudentsIcon,
    RespondedIcon,
    NoResponseIcon,
} from '../../../../components/Icons/AttendanceIcons'
import RecordApprovedIcon from '../../../../assets/icons/RecordApprovedIcon.svg'
import RecordCalendarIcon from '../../../../assets/icons/RecordCalendarIcon.svg'
import RecordClockIcon from '../../../../assets/icons/RecordClockIcon.svg'
import RecordProgramIcon from '../../../../assets/icons/RecordProgramIcon.svg'
import ExportIcon from '../../../../assets/icons/export-icon.svg'
import SubmissionDrawerSkeleton from './SubmissionDrawerSkeleton'
import UserAvatar from '../../../../components/identity/UserAvatar'
import RecordDay from '../../../../components/record-display/RecordDay'
import VenueLabel from '../../../../components/record-display/VenueLabel'
import SearchPill from '../../../../components/search/SearchPill'
import './SubmissionDrawer.css'

export default function SubmissionDrawer({ submission, onClose, onUpdated, onExport, detailOverride = null, readOnly = false, embedded = false }) {
    const [fetchedDetail, setFetchedDetail] = useState(null)
    const [loading, setLoading] = useState(!detailOverride)
    const [busy, setBusy] = useState(false)
    const [error, setError] = useState('')
    const [search, setSearch] = useState('')
    const [status, setStatus] = useState('all')
    const detail = detailOverride ?? fetchedDetail
    const isRecordView = readOnly || submission.status === 'approved'

    useEffect(() => {
        if (detailOverride) return undefined
        const controller = new AbortController()

        request(`/attendance/review/${encodeURIComponent(submission.id)}`, {
            signal: controller.signal,
        })
            .then((result) => setFetchedDetail(result.data))
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
    }, [submission.id, detailOverride])

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
                || (status === 'present' && student.status === 'present')
                || (status === 'excused' && student.status === 'excused')
                || (status === 'absent' && !['present', 'excused'].includes(student.status))

            return matchesYear && matchesSearch && matchesStatus
        })
    }, [detail, search, status, submission.year_level])

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

    const exportRecord = async () => {
        setBusy(true)
        setError('')
        try {
            await onExport(submission)
        } catch (err) {
            setError(err.message || 'Could not export the attendance record.')
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
                {isRecordView && (
                    <strong>{detail.gathering || submission.gathering}</strong>
                )}
                {isRecordView ? (
                    <span>{isApproved ? 'Approved by' : 'Recorded by'}: {displayedName}</span>
                ) : (
                    <strong>{displayedName}</strong>
                )}

                {isRecordView ? (
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

    const drawer = (
            <section
                className={`ph-submission-drawer ${isRecordView ? 'ph-record-detail' : ''} ${readOnly && submission.status !== 'approved' ? 'ph-record-detail--monitoring' : ''}`}
                role="dialog"
                aria-modal="true"
                aria-labelledby="ph-drawer-title"
            >
                <div className="ph-submission-drawer-scroll">
                    <header className="ph-drawer-header">
                        <div>
                            <h2 id="ph-drawer-title">
                                {isRecordView
                                    ? 'Record Details'
                                    : submission.status === 'pending'
                                    ? 'Review Submission'
                                    : 'Submission Details'}
                            </h2>
                            <p>
                                {[
                                    detail?.gathering || submission.gathering,
                                    detail?.week_range?.label || submission.week_range?.label || detail?.day || submission.date,
                                    isRecordView ? null : detail?.time,
                                ].filter(Boolean).join(' · ')}
                            </p>
                        </div>
                        <button type="button" className="ph-drawer-header-close" aria-label="Close details" onClick={onClose}>×</button>
                    </header>

                    {loading && <SubmissionDrawerSkeleton isRecordView={isRecordView} approved={submission.status === 'approved'} />}
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
                                {!isRecordView && (
                                    <span className="ph-drawer-submitted-label">Submitted by</span>
                                )}
                                {renderSubmittedBy()}
                                {detail.unsubmitted_at && detail.resubmitted_at && (
                                    <div className="ph-resubmission-history">
                                        {[
                                            ['Previously unsubmitted at', detail.unsubmitted_at],
                                            ['Resubmitted at', detail.resubmitted_at],
                                        ].map(([label, timestamp]) => (
                                            <div key={label}>
                                                <strong>{label}</strong>
                                                <time dateTime={timestamp}>
                                                    {new Date(timestamp).toLocaleString('en-US', {
                                                        timeZone: 'Asia/Manila', month: 'short', day: 'numeric', year: 'numeric',
                                                        hour: 'numeric', minute: '2-digit', hour12: true,
                                                    })}
                                                </time>
                                            </div>
                                        ))}
                                    </div>
                                )}
                            </div>

                            <section className={isRecordView ? 'ph-record-summary' : undefined}>
                                {isRecordView && <h3>Attendance Summary</h3>}
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
                                <h3>{isRecordView ? 'Student List' : 'Attendance List'}</h3>
                                <div className="ph-drawer-controls">
                                    <select
                                        aria-label="Filter students by attendance status"
                                        value={status}
                                        onChange={(event) => setStatus(event.target.value)}
                                    >
                                        <option value="all">All Status</option>
                                        <option value="present">Present</option>
                                        <option value="excused">Excused</option>
                                        <option value="absent">No response</option>
                                    </select>
                                    <SearchPill
                                        className="ph-drawer-search-pill"
                                        ariaLabel="Search students"
                                        placeholder="Search Student…"
                                        value={search}
                                        onChange={(event) => setSearch(event.target.value)}
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
                                        {students.length ? students.map((student, index) => (
                                            <tr key={student.record_id || student.student_id || index}>
                                                <td>{student.student_name}</td>
                                                <td>{student.batch || '—'}</td>
                                                <td><VenueLabel value={student.venue} /></td>
                                                <td><RecordDay value={student.day || detail.day} status={student.status} /></td>
                                                <td>
                                                    <span className={`ph-drawer-attendance-status ${['present', 'excused'].includes(student.status) ? 'present' : 'no-response'}`}>
                                                        <i />
                                                        {student.status === 'present' ? 'Present' : student.status === 'excused' ? 'Excused' : 'No response'}
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

                        </>
                    )}
                </div>

                <footer className="ph-drawer-footer">
                    {readOnly && !onExport && (
                        <button type="button" className="ph-drawer-close" onClick={onClose}>Close</button>
                    )}
                    {!readOnly && submission.status === 'pending' && (
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

                    {isRecordView && onExport && (
                        <button
                            type="button"
                            className="ph-record-detail-export"
                            disabled={loading || !detail || busy}
                            onClick={exportRecord}
                        >
                            <img src={ExportIcon} alt="" /> {busy ? 'Exporting…' : 'Export'}
                        </button>
                    )}
                </footer>
            </section>
    )

    if (embedded) return drawer

    return (
        <div
            className="ph-submission-drawer-backdrop"
            onMouseDown={(event) => event.target === event.currentTarget && onClose()}
        >
            {drawer}
        </div>
    )
}
