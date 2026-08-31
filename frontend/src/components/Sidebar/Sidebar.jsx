import LVCCLogo from '../../assets/lvcc-logo.png'
import DashboardIcon from '../../assets/dashboard-icon.png'
// import StudentsIcon from '../../assets/students-icon.png'
import './Sidebar.css'

function Sidebar() {
    return (
        <aside className="sidebar">

            <div className="sidebar-logo">
                <img
                    className="lvcc-logo"
                    src={LVCCLogo}
                    alt="LVCC Logo"
                />
            </div>

            {/* Navigation */}
            <nav className="sidebar-nav">
                <a href="/dashboard" className="sidebar-link active">
                    <img
                        className="sidebar-icon"
                        src={DashboardIcon}
                        alt="Dashboard"
                    />
                    <p>Dashboard</p>
                </a>

                {/* <a href="/students" className="sidebar-link">
                     <img
                        src={StudentsIcon}
                        alt="Students"
                        className="sidebar-icon"
                    />
                    <span>Students</span>
                </a> */}

                {/* <a href="/import" className="sidebar-link">
                    <span className="sidebar-icon">↗</span>
                    <span>Import</span>
                </a>

                <a href="/attendance" className="sidebar-link">
                    <span className="sidebar-icon">✓</span>
                    <span>Attendance</span>
                </a>

                <a href="/records" className="sidebar-link">
                    <span className="sidebar-icon">▱</span>
                    <span>Records</span>
                </a>

                <a href="/reminders" className="sidebar-link">
                    <span className="sidebar-icon">▣</span>
                    <span>Reminders</span>
                </a> */}

            </nav>

        </aside>
    )
}

export default Sidebar