import { useEffect, useState } from 'react'
import { createPortal } from 'react-dom'
import { NavLink, useNavigate } from 'react-router-dom'
import { API_BASE_URL, initializeCsrf, xsrfHeaders } from '../../api/request'
import LVCCLogo from '../../assets/icons/lvcc-logo.svg'
import DashboardIcon from '../../assets/icons/dashboard-icon.svg'
import MonitoringIcon from '../../assets/icons/monitoring-icon.svg'
import StudentsIcon from '../../assets/icons/students-icon.svg'
import ImportIcon from '../../assets/icons/import-icon.svg'
import AttendanceIcon from '../../assets/icons/attendance-icon.svg'
import RemindersIcon from '../../assets/icons/reminders-icon.svg'
import LogoutIcon from '../../assets/icons/logout-icon.svg'
import { clearLoginSkeleton } from '../../utils/loginSkeletonState'
import ProfileIcon from '../../assets/icons/profile-icon.svg'
import SystemSettingsIcon from '../../assets/icons/system-settings-icon.svg'
import UserRolesIcon from '../../assets/icons/user-roles-icon.svg'
import ProgramsIcon from '../../assets/icons/programs-icon.svg'
import ActivityLogIcon from '../../assets/icons/activity-log-icon.svg'
import PendingSubmissionIcon from '../../assets/icons/pending-submission-kpi-icon.svg'
import RecordManagementIcon from '../../assets/icons/record-management.svg'
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
        { label: 'Submissions', to: '/program-secretary/submission', icon: PendingSubmissionIcon, lightIcon: true },
        { label: 'Records', to: '/program-secretary/records', icon: RecordManagementIcon, lightIcon: true },
        { label: 'Reminders', to: '/reminders', icon: RemindersIcon },
    ],

    program_head: [
        { label: 'Dashboard', to: '/program-head/dashboard', icon: DashboardIcon },
        { label: 'Submission', to: '/program-head/submission', icon: PendingSubmissionIcon, lightIcon: true },
        { label: 'Records', to: '/program-head/records', icon: RecordManagementIcon, lightIcon: true },
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
        { label: 'User & Roles', to: '/admin/user-roles', icon: UserRolesIcon },
        { label: 'Students', to: '/admin/students', icon: StudentsIcon },
        { label: 'Programs', to: '/admin/programs', icon: ProgramsIcon },
        { label: 'Activity Log', to: '/admin/activity-log', icon: ActivityLogIcon },
    ],
}

function Sidebar() {
    const navigate = useNavigate()
    const [isLogoutModalOpen, setIsLogoutModalOpen] = useState(false)
    let role = ''
    try { role = JSON.parse(localStorage.getItem('user') || 'null')?.role || '' } catch { /* invalid session */ }
    const navigationItems = navigationByRole[role] || []

    const handleSignOut = () => {
        localStorage.removeItem('user')
        clearLoginSkeleton()
        setIsLogoutModalOpen(false)
        navigate('/', { replace: true })

        initializeCsrf().then(() => fetch(`${API_BASE_URL}/logout`, {
            method: 'POST',
            credentials: 'include',
            headers: { Accept: 'application/json', ...xsrfHeaders() },
        })).catch(() => {})
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
                        <img className={`sidebar-icon${item.lightIcon ? ' sidebar-icon-light' : ''}`} src={item.icon} alt="" />
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
                {role === 'admin' ? (
                    <NavLink
                        to="/admin/system-settings"
                        className={({ isActive }) => `sidebar-settings ${isActive ? 'active' : ''}`}
                        aria-label="System Settings"
                        title="System Settings"
                    >
                        <img className="settings-icon" src={SystemSettingsIcon} alt="" />
                    </NavLink>
                ) : (
                    <NavLink
                        to="/profile"
                        className={({ isActive }) => `sidebar-profile ${isActive ? 'active' : ''}`}
                        aria-label="Profile"
                        title="Profile"
                    >
                        <img className="profile-icon" src={ProfileIcon} alt="" />
                    </NavLink>
                )}
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
