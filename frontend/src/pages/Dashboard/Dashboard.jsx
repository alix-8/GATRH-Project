import Sidebar from '../../components/Sidebar/Sidebar'
import PencilIcon from '../../assets/pencil-icon.png'
import EraserIcon from '../../assets/eraser-icon.png'
import DashboardIllustration from '../../assets/dashboard-illustration.png'
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

                    <img
                        className="dashboard-illustration"
                        src={DashboardIllustration}
                        alt="Dashboard Illustration"
                    />
                </div>

                <div className="dashboard-pencil-container">
                    <img
                        className="dashboard-pencil-icon"
                        src={PencilIcon}
                        alt="Pencil Icon"
                    />
                </div>
                <p className="week-attendance">This Week's Attendance</p>

                <div className="dashboard-eraser-container">
                    <img
                        className="dashboard-eraser-icon"
                        src={EraserIcon}
                        alt="Eraser Icon"
                    />
                </div>
                <p className="week-absences">This Week's Absences</p>
            </main>
        </div>
    )
}

export default Dashboard