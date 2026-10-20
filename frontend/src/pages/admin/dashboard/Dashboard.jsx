import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import Header from '../../../components/layout/Header'
import Sidebar from '../../../components/layout/Sidebar'
import LoadingCircle from '../../../components/feedback/LoadingCircle'
import { request } from '../../../api/request'
import ActiveUsersIcon from '../../../assets/icons/active-users-icon.svg'
import AdminStudentsIcon from '../../../assets/icons/admin-students-icon.svg'
import ActiveProgramsIcon from '../../../assets/icons/active-programs-icon.svg'
import SystemStatusIcon from '../../../assets/icons/system-status-icon.svg'
import ChancellorIcon from '../../../assets/icons/chancellor-icon.svg'
import ProgramHeadsIcon from '../../../assets/icons/program-heads-icon.svg'
import SecretariesIcon from '../../../assets/icons/secretaries-icon.svg'
import AddUserIcon from '../../../assets/icons/add-user-icon.svg'
import AddProgramIcon from '../../../assets/icons/add-program-icon.svg'
import SystemSettingsIcon from '../../../assets/icons/system-settings-icon.svg'
import AddUserModal from './AddUserModal'
import AddStudentModal from './AddStudentModal'
import AddProgramModal from './AddProgramModal'
import ActivityTable from './ActivityTable'
import './Dashboard.css'

const accountRoleIcons = {
    chancellor: { icon: ChancellorIcon, tone: 'purple' },
    program_head: { icon: ProgramHeadsIcon, tone: 'blue' },
    program_secretary: { icon: SecretariesIcon, tone: 'green' },
    secretary: { icon: SecretariesIcon, tone: 'green' },
    student: { icon: AdminStudentsIcon, tone: 'yellow' },
}

function AdminDashboard() {
    const [dashboard, setDashboard] = useState(null)
    const [error, setError] = useState('')
    const [notice, setNotice] = useState('')
    const [isAddUserOpen, setIsAddUserOpen] = useState(false)
    const [isAddStudentOpen, setIsAddStudentOpen] = useState(false)
    const [isAddProgramOpen, setIsAddProgramOpen] = useState(false)

    useEffect(() => {
        request('/admin/dashboard')
            .then(setDashboard)
            .catch(error => setError(error.message || 'Unable to load the admin dashboard.'))
    }, [])

    const summary = dashboard?.summary
    const accountRows = dashboard ? [
        ...dashboard.accounts,
        ...(!dashboard.accounts.some(account => account.role === 'student')
            ? [{ role: 'student', label: 'Students', count: summary?.students ?? 0, isStudentRecord: true }]
            : []),
    ] : []
    const cards = [
        { label: 'Active users', value: summary?.active_users, detail: 'Across all roles', tone: 'blue', icon: ActiveUsersIcon },
        { label: 'Students', value: summary?.students, detail: 'Enrolled in programs', tone: 'green', icon: AdminStudentsIcon },
        { label: 'Programs', value: summary?.active_programs, detail: 'Active programs', tone: 'purple', icon: ActiveProgramsIcon },
        { label: 'System Status', value: dashboard?.system_status, detail: 'All systems operational', tone: 'navy', icon: SystemStatusIcon },
    ]

    function handleUserCreated(message) {
        setIsAddUserOpen(false)
        setNotice(message || 'User account created successfully.')
        request('/admin/dashboard')
            .then(data => {
                setDashboard(data)
                setError('')
            })
            .catch(error => setError(error.message || 'Unable to refresh the admin dashboard.'))
    }

    function handleStudentCreated(message) {
        setIsAddStudentOpen(false)
        setNotice(message || 'Student created successfully.')
        request('/admin/dashboard')
            .then(data => {
                setDashboard(data)
                setError('')
            })
            .catch(error => setError(error.message || 'Unable to refresh the admin dashboard.'))
    }

    function handleProgramCreated(message) {
        setIsAddProgramOpen(false)
        setNotice(message || 'Program created successfully.')
        request('/admin/dashboard')
            .then(data => {
                setDashboard(data)
                setError('')
            })
            .catch(error => setError(error.message || 'Unable to refresh the admin dashboard.'))
    }

    return (
        <div className="admin-dashboard">
            <Sidebar />
            <main className="admin-dashboard-main">
                <Header
                    title="Dashboard"
                    subtitle="Maintain users, programs, students, and system configuration."
                    className="admin-dashboard-header"
                />

                {error && <p className="admin-dashboard-error" role="alert">{error}</p>}
                {notice && <p className="admin-dashboard-notice" role="status">{notice}</p>}

                <div className="admin-dashboard-content">
                    <section className="admin-summary-grid" aria-label="System overview">
                        {cards.map(card => (
                            <article className={`admin-summary-card ${card.tone}`} key={card.label}>
                                <span className="admin-summary-icon" aria-hidden="true"><img src={card.icon} alt="" /></span>
                                <div>
                                    <p>{card.label}</p>
                                    <strong>{dashboard ? card.value : '—'}</strong>
                                    <small>{card.detail}</small>
                                </div>
                            </article>
                        ))}
                    </section>

                    <div className="admin-dashboard-panels">
                        <section className="admin-panel admin-accounts-panel">
                            <div className="admin-panel-heading">
                                <div><h2>Manage System Accounts</h2><p>Create and maintain accounts.</p></div>
                            </div>
                            <div className="admin-account-list">
                                {!dashboard && !error && <div className="admin-account-loading"><LoadingCircle label="Loading accounts…" /></div>}
                                {accountRows.map(account => {
                                    const { icon, tone } = accountRoleIcons[account.role] ?? { icon: ActiveUsersIcon, tone: 'navy' }
                                    const countLabel = account.isStudentRecord
                                        ? (account.count === 1 ? 'student record' : 'student records')
                                        : (account.count === 1 ? 'account' : 'accounts')
                                    return (
                                        <div className="admin-account-row" key={account.role}>
                                            <span className={`admin-account-icon ${tone}`} aria-hidden="true"><img src={icon} alt="" /></span>
                                            <div><strong>{account.label}</strong><small>{account.count} {countLabel}</small></div>
                                            <Link to={account.role === 'student' ? '/admin/students' : '/admin/user-roles'} aria-label={`Manage ${account.label}`}>Manage</Link>
                                        </div>
                                    )
                                })}
                            </div>
                        </section>

                        <section className="admin-panel admin-quick-panel">
                            <div className="admin-panel-heading">
                                <div><h2>Manage System Accounts</h2><p>Administrative changes.</p></div>
                            </div>
                            <div className="admin-quick-grid">
                                <button className="admin-quick-action blue" type="button" onClick={() => { setNotice(''); setIsAddUserOpen(true) }} aria-haspopup="dialog"><span className="admin-quick-icon" aria-hidden="true"><img src={AddUserIcon} alt="" /></span><strong>Add User</strong><small>Create a Secretary, Program Head, or Chancellor.</small><b aria-hidden="true">›</b></button>
                                <button className="admin-quick-action green" type="button" onClick={() => { setNotice(''); setIsAddStudentOpen(true) }} aria-haspopup="dialog"><span className="admin-quick-icon" aria-hidden="true"><img src={AdminStudentsIcon} alt="" /></span><strong>Add Student</strong><small>Create a new student record.</small><b aria-hidden="true">›</b></button>
                                <button className="admin-quick-action purple" type="button" onClick={() => { setNotice(''); setIsAddProgramOpen(true) }} aria-haspopup="dialog"><span className="admin-quick-icon" aria-hidden="true"><img src={AddProgramIcon} alt="" /></span><strong>Add Program</strong><small>Create a new academic program.</small><b aria-hidden="true">›</b></button>
                                <Link className="admin-quick-action navy" to="/admin/system-settings"><span className="admin-quick-icon" aria-hidden="true"><img src={SystemSettingsIcon} alt="" /></span><strong>System Settings</strong><small>Manage system configuration.</small><b aria-hidden="true">›</b></Link>
                            </div>
                        </section>
                    </div>

                    <section className="admin-panel admin-activity-panel">
                        <div className="admin-panel-heading">
                            <div><h2>Recent System Activity</h2><p>Recent administrative actions</p></div>
                            <Link className="admin-activity-view-all" to="/admin/activity-log">View All <span aria-hidden="true">→</span></Link>
                        </div>
                        <ActivityTable activities={dashboard?.recent_activity?.slice(0, 6)} loading={!dashboard && !error} administratorLabel />
                    </section>
                </div>
            </main>
            {isAddUserOpen && <AddUserModal onClose={() => setIsAddUserOpen(false)} onCreated={handleUserCreated} />}
            {isAddStudentOpen && <AddStudentModal onClose={() => setIsAddStudentOpen(false)} onCreated={handleStudentCreated} />}
            {isAddProgramOpen && <AddProgramModal onClose={() => setIsAddProgramOpen(false)} onCreated={handleProgramCreated} />}
        </div>
    )
}

export default AdminDashboard
