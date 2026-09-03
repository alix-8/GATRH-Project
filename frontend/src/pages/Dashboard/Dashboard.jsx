import Sidebar from '../../components/Sidebar/Sidebar'
import './Dashboard.css'

function Dashboard() {
    const today = new Date()

    const formattedDate = today.toLocaleDateString('en-US', {
        weekday: 'long',
        year: 'numeric',
        month: 'long',
        day: 'numeric'
    })

    return (
        <div className="dashboard">
            <Sidebar />

            <main className="dashboard-main">
                <header className="dashboard-header">
                    <h3>Dashboard</h3>
                    <p>Monitor student attendance.</p>
                    <span className="dashboard-date">{formattedDate}</span>
                </header>

                <div className="dashboard-content">
                    <h2>Attendance at
                        <br />
                        a glance.
                    </h2>
                    <p>Monitor student attendance, absences, and records in one
                        <br />
                        place.
                        </p>
                </div>
            </main>
        </div>
    )
}

export default Dashboard