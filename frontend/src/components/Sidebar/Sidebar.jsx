import { NavLink } from 'react-router-dom'
import LVCCLogo from '../../assets/lvcc-logo.png'
import DashboardIcon from '../../assets/dashboard-icon.png'
import StudentsIcon from '../../assets/students-icon.png'
import ImportIcon from '../../assets/import-icon.png'
import AttendanceIcon from '../../assets/attendance-icon.png'
import './Sidebar.css'

function Sidebar() {
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
            </nav>

        </aside>
    )
}

export default Sidebar