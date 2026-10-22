import { useEffect, useState } from 'react'
import Header from '../../../components/layout/Header'
import Sidebar from '../../../components/layout/Sidebar'
import LoadingCircle from '../../../components/feedback/LoadingCircle'
import ModalSelect from '../dashboard/ModalSelect'
import { request } from '../../../api/request'
import TotalActivitiesIcon from '../../../assets/icons/total-activities-icon.svg'
import TodayActivitiesIcon from '../../../assets/icons/today-activities-icon.svg'
import UserRolesChangesIcon from '../../../assets/icons/user-roles-changes-icons.svg'
import SecurityEventsIcon from '../../../assets/icons/security-events-icon.svg'
import './ActivityLog.css'

const dateFormatter = new Intl.DateTimeFormat('en-US', { month: 'short', day: 'numeric', year: 'numeric' })
const timeFormatter = new Intl.DateTimeFormat('en-US', { hour: 'numeric', minute: '2-digit', hour12: true })

function ActivityDetails({ activity, onClose }) {
    useEffect(() => {
        const closeOnEscape = event => { if (event.key === 'Escape') onClose() }
        window.addEventListener('keydown', closeOnEscape)
        return () => window.removeEventListener('keydown', closeOnEscape)
    }, [onClose])

    const when = activity.created_at ? new Date(activity.created_at) : null
    return <div className="admin-activity-history-backdrop" onMouseDown={event => { if (event.target === event.currentTarget) onClose() }}>
        <section className="admin-activity-history-dialog" role="dialog" aria-modal="true" aria-labelledby="activity-details-title">
            <button className="admin-activity-history-close" type="button" onClick={onClose} aria-label="Close activity details" autoFocus>×</button>
            <h2 id="activity-details-title">Activity Details</h2>
            <p className="admin-activity-history-dialog-intro">Recorded administrator action</p>
            <dl>
                <div><dt>Activity</dt><dd>{activity.action}</dd></div>
                <div><dt>Date & Time</dt><dd>{when && !Number.isNaN(when.getTime()) ? `${dateFormatter.format(when)} at ${timeFormatter.format(when)}` : '—'}</dd></div>
                <div><dt>Affected Record</dt><dd>{activity.affected_record}</dd></div>
                <div><dt>Module</dt><dd>{activity.module}</dd></div>
                <div><dt>Performed By</dt><dd>{activity.performed_by}{activity.performed_by_email && <small>{activity.performed_by_email}</small>}</dd></div>
                <div><dt>Result</dt><dd className="admin-activity-history-success">{activity.result}</dd></div>
                <div><dt>Record Reference</dt><dd>{activity.target_table} #{activity.target_id}</dd></div>
            </dl>
            <button className="admin-activity-history-done" type="button" onClick={onClose}>Close</button>
        </section>
    </div>
}

function ActivityLog() {
    const [page, setPage] = useState(1)
    const [activityLog, setActivityLog] = useState(null)
    const [summary, setSummary] = useState(null)
    const [error, setError] = useState('')
    const [loading, setLoading] = useState(true)
    const [filters, setFilters] = useState({ date: '', module: '', result: '' })
    const [searchInput, setSearchInput] = useState('')
    const [search, setSearch] = useState('')
    const [selectedActivity, setSelectedActivity] = useState(null)

    useEffect(() => {
        const timeout = window.setTimeout(() => {
            const nextSearch = searchInput.trim()
            if (nextSearch !== search) {
                setSearch(nextSearch)
                setPage(1)
                setLoading(true)
            }
        }, 300)
        return () => window.clearTimeout(timeout)
    }, [searchInput, search])

    useEffect(() => {
        let active = true
        const params = new URLSearchParams({ page: String(page) })
        if (search) params.set('search', search)
        if (filters.date) params.set('date', filters.date)
        if (filters.module) params.set('module', filters.module)
        if (filters.result) params.set('result', filters.result)
        request(`/admin/activity-log?${params}`)
            .then(data => {
                if (!active) return
                setActivityLog(data.activity_log)
                setSummary(data.summary)
                setError('')
            })
            .catch(error => {
                if (active) {
                    setActivityLog(null)
                    setSummary(null)
                    setError(error.message || 'Unable to load the activity log.')
                }
            })
            .finally(() => { if (active) setLoading(false) })
        return () => { active = false }
    }, [page, search, filters.date, filters.module, filters.result])

    function updateFilter(name, value) {
        if (filters[name] === value) return
        setFilters(current => ({ ...current, [name]: value }))
        setPage(1)
        setLoading(true)
    }

    function changePage(nextPage) {
        if (nextPage === page) return
        setPage(nextPage)
        setLoading(true)
    }

    const todayLabel = summary?.today_date
        ? new Intl.DateTimeFormat('en-US', { month: 'long', day: 'numeric', year: 'numeric' }).format(new Date(`${summary.today_date}T00:00:00`))
        : 'Today'
    const cards = [
        { label: 'Total Activities', value: summary?.total, detail: 'Recorded administrator actions', tone: 'purple', icon: TotalActivitiesIcon },
        { label: 'Today’s Activities', value: summary?.today, detail: todayLabel, tone: 'blue', icon: TodayActivitiesIcon },
        { label: 'User & Role Changes', value: summary?.user_role_changes, detail: 'Account and permission updates', tone: 'green', icon: UserRolesChangesIcon },
        { label: 'Security Events', value: summary?.security_events, detail: 'Sign-ins and password changes', tone: 'red', icon: SecurityEventsIcon },
    ]
    const totalPages = activityLog?.last_page ?? 1
    const firstPageButton = Math.max(1, Math.min(page - 2, totalPages - 4))
    const pageButtons = Array.from({ length: Math.min(5, totalPages) }, (_, index) => firstPageButton + index)
    const firstItem = activityLog?.total ? (activityLog.current_page - 1) * activityLog.per_page + 1 : 0
    const lastItem = activityLog?.total ? firstItem + activityLog.data.length - 1 : 0

    return (
        <div className="admin-activity-log">
            <Sidebar />
            <main className="admin-activity-log-main">
                <Header
                    title="Activity Log"
                    subtitle="Track administrator actions across your system."
                    showNotification={false}
                    className="admin-activity-log-header"
                />
                <div className="admin-activity-log-content">
                    {error && <p className="admin-activity-log-error" role="alert">{error}</p>}
                    <section className="admin-activity-log-cards" aria-label="Activity log overview" aria-busy={!summary && !error}>
                        {cards.map(card => <article className={`admin-activity-log-card ${card.tone}`} key={card.label}>
                            <span className="admin-activity-log-card-icon" aria-hidden="true"><img src={card.icon} alt="" /></span>
                            <div className="admin-activity-log-card-copy">
                                <h2>{card.label}</h2>
                                <strong>{summary ? (card.value ?? 0) : '—'}</strong>
                                <p>{card.detail}</p>
                            </div>
                        </article>)}
                    </section>
                    <section className="admin-activity-history" aria-labelledby="activity-history-title">
                        <div className="admin-activity-history-heading">
                            <h2 id="activity-history-title">Activity History</h2>
                            <p>A record of account, student, program, settings and security actions.</p>
                        </div>
                        <div className="admin-activity-history-filters">
                            <span className="admin-activity-history-select"><ModalSelect aria-label="Filter by date" value={filters.date} onChange={event => updateFilter('date', event.target.value)}>
                                <option value="">All Dates</option><option value="today">Today</option><option value="7days">Last 7 Days</option><option value="30days">Last 30 Days</option>
                            </ModalSelect></span>
                            <span className="admin-activity-history-select"><ModalSelect aria-label="Filter by module" value={filters.module} onChange={event => updateFilter('module', event.target.value)}>
                                <option value="">All Modules</option><option value="users">User &amp; Roles</option><option value="students">Students</option><option value="programs">Programs</option><option value="settings">System Settings</option>
                            </ModalSelect></span>
                            <span className="admin-activity-history-select"><ModalSelect aria-label="Filter by result" value={filters.result} onChange={event => updateFilter('result', event.target.value)}>
                                <option value="">All Results</option><option value="success">Success</option><option value="failed">Failed</option>
                            </ModalSelect></span>
                            <label className="admin-activity-history-search">
                                <svg viewBox="0 0 20 20" fill="none" aria-hidden="true"><circle cx="8.7" cy="8.7" r="5.3" stroke="currentColor" strokeWidth="1.6" /><path d="m12.7 12.7 4 4" stroke="currentColor" strokeWidth="1.6" strokeLinecap="round" /></svg>
                                <input type="search" aria-label="Search activity, administrator or record" placeholder="Search activity, admin or record..." value={searchInput} onChange={event => setSearchInput(event.target.value)} maxLength={100} />
                            </label>
                        </div>
                        <div className="admin-activity-history-table-wrap" role="region" aria-label="Activity history table" tabIndex="0" aria-busy={loading}>
                            <table className="admin-activity-history-table">
                                <thead><tr><th scope="col">Date &amp; Time</th><th scope="col">Activity / Affected Record</th><th scope="col">Module</th><th scope="col">Performed By</th><th scope="col">Result</th><th scope="col">Details</th></tr></thead>
                                <tbody>
                                    {loading ? <tr><td colSpan="6" className="admin-activity-history-empty"><LoadingCircle label="Loading activity history..." /></td></tr>
                                        : error ? <tr><td colSpan="6" className="admin-activity-history-empty">Unable to load activity history.</td></tr>
                                            : (activityLog?.data ?? []).length === 0 ? <tr><td colSpan="6" className="admin-activity-history-empty">No activity has been recorded yet.</td></tr>
                                            : activityLog.data.map(activity => {
                                                const when = activity.created_at ? new Date(activity.created_at) : null
                                                const valid = when && !Number.isNaN(when.getTime())
                                                return <tr key={activity.id}>
                                                    <td><span className="admin-activity-history-main">{valid ? dateFormatter.format(when) : '—'}</span><small>{valid ? timeFormatter.format(when) : '—'}</small></td>
                                                    <td><span className="admin-activity-history-main">{activity.action}</span><small>{activity.affected_record}</small></td>
                                                    <td>{activity.module}</td>
                                                    <td><span className="admin-activity-history-main">{activity.performed_by}</span><small>{activity.performed_by_email || '—'}</small></td>
                                                    <td><span className="admin-activity-history-success">{activity.result}</span></td>
                                                    <td><button type="button" className="admin-activity-history-details-link" onClick={() => setSelectedActivity(activity)}>View details</button></td>
                                                </tr>
                                            })}
                                </tbody>
                            </table>
                        </div>
                        <div className="admin-activity-history-footer">
                            <div><p>Showing {firstItem}–{lastItem} of {activityLog?.total ?? 0} activities</p><small>Activity records are read-only and shown newest first.</small></div>
                            {totalPages > 1 && <nav className="admin-activity-history-pagination" aria-label="Activity history pages">
                                <button type="button" disabled={page <= 1 || loading} onClick={() => changePage(page - 1)}>Previous</button>
                                {pageButtons.map(number => <button type="button" key={number} className={number === page ? 'active' : ''} aria-current={number === page ? 'page' : undefined} disabled={loading} onClick={() => changePage(number)}>{number}</button>)}
                                <button type="button" disabled={page >= totalPages || loading} onClick={() => changePage(page + 1)}>Next</button>
                            </nav>}
                        </div>
                    </section>
                </div>
            </main>
            {selectedActivity && <ActivityDetails activity={selectedActivity} onClose={() => setSelectedActivity(null)} />}
        </div>
    )
}

export default ActivityLog
