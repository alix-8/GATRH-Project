import { useEffect, useMemo, useState } from 'react'
import { request } from '../../../api/request'
import RecordDate from '../../../components/RecordDate'
import './ReviewDrawer.css'
import { headerTitle } from './header-title'
import { RespondedIcon, TotalStudentsIcon, NoResponseIcon } from '../../../components/Icons/AttendanceIcons'
import { FilterIcon, ChevronDownIcon } from '../../../components/Icons/Reusables'
import LoadingCircle from '../../../components/feedback/LoadingCircle'
import UserAvatar from '../../../components/identity/UserAvatar'
import SearchPill from '../../../components/search/SearchPill'
import ConfirmOverrideModal from '../import/modals/ConfirmOverrideModal'
import ErrorModal from '../import/modals/ErrorModal'
import AttendanceEditButton from './AttendanceEditButton'
import EditableAttendanceTable from './EditableAttendanceTable'
import {
    getAttendanceEditErrors,
    getAutomaticAttendanceStatus,
    hasAttendanceEditErrors,
    resolveAttendanceDay,
} from './attendanceEditValidation'

export default function ReviewDrawer({ isOpen, onClose, record, onSubmitted }) {
    const [draft, setDraft] = useState(null)
    const [loading, setLoading] = useState(true)
    const [error, setError] = useState(null)
    const [submitting, setSubmitting] = useState(false)
    const [unsubmitting, setUnsubmitting] = useState(false)
    const [unsubmitConfirm, setUnsubmitConfirm] = useState(false)
    const [duplicateConfirm, setDuplicateConfirm] = useState(null)
    const [editError, setEditError] = useState(null)
    const [removedInactiveStudentIds, setRemovedInactiveStudentIds] = useState([])
    const [search, setSearch] = useState('')
    const [editing, setEditing] = useState(false)
    //for status filter
    const [statusFilter, setStatusFilter] = useState('all')
    const [isStatusMenuOpen, setIsStatusMenuOpen] = useState(false)

    useEffect(() => {
        if (!isOpen || !record) return
        let cancelled = false
        setLoading(true)
        setError(null)
        setDraft(null)
        setDuplicateConfirm(null)
        setEditError(null)
        setRemovedInactiveStudentIds([])
        setEditing(false)
        setUnsubmitConfirm(false)
        setUnsubmitting(false)

        const url = record.source === 'draft'
        ? `/attendance/drafts/${record.ref_id}`
        : record.batch_id
            ? `/attendance/session?batch_id=${record.batch_id}`
            : `/attendance/session?gathering_type=${record.gathering_type}&day=${record.day_iso}`

        request(url)
            .then((json) => {
                if (cancelled) return
                setDraft(json.data)
                if (json.data?.duplicate?.status === 'duplicate_detected') {
                    setDuplicateConfirm(json.data.duplicate)
                }
            })
            .catch((err) => { if (!cancelled) setError(err.message) })
            .finally(() => { if (!cancelled) setLoading(false) })

        return () => { cancelled = true }
    }, [isOpen, record])

    const filteredStudents = useMemo(() => {
        if (!draft) return []
        return draft.students.filter((s) => {
            const matchesSearch = s.student_name.toLowerCase().includes(search.toLowerCase())
            const attendanceStatus = editing || record.source === 'draft'
                ? getAutomaticAttendanceStatus(s, draft.week_range, record.gathering_type)
                : s.status
            const matchesStatus =
                statusFilter === 'all' ||
                (statusFilter === 'present' && attendanceStatus === 'present') ||
                (statusFilter === 'absent' && attendanceStatus !== 'present')
            return matchesSearch && matchesStatus
        }).sort((a, b) => a.student_name.localeCompare(b.student_name, undefined, { sensitivity: 'base' }))
    }, [draft, search, statusFilter, editing, record])

    const handleStudentChange = (student, field, value) => {
        setDraft((current) => current ? {
            ...current,
            students: current.students.map((item) => item === student
                ? { ...item, [field]: value, ...(field === 'day' ? { day_provided: Boolean(value) } : {}) }
                : item),
        } : current)
    }

    const normalizeServerErrors = (details) => {
        if (details.format_errors || details.incomplete_records) return details
        const validationErrors = Object.entries(details.errors || {}).flatMap(([field, messages]) =>
            (Array.isArray(messages) ? messages : [messages]).map((reason) => ({
                raw_line: field,
                errors: [{ field, reason: String(reason) }],
            }))
        )
        return validationErrors.length ? { ...details, format_errors: validationErrors } : details
    }

    const handleRemoveInactiveStudents = (inactiveStudents) => {
        const ids = inactiveStudents.map((student) => Number(student.student_id)).filter(Number.isInteger)
        if (!ids.length) return
        const idSet = new Set(ids)
        setDraft((current) => current ? {
            ...current,
            students: current.students.filter((student) => !idSet.has(Number(student.student_id))),
        } : current)
        setRemovedInactiveStudentIds((current) => [...new Set([...current, ...ids])])
        setEditing(true)
        setEditError(null)
        setError(null)
    }

    const handleSubmit = async (opts = {}) => {
        if (!draft) return

        const editErrors = getAttendanceEditErrors(draft.students, draft.week_range, record.gathering_type)
        if (hasAttendanceEditErrors(editErrors)) {
            setEditing(true)
            setEditError({
                message: 'Fix the attendance format issues before submitting.',
                details: editErrors,
            })
            return
        }

        setSubmitting(true)
        setError(null)
        try {
            const records = draft.students.map((s) => ({
                student_id: s.student_id,
                gathering_type: record.gathering_type,
                day: s.day_provided === false ? draft.day_iso : (s.day || draft.day_iso),
                status: getAutomaticAttendanceStatus(s, draft.week_range, record.gathering_type),
                batch: s.batch === '---' ? '' : s.batch,
                venue: s.venue === '---' ? '' : s.venue,
            }))

            const isOverride = Boolean(opts.override)

            await request('/attendance/submit', {
                method: 'POST',
                body: JSON.stringify({
                    draft_id: draft.id,
                    records,
                    override: isOverride,
                    week_range: draft.week_range,
                }),
            })

            setDuplicateConfirm(null)
            onSubmitted(record.id)
        } catch (err) {
            const details = err.details || {}
            if (details.requires_confirmation || details.status === 'duplicate_detected') {
                setDuplicateConfirm({
                    ...details,
                    gathering_type: details.gathering_type || record.gathering_type,
                    week_range: details.week_range || { label: record.date || draft.day },
                })
            } else {
                setEditError({ message: err.message, details: normalizeServerErrors(details) })
            }
        } finally {
            setSubmitting(false)
        }
    }

    const handleDoneEditing = async () => {
        if (!draft) return
        const editErrors = getAttendanceEditErrors(draft.students, draft.week_range, record.gathering_type)
        if (hasAttendanceEditErrors(editErrors)) {
            setEditError({
                message: 'Fix the attendance format issues before leaving edit mode.',
                details: editErrors,
            })
            return
        }

        setSubmitting(true)
        setEditError(null)
        try {
            const response = await request(`/attendance/drafts/${draft.id}`, {
                method: 'PATCH',
                body: JSON.stringify({
                    remove_inactive_student_ids: removedInactiveStudentIds,
                    records: draft.students.map((student) => ({
                        student_id: student.student_id,
                        day: student.day_provided === false
                            ? null
                            : resolveAttendanceDay(student.day, draft.week_range, student.venue),
                        day_provided: Boolean(
                            student.day_provided !== false
                            && student.day
                            && student.day !== '---'
                            && resolveAttendanceDay(student.day, draft.week_range, student.venue)
                        ),
                        batch: student.batch === '---' ? '' : student.batch,
                        venue: student.venue === '---' ? '' : student.venue,
                    })),
                }),
            })
            setDraft((current) => current ? {
                ...current,
                students: response.data?.students || current.students,
                responded_count: response.data?.responded_count ?? current.responded_count,
                no_response_count: response.data?.no_response_count ?? current.no_response_count,
            } : current)
            setRemovedInactiveStudentIds([])
            setEditing(false)
        } catch (err) {
            setEditError({ message: err.message, details: normalizeServerErrors(err.details || {}) })
        } finally {
            setSubmitting(false)
        }
    }

    const handleUnsubmit = async () => {
        if (!record?.can_unsubmit || !record?.batch_id && !record?.submitted_by_id) return
        const batchKey = record.batch_id || `${record.gathering_type}|${record.day_iso}|${record.submitted_by_id}`
        setUnsubmitting(true)
        setError(null)
        try {
            await request(`/attendance/submissions/${encodeURIComponent(batchKey)}/unsubmit`, { method: 'POST' })
            setUnsubmitConfirm(false)
            onSubmitted(record.id)
        } catch (err) {
            setError(err.message)
            setUnsubmitConfirm(false)
        } finally {
            setUnsubmitting(false)
        }
    }

    if (!isOpen) return null

    // Decide footer control based on the row's current lifecycle status
    const approver = draft?.approved_by
    const approverRole = { program_head: 'Program Head', program_secretary: 'Program Secretary' }[approver?.role]
    const approvalDescription = approver?.name
        ? `Approved by ${approver.name}${approverRole ? ` (${approverRole})` : ''}. This attendance report has been approved and recorded.`
        : 'This attendance report has been approved and recorded.'
    const footer = (() => {
        switch (record.status) {
            case 'approved':
            case 'pending':
                return (
                    <div className="drawer-submission-footer">
                        <div className={`drawer-submission-notice ${record.status === 'approved' ? 'is-approved' : 'is-pending'}`}>
                            <span className="drawer-submission-notice-icon" aria-hidden="true">
                                <svg viewBox="0 0 24 24" fill="none">
                                    {record.status === 'approved' ? (
                                        <path d="m4 12 4 4 8-9m-5 7 3 3 7-9" />
                                    ) : (
                                        <><circle cx="12" cy="12" r="9" /><path d="M12 6v6l4 3" /></>
                                    )}
                                </svg>
                            </span>
                            <div>
                                <strong>{record.status === 'approved' ? 'Approved & Recorded' : 'Submitted for Review'}</strong>
                                <p>{record.status === 'approved' ? approvalDescription : 'This attendance report has been sent and is awaiting review.'}</p>
                            </div>
                        </div>
                        {record.can_unsubmit && (
                            <button type="button" className="drawer-withdraw-button" onClick={() => setUnsubmitConfirm(true)} disabled={unsubmitting}>
                                <svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M5 8a8 8 0 1 1 0 9M5 3v5h5" /></svg>
                                {unsubmitting ? 'Withdrawing…' : 'Withdraw Submission'}
                            </button>
                        )}
                    </div>
                )
            default:
                if (editing) {
                    return (
                        <button type="button" className="drawer-submit-btn" onClick={handleDoneEditing} disabled={submitting}>
                            {submitting ? 'Saving…' : 'Done'}
                        </button>
                    )
                }
                return (
                    <div className="drawer-footer-actions">
                        {record.source === 'draft' && <AttendanceEditButton editing={false} disabled={submitting} onClick={() => setEditing(true)} />}
                        <button type="button" className="drawer-submit-btn" onClick={handleSubmit} disabled={submitting}>
                            {submitting ? 'Submitting…' : '✓ Submit'}
                        </button>
                    </div>
                )
        }
    })()


    return (
        <div className="review-drawer-backdrop" onClick={onClose}>
            <div className="review-drawer-panel" role="dialog" aria-modal="true" aria-label="Attendance record details" onClick={(e) => e.stopPropagation()}>
                <div className="drawer-scroll">
                    <div className="drawer-header">
                        <h2>{headerTitle(record?.status)}</h2>
                        <button type="button" className="review-drawer-close" aria-label="Close attendance details" onClick={onClose}>×</button>
                        <p className="drawer-subtitle">
                            {record?.gathering || '—'} • {draft?.week_range?.label || record?.week_range?.label || <RecordDate value={record?.date} />}
                        </p>
                    </div>

                    {loading && <p><LoadingCircle label="Loading attendance details…" /></p>}
                    {error && <p style={{ color: '#e52328' }}>{error}</p>}

                    {!loading && draft && !duplicateConfirm && (
                        <>
                            <div className="submitted-card">
                                <span className="submitted-label">Submitted by</span>
                                <div className="submitter-info">
                                    <UserAvatar as="div" className="avatar avatar-initials" name={draft.submitted_by?.name} program={record.program} />
                                    <div>
                                        <h4 className="submitter-name">{draft.submitted_by?.name}</h4>
                                        <p className="submitter-role">{draft.submitted_by?.role}</p>
                                    </div>
                                </div>
                                {draft.unsubmitted_at && (
                                    <div className="submitted-card-unsubmitted">
                                        <span>Unsubmitted at</span>
                                        <time dateTime={draft.unsubmitted_at}>
                                            {new Date(draft.unsubmitted_at).toLocaleString('en-US', {
                                                timeZone: 'Asia/Manila',
                                                month: 'short', day: 'numeric', year: 'numeric',
                                                hour: 'numeric', minute: '2-digit', hour12: true,
                                            })}
                                        </time>
                                    </div>
                                )}
                            </div>

                            <div className="kpi-grid">
                                <div className="kpi-card">
                                    <div className="kpi-icon">
                                        <TotalStudentsIcon/>
                                    </div>
                                    <div className="kpi-value">{draft.total_students}</div>
                                    <div className="kpi-title">Total Students</div>
                                </div>

                                <div className="kpi-card">
                                    <div className="kpi-icon">
                                        <RespondedIcon/>
                                    </div>
                                    <div className="kpi-value">{draft.students.filter((student) => getAutomaticAttendanceStatus(student, draft.week_range, record.gathering_type) === 'present').length}</div>
                                    <div className="kpi-title">Responded</div>
                                </div>

                                <div className="kpi-card">
                                    <div className="kpi-icon">
                                        <NoResponseIcon/>
                                    </div>
                                    <div className="kpi-value">{draft.students.filter((student) => getAutomaticAttendanceStatus(student, draft.week_range, record.gathering_type) !== 'present').length}</div>
                                    <div className="kpi-title">No Response</div>
                                </div>
                            </div>

                            <div className="list-controls-header">
                                <h3>Attendance List</h3>
                                <div className="controls-group">
                                    <div className="drawer-status-dropdown">
                                        <button
                                            type="button"
                                            className="drawer-status-trigger"
                                            onClick={() => setIsStatusMenuOpen(prev => !prev)}
                                            aria-haspopup="listbox"
                                            aria-expanded={isStatusMenuOpen}
                                        >
                                            <FilterIcon />
                                            <span>
                                                {statusFilter === 'all'
                                                    ? 'Status'
                                                    : statusFilter === 'present'
                                                        ? 'Present'
                                                        : 'No response'}
                                            </span>
                                            <ChevronDownIcon />
                                        </button>

                                        {isStatusMenuOpen && (
                                            <div className="drawer-status-menu" role="listbox">
                                                {[
                                                    { value: 'all', label: 'Status' },
                                                    { value: 'present', label: 'Present' },
                                                    { value: 'absent', label: 'No response' },
                                                ].map(option => (
                                                    <button
                                                        type="button"
                                                        key={option.value}
                                                        className={`drawer-status-option ${
                                                            statusFilter === option.value ? 'selected' : ''
                                                        }`}
                                                        onClick={() => {
                                                            setStatusFilter(option.value)
                                                            setIsStatusMenuOpen(false)
                                                        }}
                                                    >
                                                        {option.label}
                                                    </button>
                                                ))}
                                            </div>
                                        )}
                                    </div>

                                    <SearchPill
                                        ariaLabel="Search students"
                                        placeholder="Search Student.."
                                        value={search}
                                        onChange={(e) => setSearch(e.target.value)}
                                    />
                                </div>
                            </div>

                            <div className="drawer-table-container">
                                <EditableAttendanceTable
                                    students={filteredStudents}
                                    day={draft.day_iso}
                                    weekRange={draft.week_range}
                                    gatheringType={record.gathering_type}
                                    editing={editing}
                                    onStudentChange={handleStudentChange}
                                    emptyMessage="No students match these filters."
                                />
                            </div>
                        </>
                    )}
                </div>

                <div className="drawer-footer">
                    {footer}
                </div>
            </div>

            {duplicateConfirm && (
                <ConfirmOverrideModal
                    duplicateData={duplicateConfirm}
                    isSubmitting={submitting}
                    onCancel={() => {
                        setDuplicateConfirm(null)
                        onClose()
                    }}
                    onOverride={() => handleSubmit({ override: true })}
                />
            )}

            {editError && (
                <ErrorModal
                    title="Attendance Needs Fixes"
                    description="Correct the highlighted format issues in the attendance table, then click Done to save again."
                    overlayClassName="attendance-edit-error-overlay"
                    errorMessage={editError.message}
                    errorDetails={editError.details}
                    onRemoveInactiveStudents={handleRemoveInactiveStudents}
                    showRetryButton={false}
                    closeLabel="Back to editing"
                    onClose={() => {
                        setEditError(null)
                        setError(null)
                        setEditing(true)
                    }}
                />
            )}

            {unsubmitConfirm && (
                <div className="unsubmit-confirm-overlay">
                    <section className="unsubmit-confirm-dialog" role="dialog" aria-modal="true" aria-labelledby="unsubmit-confirm-title">
                        <h2 id="unsubmit-confirm-title">Return attendance to Validating?</h2>
                        <p>This submission will become an editable draft again. Any approval will be removed. You can edit it and submit it for approval again.</p>
                        <div className="unsubmit-confirm-actions">
                            <button type="button" className="unsubmit-cancel-btn" onClick={() => setUnsubmitConfirm(false)} disabled={unsubmitting}>Cancel</button>
                            <button type="button" className="unsubmit-submit-btn" onClick={handleUnsubmit} disabled={unsubmitting}>
                                {unsubmitting ? 'Returning…' : 'Unsubmit'}
                            </button>
                        </div>
                    </section>
                </div>
            )}
        </div>
    )
}
