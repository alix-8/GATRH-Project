import { useEffect, useState, useCallback, useRef } from 'react'
import { useLocation, useNavigate } from 'react-router-dom'
import PlusIcon from '../../../../assets/plus-icon.svg'
import GatheringIcon from '../../../../assets/gathering-icon.svg'
import CalendarIcon from '../../../../assets/calendar-icon.svg'
import SortbyIcon from '../../../../assets/sort-by-icon.svg'
import DropdownIcon from '../../../../assets/drop-down-icon.svg'
import { request } from '../../../../api/request'
import { TrashIcon } from '../../../../components/Icons/Reusables'
import Calendar from '../../../../components/Calendar/Calendar'
import './AttendanceTable.css'
import ReviewDrawer from './ReviewDrawer'
import AttendancePagination from './AttendancePagination'
import LoadingCircle from '../../../../components/Loading/LoadingCircle'

const STATUS_META = {
    validating: { label: 'Validating', dot: '#8c96a4' },
    pending: { label: 'Pending', dot: '#F2994A' },
    rejected: { label: 'Rejected', dot: '#E52328' },
    approved: { label: 'Approved', dot: '#27AE60' },
}

const GATHERING_OPTIONS = [
    { value: '', label: 'Gathering' },
    { value: 'pm', label: 'Prayer Meeting' },
    { value: 'ws', label: 'Worship Service' },
    { value: 'tg', label: 'Thanksgiving' },
    { value: 'special', label: 'Special' },
]

const SORT_OPTIONS = [
    { value: 'day', label: 'Sort by Date' },
    { value: 'status', label: 'Sort by Status' },
    { value: 'gathering_type', label: 'Sort by Gathering' },
]

// Local YYYY-MM-DD (avoids the UTC-shift bug from toISOString())
function toDateParam(date) {
    if (!date) return ''
    const y = date.getFullYear()
    const m = String(date.getMonth() + 1).padStart(2, '0')
    const d = String(date.getDate()).padStart(2, '0')
    return `${y}-${m}-${d}`
}

function formatDateLabel(date) {
    if (!date) return 'Date'
    return date.toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' })
}

function AttendanceTable() {
    const [records, setRecords] = useState([])
    const [meta, setMeta] = useState({ current_page: 1, last_page: 1 })
    const [page, setPage] = useState(1)
    const [gathering, setGathering] = useState('')
    const [selectedDate, setSelectedDate] = useState(null)
    const [sortBy, setSortBy] = useState('day')
    const [loading, setLoading] = useState(true)
    const [error, setError] = useState(null)
    const [refreshTrigger, setRefreshTrigger] = useState(0)

    const [isGatheringOpen, setIsGatheringOpen] = useState(false)
    const gatheringDropdownRef = useRef(null)

    const [isSortOpen, setIsSortOpen] = useState(false)
    const sortDropdownRef = useRef(null)

    const [isCalendarOpen, setIsCalendarOpen] = useState(false)
    const calendarDropdownRef = useRef(null)

    const [drawerOpen, setDrawerOpen] = useState(false)
    const [activeRecord, setActiveRecord] = useState(null)

    const [deleteMode, setDeleteMode] = useState(false)
    const [selectedIds, setSelectedIds] = useState([])
    const [confirmOpen, setConfirmOpen] = useState(false)
    const [deleting, setDeleting] = useState(false)
    const navigate = useNavigate()
    const location = useLocation()

    useEffect(() => {
        const targetRecord = location.state?.openAttendanceRecord
        if (!targetRecord) return

        setActiveRecord(targetRecord)
        setDrawerOpen(true)
        navigate(location.pathname, { replace: true, state: null })
    }, [location.pathname, location.state, navigate])

    useEffect(() => {
        function handleClickOutside(event) {
            if (gatheringDropdownRef.current && !gatheringDropdownRef.current.contains(event.target)) {
                setIsGatheringOpen(false)
            }
            if (sortDropdownRef.current && !sortDropdownRef.current.contains(event.target)) {
                setIsSortOpen(false)
            }
            if (calendarDropdownRef.current && !calendarDropdownRef.current.contains(event.target)) {
                setIsCalendarOpen(false)
            }
        }
        document.addEventListener('mousedown', handleClickOutside)
        return () => document.removeEventListener('mousedown', handleClickOutside)
    }, [])

    const handleSelectGathering = (value) => {
        setGathering(value)
        setPage(1)
        setIsGatheringOpen(false)
    }

    const handleSelectSort = (value) => {
        setSortBy(value)
        setPage(1)
        setIsSortOpen(false)
    }

    const handleSelectDate = (date) => {
        setSelectedDate(date)
        setPage(1)
        setIsCalendarOpen(false)
    }

    const clearDate = (e) => {
        e.stopPropagation()
        setSelectedDate(null)
        setPage(1)
    }

    const fetchRecords = useCallback(async (signal) => {
        setLoading(true)
        setError(null)
        try {
            const params = new URLSearchParams()
            if (gathering) params.set('gathering_type', gathering)
            if (selectedDate) params.set('date', toDateParam(selectedDate))
            if (sortBy) params.set('sort_by', sortBy)
            params.set('page', page)

            const json = await request(`/attendance?${params.toString()}`, { signal })

            setRecords(json.data)
            setMeta(json.meta)
        } catch (err) {
            if (err.name !== 'AbortError') setError(err.message)
        } finally {
            if (!signal?.aborted) setLoading(false)
        }
    }, [gathering, selectedDate, sortBy, page])

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

    const selectedGatheringLabel = GATHERING_OPTIONS.find((o) => o.value === gathering)?.label || 'Gathering'
    const selectedSortLabel = SORT_OPTIONS.find((o) => o.value === sortBy)?.label || 'Sort by'

    return (
        <section className="attendance-table-section">
            <div className="attendance-toolbar">
                <div className="gathering-dropdown-container" ref={gatheringDropdownRef}>
                    <div
                        className={`toolbar-dropdown-row toolbar-dropdown-row-primary ${isGatheringOpen ? 'active' : ''}`}
                        onClick={() => {
                            setIsGatheringOpen((prev) => !prev)
                            setIsSortOpen(false)
                            setIsCalendarOpen(false)
                        }}
                    >
                        <img className="toolbar-dropdown-icon" src={GatheringIcon} alt="" />
                        <p className="toolbar-dropdown-text">{selectedGatheringLabel}</p>
                        <img
                            className={`toolbar-dropdown-chevron toolbar-dropdown-chevron-light ${isGatheringOpen ? 'rotate' : ''}`}
                            src={DropdownIcon}
                            alt=""
                        />
                    </div>

                    {isGatheringOpen && (
                        <ul className="toolbar-dropdown-menu">
                            {GATHERING_OPTIONS.map((option) => (
                                <li
                                    key={option.value || 'all'}
                                    className={`toolbar-dropdown-item ${gathering === option.value ? 'selected' : ''}`}
                                    onClick={() => handleSelectGathering(option.value)}
                                >
                                    {option.label}
                                </li>
                            ))}
                        </ul>
                    )}
                </div>

                <div className="calendar-dropdown-container" ref={calendarDropdownRef}>
                    <div
                        className={`toolbar-dropdown-row ${isCalendarOpen ? 'active' : ''}`}
                        onClick={() => {
                            setIsCalendarOpen((prev) => !prev)
                            setIsGatheringOpen(false)
                            setIsSortOpen(false)
                        }}
                    >
                        <img className="toolbar-dropdown-icon" src={CalendarIcon} alt="" />
                        <p className="toolbar-dropdown-text">{formatDateLabel(selectedDate)}</p>
                        {selectedDate && (
                            <button type="button" className="toolbar-dropdown-clear" onClick={clearDate} aria-label="Clear date">
                                ×
                            </button>
                        )}
                    </div>

                    {isCalendarOpen && (
                        <div className="toolbar-calendar-popover">
                            <Calendar
                                value={selectedDate}
                                onChange={handleSelectDate}
                                initialMonth={selectedDate || new Date()}
                            />
                        </div>
                    )}
                </div>

                <div className="sort-dropdown-container" ref={sortDropdownRef}>
                    <div
                        className={`toolbar-dropdown-row ${isSortOpen ? 'active' : ''}`}
                        onClick={() => {
                            setIsSortOpen((prev) => !prev)
                            setIsGatheringOpen(false)
                            setIsCalendarOpen(false)
                        }}
                    >
                        <img className="toolbar-dropdown-icon" src={SortbyIcon} alt="" />
                        <p className="toolbar-dropdown-text">{selectedSortLabel}</p>
                        <img
                            className={`toolbar-dropdown-chevron ${isSortOpen ? 'rotate' : ''}`}
                            src={DropdownIcon}
                            alt=""
                        />
                    </div>

                    {isSortOpen && (
                        <ul className="toolbar-dropdown-menu">
                            {SORT_OPTIONS.map((option) => (
                                <li
                                    key={option.value}
                                    className={`toolbar-dropdown-item ${sortBy === option.value ? 'selected' : ''}`}
                                    onClick={() => handleSelectSort(option.value)}
                                >
                                    {option.label}
                                </li>
                            ))}
                        </ul>
                    )}
                </div>

                <div className="toolbar-spacer" />

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
                            <tr><td colSpan={deleteMode ? 8 : 7} className="table-state"><LoadingCircle label="Loading attendance records…" /></td></tr>
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

            <AttendancePagination
                page={page}
                meta={meta}
                goToPage={goToPage}
            />

            {confirmOpen && (
                <div className="delete-modal-overlay">
                    <div className="delete-modal">
                        <div className="delete-modal-icon">
                            <TrashIcon />
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
