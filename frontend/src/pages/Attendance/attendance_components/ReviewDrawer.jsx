import { useEffect, useMemo, useState } from 'react'
import { request } from '../../../api/request'
import './ReviewDrawer.css'

const PAGE_SIZE = 10

function getInitials(name = '') {
    return name.trim().split(/\s+/).slice(0, 2).map((w) => w[0]?.toUpperCase()).join('')
}

export default function ReviewDrawer({ isOpen, onClose, record, onSubmitted }) {
    const [draft, setDraft] = useState(null)
    const [loading, setLoading] = useState(true)
    const [error, setError] = useState(null)
    const [submitting, setSubmitting] = useState(false)
    const [search, setSearch] = useState('')
    const [statusFilter, setStatusFilter] = useState('all')
    const [page, setPage] = useState(1)

    useEffect(() => {
        if (!isOpen || !record?.ref_id) return
        let cancelled = false
        setLoading(true)
        setError(null)

        request(`/attendance/drafts/${record.ref_id}`)
            .then((json) => { if (!cancelled) setDraft(json.data) })
            .catch((err) => { if (!cancelled) setError(err.message) })
            .finally(() => { if (!cancelled) setLoading(false) })

        return () => { cancelled = true }
    }, [isOpen, record])

    const filteredStudents = useMemo(() => {
        if (!draft) return []
        return draft.students.filter((s) => {
            const matchesSearch = s.student_name.toLowerCase().includes(search.toLowerCase())
            const matchesStatus =
                statusFilter === 'all' ||
                (statusFilter === 'present' && s.status === 'present') ||
                (statusFilter === 'absent' && s.status !== 'present')
            return matchesSearch && matchesStatus
        })
    }, [draft, search, statusFilter])

    const pageCount = Math.max(1, Math.ceil(filteredStudents.length / PAGE_SIZE))
    const pageStudents = filteredStudents.slice((page - 1) * PAGE_SIZE, page * PAGE_SIZE)

    const handleSubmit = async () => {
        if (!draft) return
        setSubmitting(true)
        setError(null)
        try {
            const records = draft.students.map((s) => ({
                student_id: s.student_id,
                gathering_type: record.gathering_type,
                day: s.day || draft.day_iso,
                status: s.status,
                batch: s.batch,
                venue: s.venue,
            }))

            await request('/attendance', {
                method: 'POST',
                body: JSON.stringify({ draft_id: draft.id, records }),
            })

            onSubmitted(record.id)
        } catch (err) {
            setError(err.message)
        } finally {
            setSubmitting(false)
        }
    }

    if (!isOpen) return null

    // Decide footer control based on the row's current lifecycle status
    const footer = (() => {
        switch (record.status) {
            case 'approved':
                return <div className="status-pill status-pill-approved">✓ Recorded</div>
            case 'rejected':
                return <div className="status-pill status-pill-rejected">✕ Rejected</div>
            case 'pending':
                return <div className="status-pill status-pill-pending">✓ Submitted</div>
            default:
                return (
                    <button type="button" className="drawer-submit-btn" onClick={handleSubmit} disabled={submitting}>
                        {submitting ? 'Submitting…' : '✓ Submit'}
                    </button>
                )
        }
    })()

    return (
        <div className="review-drawer-backdrop" onClick={onClose}>
            <div className="review-drawer-panel" onClick={(e) => e.stopPropagation()}>
                <div className="drawer-scroll">
                    <div className="drawer-header">
                        <h2>Review Submission</h2>
                        <p className="drawer-subtitle">
                            {record?.gathering || '—'} • {record?.date || '—'}
                        </p>
                    </div>

                    {loading && <p>Loading…</p>}
                    {error && <p style={{ color: '#e52328' }}>{error}</p>}

                    {!loading && draft && (
                        <>
                            <div className="submitted-card">
                                <span className="submitted-label">Submitted by</span>
                                <div className="submitter-info">
                                    <div className="avatar avatar-initials">
                                        {getInitials(draft.submitted_by?.name)}
                                    </div>
                                    <div>
                                        <h4 className="submitter-name">{draft.submitted_by?.name}</h4>
                                        <p className="submitter-role">{draft.submitted_by?.role}</p>
                                    </div>
                                </div>
                            </div>

                            <div className="kpi-grid">
                                <div className="kpi-card">
                                    <div className="kpi-icon kpi-blue">👥</div>
                                    <div className="kpi-value">{draft.total_students}</div>
                                    <div className="kpi-title">Total Students</div>
                                </div>
                                <div className="kpi-card">
                                    <div className="kpi-icon kpi-green">✔</div>
                                    <div className="kpi-value">{draft.responded_count}</div>
                                    <div className="kpi-title">Responded</div>
                                </div>
                                <div className="kpi-card">
                                    <div className="kpi-icon kpi-gray">➖</div>
                                    <div className="kpi-value">{draft.no_response_count}</div>
                                    <div className="kpi-title">No Response</div>
                                </div>
                            </div>

                            <div className="list-controls-header">
                                <h3>Attendance List</h3>
                                <div className="controls-group">
                                    <select
                                        className="drawer-filter-select"
                                        value={statusFilter}
                                        onChange={(e) => { setStatusFilter(e.target.value); setPage(1) }}
                                    >
                                        <option value="all">Status</option>
                                        <option value="present">Present</option>
                                        <option value="absent">No response</option>
                                    </select>
                                    <div className="drawer-search-wrapper">
                                        <span className="search-icon">🔍</span>
                                        <input
                                            type="text"
                                            placeholder="Search Student.."
                                            value={search}
                                            onChange={(e) => { setSearch(e.target.value); setPage(1) }}
                                        />
                                    </div>
                                </div>
                            </div>

                            <div className="drawer-table-container">
                                <table className="drawer-table">
                                    <thead>
                                        <tr>
                                            <th>Student</th>
                                            <th>Venue</th>
                                            <th>Batch</th>
                                            <th>Date</th>
                                            <th>Status</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {pageStudents.map((s, i) => (
                                            <tr key={s.student_id ?? i}>
                                                <td>{s.student_name}</td>
                                                <td>{s.venue || '—'}</td>
                                                <td>{s.batch || '—'}</td>
                                                <td>{s.day || '—'}</td>
                                                <td>
                                                    <span className={`status-indicator status-${s.status === 'present' ? 'present' : 'no-response'}`}>
                                                        <span className="dot" /> {s.status === 'present' ? 'Present' : 'No response'}
                                                    </span>
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>

                            <div className="drawer-pagination">
                                <span>Showing {page} of {pageCount}</span>
                                <div className="pagination-btns">
                                    <button disabled={page === 1} onClick={() => setPage((p) => p - 1)}>‹</button>
                                    <button className="active">{page}</button>
                                    <button disabled={page === pageCount} onClick={() => setPage((p) => p + 1)}>›</button>
                                </div>
                            </div>
                        </>
                    )}
                </div>

                <div className="drawer-footer">
                    {footer}
                </div>
            </div>
        </div>
    )
}