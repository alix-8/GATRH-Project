import Sidebar from '../../../components/Sidebar/Sidebar'
import './Dashboard.css'

function Dashboard() {
    return (
        <div className="dashboard">
            <Sidebar />

            <main className="dashboard-main">
                <h1>Dashboard</h1>
                <p>Monitor student attendance</p>
            </main>
        </div>
    )
}

export default Dashboard