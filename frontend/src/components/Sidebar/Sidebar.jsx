import { NavLink, useNavigate } from 'react-router-dom'
import LVCCLogo from '../../assets/lvcc-logo.png'
import DashboardIcon from '../../assets/dashboard-icon.png'
import StudentsIcon from '../../assets/students-icon.png'
import ImportIcon from '../../assets/import-icon.png'
import AttendanceIcon from '../../assets/attendance-icon.png'
import RecordsIcon from '../../assets/records-icon.png'
import RemindersIcon from '../../assets/reminders-icon.png'
import SignOutIcon from '../../assets/sign-out-icon.png'
import './Sidebar.css'

function Sidebar() {
    const navigate = useNavigate()

    const handleSignOut = () => {
        navigate('/')
    }

    return (
        <aside className="sidebar">

            <div className="sidebar-logo">
                <img
                    className="sidebar-lvcc-logo"
                    src={LVCCLogo}
                    alt="LVCC Logo"
                />
            </div>

            <nav className="sidebar-nav">
                <NavLink
                    to="/dashboard"
                    end
                    className={({ isActive }) => `sidebar-link ${isActive ? 'active' : ''}`}
                >
                    <img
                        className="sidebar-icon"
                        src={DashboardIcon}
                        alt="Dashboard"
                    />
                    <span>Dashboard</span>
                </NavLink>

                <NavLink
                    to="/students"
                    className={({ isActive }) => `sidebar-link ${isActive ? 'active' : ''}`}
                >
                    <img
                        className="sidebar-icon"
                        src={StudentsIcon}
                        alt="Students"
                    />
                    <span>Students</span>
                </NavLink>

                <NavLink
                    to="/import"
                    className={({ isActive }) => `sidebar-link ${isActive ? 'active' : ''}`}
                >
                    <img
                        className="sidebar-icon"
                        src={ImportIcon}
                        alt="Import"
                    />
                    <span>Import</span>
                </NavLink>

                <NavLink
                    to="/attendance"
                    className={({ isActive }) => `sidebar-link ${isActive ? 'active' : ''}`}
                >
                    <img
                        className="sidebar-icon"
                        src={AttendanceIcon}
                        alt="Attendance"
                    />
                    <span>Attendance</span>
                </NavLink>

                <NavLink
                    to="/records"
                    className={({ isActive }) => `sidebar-link ${isActive ? 'active' : ''}`}
                >
                    <img
                        className="sidebar-icon"
                        src={RecordsIcon}
                        alt="Records"
                    />
                    <span>Records</span>
                </NavLink>

                <NavLink
                    to="/reminders"
                    className={({ isActive }) => `sidebar-link ${isActive ? 'active' : ''}`}
                >
                    <img
                        className="sidebar-icon"
                        src={RemindersIcon}
                        alt="Reminders"
                    />
                    <span>Reminders</span>
                </NavLink>

                <button type="button" className="sidebar-signout" onClick={handleSignOut}>
                    <img
                        className="sign-out-icon"
                        src={SignOutIcon}
                        alt="Sign Out"
                    />
                </button>
            </nav>

        </aside>
    )
}

export default Sidebar