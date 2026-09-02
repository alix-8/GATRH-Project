import Sidebar from '../../components/Sidebar/Sidebar'
import './Dashboard.css'

function Dashboard() {
    return (
        <div className="dashboard">
            <Sidebar />

            <main className="dashboard-main">
                <header className="dashboard-header">
                    <h3>Dashboard</h3>
                    <p>Monitor student attendance</p>
                </header>
            </main>
        </div>
    )
}

export default Dashboard