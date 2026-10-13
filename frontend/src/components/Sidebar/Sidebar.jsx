import { useEffect, useState } from 'react'
import { createPortal } from 'react-dom'
import { NavLink, useNavigate } from 'react-router-dom'
import { API_BASE_URL } from '../../api/request'
import LVCCLogo from '../../assets/lvcc-logo.webp'
import DashboardIcon from '../../assets/dashboard-icon.svg'
import MonitoringIcon from '../../assets/monitoring-icon.svg'
import StudentsIcon from '../../assets/students-icon.svg'
import ImportIcon from '../../assets/import-icon.svg'
import AttendanceIcon from '../../assets/attendance-icon.svg'
import RemindersIcon from '../../assets/reminders-icon.svg'
import LogoutIcon from '../../assets/logout-icon.svg'
import ProfileIcon from '../../assets/profile-icon.svg'
import './Sidebar.css'

const navigationByRole = {
    secretary: [
        { label: 'Dashboard', to: '/dashboard', icon: DashboardIcon },
        { label: 'Students', to: '/students', icon: StudentsIcon },
        { label: 'Import', to: '/import', icon: ImportIcon },
        { label: 'Attendance', to: '/attendance', icon: AttendanceIcon },
        { label: 'Reminders', to: '/reminders', icon: RemindersIcon },
    ],

    program_secretary: [
        { label: 'Dashboard', to: '/program-secretary/dashboard', icon: DashboardIcon },
        { label: 'Submissions', to: '/program-secretary/submission', icon: AttendanceIcon },
        { label: 'Records', to: '/program-secretary/records', icon: StudentsIcon },
        { label: 'Reminders', to: '/reminders', icon: RemindersIcon },
    ],

    program_head: [
        { label: 'Dashboard', to: '/program-head/dashboard', icon: DashboardIcon },
        { label: 'Submission', to: '/program-head/submission', icon: ImportIcon },
        { label: 'Records', to: '/program-head/records', icon: AttendanceIcon },
        { label: 'Reminders', to: '/reminders', icon: RemindersIcon },
    ],
    chancellor: [
        { label: 'Dashboard', to: '/chancellor/dashboard', icon: DashboardIcon },
    ],
    cm_coordinator: [
        { label: 'Monitoring', to: '/coordinator/monitoring', icon: MonitoringIcon },
        { label: 'Reminders', to: '/reminders', icon: RemindersIcon },
    ],
    admin: [
        { label: 'Dashboard', to: '/admin/dashboard', icon: DashboardIcon },
        { label: 'User Roles', to: '/admin/user-roles', icon: StudentsIcon },
        { label: 'Students', to: '/admin/students', icon: StudentsIcon },
        { label: 'Programs', to: '/admin/programs', icon: ImportIcon },
        { label: 'System Settings', to: '/admin/settings', icon: AttendanceIcon },
    ],
}

function Sidebar() {
    const navigate = useNavigate()
    const [isLogoutModalOpen, setIsLogoutModalOpen] = useState(false)
    let role = ''
    try { role = JSON.parse(localStorage.getItem('user') || 'null')?.role || '' } catch { /* invalid session */ }
    const navigationItems = navigationByRole[role] || []

    const handleSignOut = () => {
        const token = localStorage.getItem('auth_token')
        localStorage.removeItem('auth_token')
        localStorage.removeItem('user')
        setIsLogoutModalOpen(false)
        navigate('/', { replace: true })

        if (token) {
            fetch(`${API_BASE_URL}/logout`, {
                method: 'POST',
                headers: { Accept: 'application/json', Authorization: `Bearer ${token}` },
            }).catch(() => {
                // The local session is already cleared; ignore a network failure.
            })
        }
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
        <aside className={`sidebar ${role === 'program_head' ? 'sidebar-program-head' : ''}`}>

            <div className="sidebar-logo">
                <img
                    className="sidebar-lvcc-logo"
                    src={LVCCLogo}
                    alt="LVCC Logo"
                />
            </div>

            <nav className="sidebar-nav">
                {navigationItems.map((item) => (
                    <NavLink
                        key={item.to}
                        to={item.to}
                        end
                        className={({ isActive }) => `sidebar-link ${isActive ? 'active' : ''}`}
                    >
                        <img className="sidebar-icon" src={item.icon} alt="" />
                        <span>{item.label}</span>
                    </NavLink>
                ))}
            </nav>

            <div className="sidebar-footer">
                <button type="button" className="sidebar-signout" onClick={() => setIsLogoutModalOpen(true)}>
                    <span>Sign Out</span>
                    <img
                        className="sign-out-icon"
                        src={LogoutIcon}
                        alt=""
                        aria-hidden="true"
                    />
                </button>
                <NavLink
                    to="/profile"
                    className={({ isActive }) => `sidebar-profile ${isActive ? 'active' : ''}`}
                    aria-label="Profile"
                    title="Profile"
                >
                    <img className="profile-icon" src={ProfileIcon} alt="" />
                </NavLink>
            </div>

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
