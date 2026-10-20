import Header from '../../../components/layout/Header'
import Sidebar from '../../../components/layout/Sidebar'
import './SystemSettings.css'

function SystemSettings() {
    return (
        <div className="admin-system-settings">
            <Sidebar />
            <main className="admin-system-settings-main">
                <Header
                    title="System Settings"
                    subtitle="Set up your system settings and grap a backup."
                    showNotification={false}
                    className="admin-system-settings-header"
                />
            </main>
        </div>
    )
}

export default SystemSettings
