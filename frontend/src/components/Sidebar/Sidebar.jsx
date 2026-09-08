import { useEffect, useState } from 'react'
import { createPortal } from 'react-dom'
import { NavLink, useNavigate } from 'react-router-dom'
import LVCCLogo from '../../assets/lvcc-logo.png'
import DashboardIcon from '../../assets/dashboard-icon.png'
import StudentsIcon from '../../assets/students-icon.png'
import ImportIcon from '../../assets/import-icon.png'
import AttendanceIcon from '../../assets/attendance-icon.png'
import RecordsIcon from '../../assets/records-icon.png'
import RemindersIcon from '../../assets/reminders-icon.png'
import SignOutIcon from '../../assets/sign-out-icon.png'
import LogoutIcon from '../../assets/logout-icon.png'
import ProfileIcon from '../../assets/profile-icon.png'
import './Sidebar.css'

function Sidebar() {
    const navigate = useNavigate()
    const [isLogoutModalOpen, setIsLogoutModalOpen] = useState(false)

    const handleSignOut = () => {
        navigate('/')
    }

    useEffect(() => {
        if (!isLogoutModalOpen) {
            return undefined
        }

        const handleEscape = (event) => {
            if (event.key === 'Escape') {
                setIsLogoutModalOpen(false)
            }
        }

        document.addEventListener('keydown', handleEscape)
        return () => document.removeEventListener('keydown', handleEscape)
    }, [isLogoutModalOpen])

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

                <button type="button" className="sidebar-signout" onClick={() => setIsLogoutModalOpen(true)}>
                    <img
                        className="sign-out-icon"
                        src={SignOutIcon}
                        alt="Sign Out"
                    />
                </button>

                <NavLink
                    to="/profile"
                    className={({ isActive }) => `sidebar-profile ${isActive ? 'active' : ''}`}
                >
                    <img
                        className="profile-icon"
                        src={ProfileIcon}
                        alt="Profile"
                    />
                </NavLink>

            </nav>

            {isLogoutModalOpen && createPortal(
                <div
                    className="logout-modal-backdrop"
                    role="presentation"
                    onMouseDown={(event) => {
                        if (event.target === event.currentTarget) {
                            setIsLogoutModalOpen(false)
                        }
                    }}
                >
                    <section
                        className="logout-modal"
                    >
                        <img
                            className="logout-modal-icon"
                            src={LogoutIcon}
                            alt=""
                            aria-hidden="true" />

                        <h2 id="logout-modal-title">Confirm logout?</h2>
                        <div className="logout-modal-divider" />
                        <p>
                            Are you sure you want to log out? You will need to sign in again to access your account.
                        </p>
                        <div className="logout-modal-actions">
                            <button
                                type="button"
                                className="logout-modal-cancel"
                                onClick={() => setIsLogoutModalOpen(false)}
                            >
                                Cancel
                            </button>
                            <button type="button" className="logout-modal-confirm" onClick={handleSignOut}>
                                Log out
                            </button>
                        </div>
                    </section>
                </div>,
                document.body,
            )}

        </aside>
    )
}

export default Sidebar