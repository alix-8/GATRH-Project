import { useEffect, useState } from 'react'
import Header from '../../../components/layout/Header'
import Sidebar from '../../../components/layout/Sidebar'
import { request } from '../../../api/request'
import TotalActivitiesIcon from '../../../assets/icons/total-activities-icon.svg'
import TodayActivitiesIcon from '../../../assets/icons/today-activities-icon.svg'
import UserRolesChangesIcon from '../../../assets/icons/user-roles-changes-icons.svg'
import SecurityEventsIcon from '../../../assets/icons/security-events-icon.svg'
import ActivityTable from '../dashboard/ActivityTable'
import './ActivityLog.css'

function ActivityLog() {
    const [page, setPage] = useState(1)
    const [activityLog, setActivityLog] = useState(null)
    const [summary, setSummary] = useState(null)
    const [error, setError] = useState('')

    useEffect(() => {
        let active = true
        request(`/admin/activity-log?page=${page}`)
            .then(data => {
                if (!active) return
                setActivityLog(data.activity_log)
                setSummary(data.summary)
                setError('')
            })
            .catch(error => {
                if (active) setError(error.message || 'Unable to load the activity log.')
            })
        return () => { active = false }
    }, [page])

    const todayLabel = summary?.today_date
        ? new Intl.DateTimeFormat('en-US', { month: 'long', day: 'numeric', year: 'numeric' }).format(new Date(`${summary.today_date}T00:00:00`))
        : 'Today'
    const cards = [
        { label: 'Total Activities', value: summary?.total, detail: 'Recorded administrator actions', tone: 'purple', icon: TotalActivitiesIcon },
        { label: 'Today’s Activities', value: summary?.today, detail: todayLabel, tone: 'blue', icon: TodayActivitiesIcon },
        { label: 'User & Role Changes', value: summary?.user_role_changes, detail: 'Account and permission updates', tone: 'green', icon: UserRolesChangesIcon },
        { label: 'Security Events', value: summary?.security_events, detail: 'Sign-ins and password changes', tone: 'red', icon: SecurityEventsIcon },
    ]

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
                                <strong>{summary ? card.value : '—'}</strong>
                                <p>{card.detail}</p>
                            </div>
                        </article>)}
                    </section>
                    <section className="admin-panel admin-activity-log-panel">
                        <div className="admin-panel-heading"><div><h2>System Activity</h2><p>Administrator actions across the system</p></div></div>
                        <ActivityTable activities={activityLog?.data} loading={!activityLog && !error} />
                        {activityLog && activityLog.last_page > 1 && <nav className="admin-activity-log-pagination" aria-label="Activity log pages">
                            <button type="button" disabled={page <= 1} onClick={() => setPage(current => current - 1)}>Previous</button>
                            <span>Page {activityLog.current_page} of {activityLog.last_page}</span>
                            <button type="button" disabled={page >= activityLog.last_page} onClick={() => setPage(current => current + 1)}>Next</button>
                        </nav>}
                    </section>
                </div>
            </main>
        </div>
    )
}

export default ActivityLog
