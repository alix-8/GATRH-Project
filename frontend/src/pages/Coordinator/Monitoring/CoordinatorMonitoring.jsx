import Header from '../../../components/Header/Header'
import Sidebar from '../../../components/Sidebar/Sidebar'
import './CoordinatorMonitoring.css'

function CoordinatorMonitoring() {
    return (
        <div className="coordinator-dashboard">
            <Sidebar />
            <main className="coordinator-dashboard-main">
                <Header
                    title="Monitoring"
                    subtitle="Monitor attendance reporting progress across all programs."
                    className="coordinator-dashboard-header"
                />
            </main>
        </div>
    )
}

export default CoordinatorMonitoring
