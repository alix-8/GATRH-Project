import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import Header from '../../../components/Header/Header'
import Sidebar from '../../../components/Sidebar/Sidebar'
import { request } from '../../../api/request'
import './AdminDashboard.css'

const roleColors = ['purple', 'blue', 'green', 'yellow', 'navy']

function formatDate(value) {
    if (!value) return '—'
    return new Date(value).toLocaleString('en-US', {
        dateStyle: 'medium',
        timeStyle: 'short',
    })
}

function AdminDashboard() {
    const [dashboard, setDashboard] = useState(null)
    const [error, setError] = useState('')

    useEffect(() => {
        request('/admin/dashboard')
            .then(setDashboard)
            .catch(error => setError(error.message || 'Unable to load the admin dashboard.'))
    }, [])

    const summary = dashboard?.summary
    const cards = [
        { label: 'User accounts', value: summary?.user_accounts, detail: 'Accounts across all roles', tone: 'blue', icon: '♟' },
        { label: 'Students', value: summary?.students, detail: 'Registered in programs', tone: 'green', icon: '✦' },
        { label: 'Programs', value: summary?.programs, detail: 'Available programs', tone: 'purple', icon: '▦' },
        { label: 'System status', value: dashboard?.system_status, detail: 'Dashboard services responding', tone: 'navy', icon: '✓' },
    ]

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

                <div className="admin-dashboard-content">
                    <section className="admin-summary-grid" aria-label="System overview">
                        {cards.map(card => (
                            <article className={`admin-summary-card ${card.tone}`} key={card.label}>
                                <span className="admin-summary-icon" aria-hidden="true">{card.icon}</span>
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
                                <Link to="/admin/user-roles">Manage all <span aria-hidden="true">→</span></Link>
                            </div>
                            <div className="admin-account-list">
                                {(dashboard?.accounts ?? []).map((account, index) => (
                                    <div className="admin-account-row" key={account.role}>
                                        <span className={`admin-account-icon ${roleColors[index % roleColors.length]}`} aria-hidden="true">♟</span>
                                        <div><strong>{account.label}</strong><small>{account.count} {account.count === 1 ? 'account' : 'accounts'}</small></div>
                                        <Link to="/admin/user-roles" aria-label={`Manage ${account.label}`}>Manage</Link>
                                    </div>
                                ))}
                                {dashboard && dashboard.accounts.length === 0 && <p className="admin-empty">No user accounts found.</p>}
                            </div>
                        </section>

                        <section className="admin-panel admin-quick-panel">
                            <div className="admin-panel-heading">
                                <div><h2>Quick Actions</h2><p>Common administration tasks.</p></div>
                            </div>
                            <div className="admin-quick-grid">
                                <Link className="admin-quick-action blue" to="/admin/user-roles"><span aria-hidden="true">♟</span><strong>Add User</strong><small>Create an account and assign its role.</small><b aria-hidden="true">›</b></Link>
                                <Link className="admin-quick-action green" to="/admin/students"><span aria-hidden="true">✦</span><strong>Manage Students</strong><small>Review student information.</small><b aria-hidden="true">›</b></Link>
                                <Link className="admin-quick-action purple" to="/admin/programs"><span aria-hidden="true">▦</span><strong>Manage Programs</strong><small>Review and maintain programs.</small><b aria-hidden="true">›</b></Link>
                                <Link className="admin-quick-action navy" to="/admin/settings"><span aria-hidden="true">⚙</span><strong>System Settings</strong><small>Manage system configuration.</small><b aria-hidden="true">›</b></Link>
                            </div>
                        </section>
                    </div>

                    <section className="admin-panel admin-activity-panel">
                        <div className="admin-panel-heading">
                            <div><h2>Recent System Activity</h2><p>Latest recorded account and system changes.</p></div>
                        </div>
                        <div className="admin-activity-table-wrap">
                            <table className="admin-activity-table">
                                <thead><tr><th>Date</th><th>Action</th><th>Target</th><th>Performed by</th></tr></thead>
                                <tbody>
                                    {(dashboard?.recent_activity ?? []).map(activity => (
                                        <tr key={activity.id}>
                                            <td>{formatDate(activity.created_at)}</td>
                                            <td>{activity.action}</td>
                                            <td>{activity.target}{activity.target_id ? ` #${activity.target_id}` : ''}</td>
                                            <td>{activity.performed_by}</td>
                                        </tr>
                                    ))}
                                    {dashboard && dashboard.recent_activity.length === 0 && <tr><td className="admin-empty" colSpan="4">No activity has been recorded yet.</td></tr>}
                                </tbody>
                            </table>
                        </div>
                    </section>
                </div>
            </main>
        </div>
    )
}

export default AdminDashboard
