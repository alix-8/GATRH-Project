import { useEffect, useState } from 'react'
import TrashIcon from '../../../assets/trash-icon.png'
import PlusIcon from '../../../assets/plus-icon.png'
import './AttendanceTable.css'

const STATUS_META = {
    validating: { label: 'Validating', dot: '#8c96a4' },
    pending:    { label: 'Pending',    dot: '#F2994A' },
    rejected:   { label: 'Rejected',   dot: '#E52328' },
    approved:   { label: 'Approved',   dot: '#27AE60' },
}

function AttendanceTable() {
    const [records, setRecords] = useState([])
    const [meta, setMeta] = useState({ current_page: 1, last_page: 1 })
    const [gathering, setGathering] = useState('')
    const [date, setDate] = useState('')
    const [sortBy, setSortBy] = useState('day')
    const [loading, setLoading] = useState(true)
    const [error, setError] = useState(null)

    useEffect(() => {
        const controller = new AbortController()

        async function fetchRecords() {
            setLoading(true)
            setError(null)
            try {
                const params = new URLSearchParams()
                if (gathering) params.set('gathering_type', gathering)
                if (date) params.set('date', date)
                if (sortBy) params.set('sort_by', sortBy)
                params.set('page', meta.current_page)

                const res = await fetch(`/api/attendance?${params.toString()}`, {
                    signal: controller.signal,
                    headers: { Accept: 'application/json' },
                })

                if (!res.ok) throw new Error('Failed to load attendance records.')

                const json = await res.json()
                setRecords(json.data)
                setMeta(json.meta)
            } catch (err) {
                if (err.name !== 'AbortError') setError(err.message)
            } finally {
                setLoading(false)
            }
        }

        fetchRecords()
        return () => controller.abort()
    }, [gathering, date, sortBy, meta.current_page])

    const goToPage = (page) => {
        if (page < 1 || page > meta.last_page) return
        setMeta((prev) => ({ ...prev, current_page: page }))
    }

    return (
        <section className="attendance-table-section">
            <div className="attendance-toolbar">
                <select
                    className="toolbar-select toolbar-select-primary"
                    value={gathering}
                    onChange={(e) => setGathering(e.target.value)}
                >
                    <option value="">Gathering</option>
                    <option value="pm">Prayer Meeting</option>
                    <option value="ws">Worship Service</option>
                    <option value="tg">Thanksgiving</option>
                    <option value="special">Special</option>
                </select>

                <input
                    type="date"
                    className="toolbar-select"
                    value={date}
                    onChange={(e) => setDate(e.target.value)}
                />

                <select
                    className="toolbar-select"
                    value={sortBy}
                    onChange={(e) => setSortBy(e.target.value)}
                >
                    <option value="day">Sort by Date</option>
                    <option value="status">Sort by Status</option>
                    <option value="gathering_type">Sort by Gathering</option>
                </select>

                <div className="toolbar-spacer" />

                <button type="button" className="toolbar-icon-btn" aria-label="Delete selected">
                    <img src={TrashIcon} alt="" />
                </button>
                <button type="button" className="toolbar-icon-btn" aria-label="Add record">
                    <img src={PlusIcon} alt="" />
                </button>
            </div>

            <div className="attendance-table-wrapper">
                <table className="attendance-table">
                    <thead>
                        <tr>
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
                            <tr><td colSpan={7} className="table-state">Loading…</td></tr>
                        )}
                        {!loading && error && (
                            <tr><td colSpan={7} className="table-state table-state-error">{error}</td></tr>
                        )}
                        {!loading && !error && records.length === 0 && (
                            <tr><td colSpan={7} className="table-state">No records found.</td></tr>
                        )}
                        {!loading && !error && records.map((record) => {
                            const meta = STATUS_META[record.status] ?? STATUS_META.pending
                            return (
                                <tr key={record.id} className={`row-${record.status}`}>
                                    <td>{record.course}</td>
                                    <td>{record.year}</td>
                                    <td>{record.date}</td>
                                    <td>{record.gathering}</td>
                                    <td>{record.time}</td>
                                    <td>
                                        <span className="status-cell">
                                            <span className="status-dot" style={{ background: meta.dot }} />
                                            {meta.label}
                                        </span>
                                    </td>
                                    <td>
                                        <button
                                            type="button"
                                            className={record.status === 'validating' ? 'action-btn action-btn-review' : 'action-btn'}
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

            <div className="attendance-pagination">
                <span>Showing {meta.current_page} out of {meta.last_page} pages</span>
                <div className="pagination-controls">
                    <button onClick={() => goToPage(meta.current_page - 1)} disabled={meta.current_page === 1}>‹</button>
                    <button className="page-active">{meta.current_page}</button>
                    <button onClick={() => goToPage(meta.current_page + 1)} disabled={meta.current_page === meta.last_page}>›</button>
                </div>
            </div>
        </section>
    )
}

export default AttendanceTable