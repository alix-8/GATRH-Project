import { useEffect, useState } from 'react'
import Header from '../../../components/layout/Header'
import Sidebar from '../../../components/layout/Sidebar'
import { request } from '../../../api/request'
import ChancellorIcon from '../../../assets/icons/chancellor-icon.svg'
import McgiCoordinatorIcon from '../../../assets/icons/mcgi-coordinator-icon.svg'
import ProgramHeadIcon from '../../../assets/icons/program-head-icon.svg'
import SecretariesIcon from '../../../assets/icons/secretaries-icon.svg'
import './UserRoles.css'

const roleCards = [
    { label: 'Chancellor', roles: ['chancellor'], detail: 'System Oversight', tone: 'purple', icon: ChancellorIcon },
    { label: 'MCGI Coordinator', roles: ['cm_coordinator'], detail: 'System Oversight and Monitoring', tone: 'navy', icon: McgiCoordinatorIcon },
    { label: 'Program Head', roles: ['program_head'], detail: 'Oversees programs and reviews records', tone: 'blue', icon: ProgramHeadIcon },
    { label: 'Secretaries', roles: ['secretary'], detail: 'Manages Attendance', tone: 'green', icon: SecretariesIcon },
]

function UserRoles() {
    const [summary, setSummary] = useState(null)
    const [error, setError] = useState('')

    useEffect(() => {
        let active = true
        request('/admin/users?per_page=1')
            .then(data => {
                if (active) setSummary(data.summary)
            })
            .catch(error => {
                if (active) setError(error.message || 'Unable to load role counts.')
            })
        return () => { active = false }
    }, [])

    const roleCounts = Object.fromEntries((summary?.by_role ?? []).map(role => [role.role, role.count]))

    return (
        <div className="user-roles">
            <Sidebar />
            <main className="user-roles-main">
                <Header
                    title="User & Roles"
                    subtitle="Manage system users, assign roles, and control access permissions."
                    className="user-roles-header"
                />
                <div className="user-roles-content">
                    {error && <p className="user-roles-error" role="alert">{error}</p>}
                    <section className="user-role-cards" aria-label="Active accounts by role" aria-busy={!summary && !error}>
                        {roleCards.map(card => (
                            <article className={`user-role-card ${card.tone}`} key={card.label}>
                                <span className="user-role-card-icon" aria-hidden="true">
                                    <img className="user-role-card-glyph" src={card.icon} alt="" />
                                </span>
                                <div className="user-role-card-copy">
                                    <h2>{card.label}</h2>
                                    <strong>{summary ? card.roles.reduce((total, role) => total + (roleCounts[role] ?? 0), 0) : '—'}</strong>
                                    <p>{card.detail}</p>
                                </div>
                            </article>
                        ))}
                    </section>
                </div>
            </main>
        </div>
    )
}

export default UserRoles
