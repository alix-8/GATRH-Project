import { useEffect, useState, useCallback } from 'react'
import { useNavigate } from 'react-router-dom'
import PlusIcon from '../../../assets/plus-icon.png'
import GatheringIcon from '../../../assets/gathering-icon.png'
import CalendarIcon from '../../../assets/calendar-icon.png'
import SortbyIcon from '../../../assets/sort-by-icon.png'
import { request } from '../../../api/request'
import { TrashIcon } from '../../../components/Icons/Reusables'
import './AttendanceTable.css'
import ReviewDrawer from './ReviewDrawer'
import AttendancePagination from './AttendancePagination'

const STATUS_META = {
    validating: { label: 'Validating', dot: '#8c96a4' },
    pending:    { label: 'Pending',    dot: '#F2994A' },
    rejected:   { label: 'Rejected',   dot: '#E52328' },
    approved:   { label: 'Approved',   dot: '#27AE60' },
}

function AttendanceTable() {
    const [records, setRecords] = useState([])
    const [meta, setMeta] = useState({ current_page: 1, last_page: 1 })
    const [page, setPage] = useState(1)
    const [gathering, setGathering] = useState('')
    const [date, setDate] = useState('')
    const [sortBy, setSortBy] = useState('day')
    const [loading, setLoading] = useState(true)
    const [error, setError] = useState(null)
    const [refreshTrigger, setRefreshTrigger] = useState(0)

    const [drawerOpen, setDrawerOpen] = useState(false)
    const [activeRecord, setActiveRecord] = useState(null)

    const [deleteMode, setDeleteMode] = useState(false)
    const [selectedIds, setSelectedIds] = useState([])
    const [confirmOpen, setConfirmOpen] = useState(false)
    const [deleting, setDeleting] = useState(false)
    const navigate = useNavigate()

    const handleGatheringChange = (e) => {
        setGathering(e.target.value)
        setPage(1)
    }

    const handleDateChange = (e) => {
        setDate(e.target.value)
        setPage(1)
    }

    const handleSortChange = (e) => {
        setSortBy(e.target.value)
        setPage(1)
    }

    const fetchRecords = useCallback(async (signal) => {
        setLoading(true)
        setError(null)
        try {
            const params = new URLSearchParams()
            if (gathering) params.set('gathering_type', gathering)
            if (date) params.set('date', date)
            if (sortBy) params.set('sort_by', sortBy)
            params.set('page', page)

            const json = await request(`/attendance?${params.toString()}`, { signal })

            setRecords(json.data)
            setMeta(json.meta)
        } catch (err) {
            if (err.name !== 'AbortError') setError(err.message)
        } finally {
            setLoading(false)
        }
    }, [gathering, date, sortBy, page])

    useEffect(() => {
        const controller = new AbortController()
        fetchRecords(controller.signal)
        return () => controller.abort()
    }, [fetchRecords, refreshTrigger])

    const handleActionClick = (record) => {
        setActiveRecord(record)
        setDrawerOpen(true)
    }

    const goToPage = (newPage) => {
        if (newPage < 1 || newPage > meta.last_page) return
        setPage(newPage)
    }

    const deletableRecords = records.filter((r) => r.source === 'draft')
    const allSelected = deletableRecords.length > 0 && deletableRecords.every((r) => selectedIds.includes(r.ref_id))

    const toggleDeleteMode = () => {
        if (deleteMode) {
            if (selectedIds.length > 0) {
                setConfirmOpen(true)
            } else {
                setDeleteMode(false)
            }
        } else {
            setDeleteMode(true)
            setSelectedIds([])
        }
    }

    const toggleSelectAll = () => {
        if (allSelected) {
            setSelectedIds([])
        } else {
            setSelectedIds(deletableRecords.map((r) => r.ref_id))
        }
    }

    const toggleSelectRow = (id) => {
        setSelectedIds((prev) =>
            prev.includes(id) ? prev.filter((x) => x !== id) : [...prev, id]
        )
    }

    const confirmDelete = async () => {
        setDeleting(true)
        try {
            await request('/attendance/drafts', {
                method: 'DELETE',
                body: JSON.stringify({ ids: selectedIds }),
            })
            setSelectedIds([])
            setDeleteMode(false)
            setConfirmOpen(false)
            setRefreshTrigger((prev) => prev + 1)
        } catch (err) {
            setError(err.message)
            setConfirmOpen(false)
        } finally {
            setDeleting(false)
        }
    }

    return (
        <section className="attendance-table-section">
            <div className="attendance-toolbar">
                <div className="select-wrapper">
                    <img src={GatheringIcon} alt="" className="select-img-left" />
                    <select className="toolbar-select toolbar-select-primary" value={gathering} onChange={handleGatheringChange}>
                        <option value="">Gathering</option>
                        <option value="pm">Prayer Meeting</option>
                        <option value="ws">Worship Service</option>
                        <option value="tg">Thanksgiving</option>
                        <option value="special">Special</option>
                    </select>
                </div>

                <div className='select-wrapper'>
                    <img src={CalendarIcon} alt="" className="select-img-left" />
                    <input 
                        type={date ? "date" : "text"} 
                        placeholder="Date"
                        className="toolbar-select toolbar-date-input" 
                        value={date} 
                        onChange={handleDateChange}
                        onFocus={(e) => (e.target.type = "date")}
                        onBlur={(e) => { if (!e.target.value) e.target.type = "text"; }}
                    />
                </div>

                <div className='select-wrapper'>
                    <img src={SortbyIcon} alt="" className="select-img-left" />
                    <select className="toolbar-select" value={sortBy} onChange={handleSortChange}>
                        <option value="day">Sort by Date</option>
                        <option value="status">Sort by Status</option>
                        <option value="gathering_type">Sort by Gathering</option>
                    </select>
                </div>

                <div className="toolbar-spacer" />

                {deleteMode && (
                    <button type="button" className="toolbar-text-btn" onClick={() => { setDeleteMode(false); setSelectedIds([]) }}>
                        Cancel
                    </button>
                )}

                <button
                    type="button"
                    className={`toolbar-icon-btn trash-btn ${deleteMode ? 'toolbar-icon-btn-active' : ''}`}
                    aria-label="Delete selected"
                    onClick={toggleDeleteMode}
                >
                    <svg className={`trash-icon ${deleteMode ? 'trash-icon-open' : ''}`} viewBox="0 0 24 24" width="18" height="18" fill="none">
                        <g className="trash-lid">
                            <line x1="4" y1="7" x2="20" y2="7" stroke="currentColor" strokeWidth="2" strokeLinecap="round" />
                            <path d="M9 7V4.5C9 3.67 9.67 3 10.5 3h3C14.33 3 15 3.67 15 4.5V7" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" />
                        </g>
                        <path d="M6 7l1 13.5c0 .83.67 1.5 1.5 1.5h7c.83 0 1.5-.67 1.5-1.5L18 7" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" />
                        <line x1="10" y1="11" x2="10" y2="17" stroke="currentColor" strokeWidth="1.5" strokeLinecap="round" />
                        <line x1="14" y1="11" x2="14" y2="17" stroke="currentColor" strokeWidth="1.5" strokeLinecap="round" />
                    </svg>
                </button>
                <button type="button" className="toolbar-icon-btn" aria-label="Add record" onClick={() => navigate('/import')}>
                    <img src={PlusIcon} alt="Add" />
                </button>
            </div>

            <div className="attendance-table-wrapper">
                <table className="attendance-table">
                    <thead>
                        <tr>
                            {deleteMode && (
                                <th className="checkbox-col">
                                    <input type="checkbox" checked={allSelected} onChange={toggleSelectAll} />
                                </th>
                            )}
                            <th>Course</th>
                            <th>Year</th>
                            <th>Date</th>
                            <th>Gathering</th>
                            <th>Time</th>
                            <th>Status</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        {loading && (
                            <tr><td colSpan={deleteMode ? 8 : 7} className="table-state">Loading…</td></tr>
                        )}
                        {!loading && error && (
                            <tr><td colSpan={deleteMode ? 8 : 7} className="table-state table-state-error">{error}</td></tr>
                        )}
                        {!loading && !error && records.length === 0 && (
                            <tr><td colSpan={deleteMode ? 8 : 7} className="table-state">No records found.</td></tr>
                        )}
                        {!loading && !error && records.map((record) => {
                            const statusMeta = STATUS_META[record.status] ?? STATUS_META.pending
                            const isDeletable = record.source === 'draft'
                            // Composite unique key using source + ID to avoid React key collision
                            const rowKey = `${record.source || 'rec'}-${record.ref_id || record.id}`

                            return (
                                <tr key={rowKey} className={`row-${record.status}`}>
                                    {deleteMode && (
                                        <td className="checkbox-col">
                                            {isDeletable && (
                                                <input
                                                    type="checkbox"
                                                    checked={selectedIds.includes(record.ref_id)}
                                                    onChange={() => toggleSelectRow(record.ref_id)}
                                                />
                                            )}
                                        </td>
                                    )}
                                    <td>{record.course}</td>
                                    <td>{record.year}</td>
                                    <td>{record.date}</td>
                                    <td>{record.gathering}</td>
                                    <td>{record.time}</td>
                                    <td>
                                        <span className="status-cell">
                                            <span className="status-dot" style={{ background: statusMeta.dot }} />
                                            {statusMeta.label}
                                        </span>
                                    </td>
                                    <td>
                                        <button
                                            type="button"
                                            className={record.status === 'validating' ? 'action-btn action-btn-review' : 'action-btn'}
                                            onClick={() => handleActionClick(record)}
                                        >
                                            {record.status === 'validating' ? 'Review' : 'View'} &gt;
                                        </button>
                                    </td>
                                </tr>
                            )
                        })}
                    </tbody>
                </table>
            </div>

            <AttendancePagination/>

            {confirmOpen && (
                <div className="delete-modal-overlay">
                    <div className="delete-modal">
                        <div className="delete-modal-icon">
                            <TrashIcon/>
                        </div>
                        <h2>Confirm Deleting?</h2>
                        <div className="delete-modal-divider" />
                        <p>
                            Are you sure you want to delete the selected items
                            (<span className="delete-modal-count">{selectedIds.length} items selected</span>)?
                            You can't get them back once you delete them.
                        </p>
                        <div className="delete-modal-actions">
                            <button type="button" className="delete-modal-cancel" onClick={() => setConfirmOpen(false)} disabled={deleting}>
                                Cancel
                            </button>
                            <button type="button" className="delete-modal-confirm" onClick={confirmDelete} disabled={deleting}>
                                {deleting ? 'Deleting…' : 'Confirm Delete'}
                            </button>
                        </div>
                    </div>
                </div>
            )}

            <ReviewDrawer 
                isOpen={drawerOpen} 
                onClose={() => setDrawerOpen(false)} 
                record={activeRecord}
                onSubmitted={() => {
                    setDrawerOpen(false);
                    setRefreshTrigger((prev) => prev + 1);
                }}
            />
        </section>
    )
}

export default AttendanceTable