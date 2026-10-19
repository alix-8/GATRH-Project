import LoadingCircle from '../../../components/feedback/LoadingCircle'
import './ActivityTable.css'

function activityCategory(activity) {
    const target = (activity.target ?? activity.target_table ?? '').toLowerCase()

    if (target === 'users') return { label: 'User Management', tone: 'users', icon: 'user' }
    if (target === 'students') return { label: 'Students', tone: 'students', icon: 'student' }
    if (target === 'programs') return { label: 'Programs', tone: 'programs', icon: 'program' }
    if (target.includes('setting') || target === 'gathering_schedules') return { label: 'Settings', tone: 'settings', icon: 'settings' }
    if (target.includes('attendance') || target.includes('draft')) return { label: 'Attendance', tone: 'attendance', icon: 'student' }

    return { label: 'System', tone: 'system', icon: 'settings' }
}

function ActivityIcon({ kind }) {
    if (kind === 'user') {
        return <svg viewBox="0 0 16 16" fill="currentColor" aria-hidden="true"><circle cx="8" cy="5" r="2.7" /><path d="M3 13.3c0-2.2 1.8-3.9 5-3.9s5 1.7 5 3.9v.7H3z" /></svg>
    }
    if (kind === 'student') {
        return <svg viewBox="0 0 16 16" fill="currentColor" aria-hidden="true"><path d="M1 5.6 8 2l7 3.6L8 9.1zM3.7 8.4 8 10.6l4.3-2.2v2.7c-1.1 1-2.5 1.5-4.3 1.5s-3.2-.5-4.3-1.5zM14 6.4v4.1h-1V6.9z" /></svg>
    }
    if (kind === 'program') {
        return <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true"><path d="M3 2.5h7.5A1.5 1.5 0 0 1 12 4v9H4.5A1.5 1.5 0 0 1 3 11.5z" /><path d="M3 11.5c0-.8.7-1.5 1.5-1.5H12M8.5 5.3v3.4M6.8 7h3.4" /></svg>
    }
    return <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" strokeWidth="1.7" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true"><circle cx="8" cy="8" r="2.3" /><path d="M8 1.5v1.4M8 13.1v1.4M1.5 8h1.4M13.1 8h1.4M3.4 3.4l1 1M11.6 11.6l1 1M12.6 3.4l-1 1M4.4 11.6l-1 1" /></svg>
}

const dateFormatter = new Intl.DateTimeFormat('en-US', { month: 'long', day: 'numeric', year: 'numeric' })
const timeFormatter = new Intl.DateTimeFormat('en-US', { hour: 'numeric', minute: '2-digit', hour12: true })

function ActivityTable({ activities, loading = false, administratorLabel = false }) {
    return <div className="admin-activity-table-wrap">
        <table className="admin-activity-table">
            <thead><tr><th scope="col">Date</th><th scope="col">Time</th><th scope="col">Action</th><th scope="col">Performed by</th><th scope="col" aria-label="Category" /></tr></thead>
            <tbody>
                {(activities ?? []).map(activity => {
                    const category = activityCategory(activity)
                    const date = activity.created_at ? new Date(activity.created_at) : null
                    const isValidDate = date && !Number.isNaN(date.getTime())
                    const removed = /removed|deleted|deactivated/i.test(activity.action)
                    return <tr key={activity.id}>
                        <td>{isValidDate ? dateFormatter.format(date) : '—'}</td>
                        <td>{isValidDate ? timeFormatter.format(date).replace(/\s/g, '').toLowerCase() : '—'}</td>
                        <td><span className="admin-activity-action"><span className={`admin-activity-icon ${removed ? 'removed' : category.tone}`}><ActivityIcon kind={category.icon} /></span><span>{activity.action}</span></span></td>
                        <td className="admin-activity-actor">{administratorLabel ? 'Administrator' : activity.performed_by}</td>
                        <td><span className={`admin-activity-badge ${category.tone}`}>{category.label}</span></td>
                    </tr>
                })}
                {!loading && activities?.length === 0 && <tr><td className="admin-activity-empty" colSpan="5">No activity has been recorded yet.</td></tr>}
                {loading && <tr><td className="admin-activity-empty" colSpan="5"><LoadingCircle label="Loading activity…" size={20} /></td></tr>}
            </tbody>
        </table>
    </div>
}

export default ActivityTable
