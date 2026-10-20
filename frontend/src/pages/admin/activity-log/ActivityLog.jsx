import { useEffect, useState } from 'react'
import Header from '../../../components/layout/Header'
import Sidebar from '../../../components/layout/Sidebar'
import { request } from '../../../api/request'
import ActivityTable from '../dashboard/ActivityTable'
import './ActivityLog.css'

function ActivityLog() {
    const [page, setPage] = useState(1)
    const [activityLog, setActivityLog] = useState(null)
    const [error, setError] = useState('')

    useEffect(() => {
        let active = true
        request(`/admin/activity-log?page=${page}`)
            .then(data => {
                if (!active) return
                setActivityLog(data.activity_log)
                setError('')
            })
            .catch(error => {
                if (active) setError(error.message || 'Unable to load the activity log.')
            })
        return () => { active = false }
    }, [page])

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
