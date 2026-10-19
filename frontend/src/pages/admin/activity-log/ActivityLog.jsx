import Header from '../../../components/layout/Header'
import Sidebar from '../../../components/layout/Sidebar'
import './ActivityLog.css'

function ActivityLog() {
    return (
        <div className="admin-activity-log">
            <Sidebar />
            <main className="admin-activity-log-main">
                <Header
                    title="Activity Log"
                    subtitle="Track administrator actions across your system."
                    className="admin-activity-log-header"
                />
            </main>
        </div>
    )
}

export default ActivityLog
