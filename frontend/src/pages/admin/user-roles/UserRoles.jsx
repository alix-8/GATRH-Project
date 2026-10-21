import { useEffect, useState } from 'react'
import Header from '../../../components/layout/Header'
import Sidebar from '../../../components/layout/Sidebar'
import { request } from '../../../api/request'
import ChancellorIcon from '../../../assets/icons/chancellor-icon.svg'
import McgiCoordinatorIcon from '../../../assets/icons/mcgi-coordinator-icon.svg'
import ProgramHeadIcon from '../../../assets/icons/program-head-icon.svg'
import SecretariesIcon from '../../../assets/icons/secretaries-icon.svg'
import EditIcon from '../../../assets/icons/edit-icon.svg'
import AddUserModal from '../dashboard/AddUserModal'
import ModalSelect from '../dashboard/ModalSelect'
import LoadingCircle from '../../../components/feedback/LoadingCircle'
import EditUserModal from './EditUserModal'
import './UserRoles.css'

const programOrder = ['ACT', 'BSIS', 'BSSW', 'BSA', 'BSAIS', 'BAB']

const roleCards = [
    { label: 'Chancellor', roles: ['chancellor'], detail: 'System Oversight', tone: 'purple', icon: ChancellorIcon },
    { label: 'MCGI Coordinator', roles: ['cm_coordinator'], detail: 'System Oversight and Monitoring', tone: 'navy', icon: McgiCoordinatorIcon },
    { label: 'Program Head', roles: ['program_head'], detail: 'Oversees programs and reviews records', tone: 'blue', icon: ProgramHeadIcon },
    { label: 'Secretaries', roles: ['secretary'], detail: 'Manages Attendance', tone: 'green', icon: SecretariesIcon },
]

const avatarTones = ['blue', 'pink', 'orange', 'yellow', 'slate', 'purple']

function initials(name) {
    return name.trim().split(/\s+/).slice(0, 2).map(part => part[0]).join('').toUpperCase()
}

function roleLabel(role) {
    return {
        chancellor: 'Chancellor',
        cm_coordinator: 'Coordinator',
        program_head: 'Program Head',
        program_secretary: 'Program Secretary',
        secretary: 'Secretary',
        admin: 'Administrator',
    }[role] ?? role.replaceAll('_', ' ')
}

function UserRoles() {
    const [pageData, setPageData] = useState(null)
    const [filters, setFilters] = useState({ role: '', program: '', status: '', search: '' })
    const [searchQuery, setSearchQuery] = useState('')
    const [page, setPage] = useState(1)
    const [refreshVersion, setRefreshVersion] = useState(0)
    const [loading, setLoading] = useState(true)
    const [isAddUserOpen, setIsAddUserOpen] = useState(false)
    const [editingUser, setEditingUser] = useState(null)
    const [notice, setNotice] = useState('')
    const [error, setError] = useState('')

    useEffect(() => {
        const timeout = window.setTimeout(() => setSearchQuery(filters.search.trim()), 300)
        return () => window.clearTimeout(timeout)
    }, [filters.search])

    useEffect(() => {
        let active = true
        const params = new URLSearchParams({ per_page: '100', page: String(page) })
        if (filters.role) params.set('role', filters.role)
        if (filters.program) params.set('program', filters.program)
        if (filters.status) params.set('status', filters.status)
        if (searchQuery) params.set('search', searchQuery)

        request(`/admin/users?${params}`)
            .then(data => {
                if (active) {
                    setPageData(data)
                    setError('')
                }
            })
            .catch(error => {
                if (active) setError(error.message || 'Unable to load role counts.')
            })
            .finally(() => {
                if (active) setLoading(false)
            })
        return () => { active = false }
    }, [filters.role, filters.program, filters.status, searchQuery, page, refreshVersion])

    const summary = pageData?.summary
    const users = pageData?.users
    const roleCounts = Object.fromEntries((summary?.by_role ?? []).map(role => [role.role, role.count]))
    const orderedPrograms = [...(pageData?.programs ?? [])].sort((a, b) => {
        const aIndex = programOrder.indexOf(a.code)
        const bIndex = programOrder.indexOf(b.code)
        if (aIndex === -1 && bIndex === -1) return a.code.localeCompare(b.code)
        if (aIndex === -1) return 1
        if (bIndex === -1) return -1
        return aIndex - bIndex
    })

    function updateFilter(field, value) {
        setFilters(current => ({ ...current, [field]: value }))
        setPage(1)
        setLoading(true)
    }

    function handleUserCreated(message) {
        setIsAddUserOpen(false)
        setNotice(message || 'User account created successfully.')
        setRefreshVersion(current => current + 1)
        setLoading(true)
    }

    function handleUserUpdated(message) {
        setEditingUser(null)
        setNotice(message || 'User account updated successfully.')
        setRefreshVersion(current => current + 1)
        setLoading(true)
    }

    return (
        <div className="user-roles">
            <Sidebar />
            <main className="user-roles-main">
                <Header
                    title="User & Roles"
                    subtitle="Manage system users, assign roles, and control access permissions."
                    showNotification={false}
                    className="user-roles-header"
                />
                <div className="user-roles-content">
                    {error && <p className="user-roles-error" role="alert">{error}</p>}
                    {notice && <p className="user-roles-notice" role="status">{notice}</p>}
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
                    <section className="user-roles-account-panel" aria-label="User accounts">
                    <div className="user-roles-filter-bar" aria-label="Filter user accounts">
                        <div className="user-roles-filter-controls">
                            <div className="user-roles-select role">
                                <ModalSelect aria-label="Filter by role" value={filters.role} onChange={event => updateFilter('role', event.target.value)}>
                                    <option value="">All Roles</option>
                                    {(pageData?.roles ?? []).map(role => <option key={role.value} value={role.value}>{role.label}</option>)}
                                </ModalSelect>
                            </div>
                            <div className="user-roles-select program">
                                <ModalSelect aria-label="Filter by program" value={filters.program} onChange={event => updateFilter('program', event.target.value)}>
                                    <option value="">All Programs</option>
                                    {orderedPrograms.map(program => <option key={program.id} value={program.code}>{program.code}</option>)}
                                </ModalSelect>
                            </div>
                            <div className="user-roles-select status">
                                <ModalSelect aria-label="Filter by account status" value={filters.status} onChange={event => updateFilter('status', event.target.value)}>
                                    <option value="">All Statuses</option>
                                    <option value="active">Active</option>
                                    <option value="inactive">Inactive</option>
                                </ModalSelect>
                            </div>
                            <label className="user-roles-search">
                                <svg viewBox="0 0 20 20" fill="none" aria-hidden="true"><circle cx="8.7" cy="8.7" r="5.3" stroke="currentColor" strokeWidth="1.6" /><path d="m12.7 12.7 4 4" stroke="currentColor" strokeWidth="1.6" strokeLinecap="round" /></svg>
                                <input type="search" aria-label="Search users by name, email, or ID" placeholder="Search name, ID..." value={filters.search} onChange={event => updateFilter('search', event.target.value)} />
                            </label>
                        </div>
                        <button className="user-roles-add-button" type="button" onClick={() => { setNotice(''); setIsAddUserOpen(true) }} aria-haspopup="dialog"><span aria-hidden="true">+</span> Add User</button>
                    </div>
                    <div className="user-roles-results" aria-busy={loading}>
                        <div className="user-roles-table-wrap" role="region" aria-label="User accounts table" tabIndex="0">
                            <table className="user-roles-table">
                                <thead><tr><th scope="col">Name</th><th scope="col">Email</th><th scope="col">Role</th><th scope="col">Program</th><th scope="col">Status</th><th scope="col">Edit</th></tr></thead>
                                <tbody>
                                    {loading ? <tr><td colSpan="6" className="user-roles-table-state"><LoadingCircle label="Loading users…" /></td></tr>
                                        : (users?.data ?? []).length === 0 ? <tr><td colSpan="6" className="user-roles-table-state">No users match these filters.</td></tr>
                                            : users.data.map(user => <tr key={user.id}>
                                                <td><span className="user-roles-name"><span className={`user-roles-avatar ${avatarTones[user.id % avatarTones.length]}`} aria-hidden="true">{initials(user.name)}</span><strong>{user.name}</strong></span></td>
                                                <td className="user-roles-email">{user.email}</td>
                                                <td><span className={`user-roles-role ${user.role}`}>{roleLabel(user.role)}</span></td>
                                                <td className="user-roles-program">{user.programs?.length ? user.programs.map(program => program.code).join(', ') : (user.program || '---')}</td>
                                                <td><span className={`user-roles-status ${user.is_active ? 'active' : 'inactive'}`}>{user.is_active ? 'Active' : 'Inactive'}</span></td>
                                                <td><button className="user-roles-edit-button" type="button" aria-label={`Edit ${user.name}`} onClick={() => setEditingUser(user)}><img src={EditIcon} alt="" /></button></td>
                                            </tr>)}
                                </tbody>
                            </table>
                        </div>
                        {users && users.last_page > 1 && <nav className="user-roles-pagination" aria-label="User list pages">
                            <button type="button" disabled={page <= 1 || loading} onClick={() => { setPage(current => current - 1); setLoading(true) }}>Previous</button>
                            <span>Page {users.current_page} of {users.last_page}</span>
                            <button type="button" disabled={page >= users.last_page || loading} onClick={() => { setPage(current => current + 1); setLoading(true) }}>Next</button>
                        </nav>}
                    </div>
                    </section>
                </div>
            </main>
            {isAddUserOpen && <AddUserModal onClose={() => setIsAddUserOpen(false)} onCreated={handleUserCreated} />}
            {editingUser && <EditUserModal user={editingUser} roles={pageData?.roles ?? []} programs={orderedPrograms} onClose={() => setEditingUser(null)} onUpdated={handleUserUpdated} />}
        </div>
    )
}

export default UserRoles
