import Sidebar from '../../../components/Sidebar/Sidebar'
import Header from '../../../components/Header/Header'
import './ChancellorDashboard.css'

function ChancellorDashboard() {
    return (
        <div className="chancellor-dashboard">
            <Sidebar />
            <main className="chancellor-dashboard-main">
                <Header
                    title="Dashboard"
                    subtitle="Monitor attendance reporting progress across all programs."
                    className="chancellor-dashboard-header"
                />
            </main>
        </div>
    )
}

export default ChancellorDashboard
