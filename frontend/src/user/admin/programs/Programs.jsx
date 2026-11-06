import { useEffect, useState } from 'react'
import Header from '../../../components/layout/Header'
import Sidebar from '../../../components/layout/Sidebar'
import LoadingCircle from '../../../components/feedback/LoadingCircle'
import ModalSelect from '../shared/ModalSelect'
import { request } from '../../../api/request'
import useLiveRefresh from '../../../hooks/useLiveRefresh'
import useAutoDismissNotice from '../../../hooks/useAutoDismissNotice'
import TotalProgramsIcon from '../../../assets/icons/active-programs-icon.svg'
import ProgramHeadsIcon from '../../../assets/icons/active-users-icon.svg'
import ActiveProgramIcon from '../../../assets/icons/active-program-icon.svg'
import InactiveProgramIcon from '../../../assets/icons/inactive-program-icon.svg'
import EditIcon from '../../../assets/icons/edit-icon.svg'
import AddProgramModal from '../modals/programs/AddProgramModal'
import EditProgramModal from '../modals/programs/EditProgramModal'
import './Programs.css'

const programOrder = ['ACT', 'BSIS', 'BSSW', 'BSA', 'BSAIS', 'BAB']
const programTones = { BSIS: 'red', ACT: 'orange', BSA: 'yellow', BSAIS: 'yellow', BAB: 'blue', BSSW: 'purple' }
const headTones = ['blue', 'pink', 'green', 'yellow', 'purple']

function initials(name) {
    return name.trim().split(/\s+/).slice(0, 2).map(part => part[0]).join('').toUpperCase()
}

function Programs() {
    const [pageData, setPageData] = useState(null)
    const [filters, setFilters] = useState({ program: '', status: 'active', search: '' })
    const [searchQuery, setSearchQuery] = useState('')
    const [page, setPage] = useState(1)
    const [refreshVersion, setRefreshVersion] = useState(0)
    const [isAddOpen, setIsAddOpen] = useState(false)
    const [editingProgram, setEditingProgram] = useState(null)
    const [loading, setLoading] = useState(true)
    const [notice, showNotice, clearNotice] = useAutoDismissNotice()
    const [error, setError] = useState('')
    const [pollTick, setPollTick] = useState(0)

    useLiveRefresh(() => {
        if (!isAddOpen && !editingProgram) setPollTick((current) => current + 1)
    }, 30_000)

    useEffect(() => {
        const timeout = window.setTimeout(() => setSearchQuery(filters.search.trim()), 300)
        return () => window.clearTimeout(timeout)
    }, [filters.search])

    useEffect(() => {
        const controller = new AbortController()
        const params = new URLSearchParams({ page: String(page) })
        if (filters.program) params.set('program', filters.program)
        if (filters.status) params.set('status', filters.status)
        if (searchQuery) params.set('search', searchQuery)

        request(`/admin/programs?${params}`, { signal: controller.signal })
            .then(data => {
                setPageData(data)
                setError('')
            })
            .catch(error => {
                if (error.name !== 'AbortError') setError(error.message || 'Unable to load program counts.')
            })
            .finally(() => { if (!controller.signal.aborted) setLoading(false) })

        return () => controller.abort()
    }, [filters.program, filters.status, searchQuery, page, refreshVersion, pollTick])

    const summary = pageData?.summary
    const programs = pageData?.programs
    const orderedPrograms = [...(pageData?.program_options ?? [])].sort((a, b) => {
        const aIndex = programOrder.indexOf(a.code)
        const bIndex = programOrder.indexOf(b.code)
        if (aIndex === -1 && bIndex === -1) return a.code.localeCompare(b.code)
        if (aIndex === -1) return 1
        if (bIndex === -1) return -1
        return aIndex - bIndex
    })
    const cards = [
        { label: 'Total Programs', value: summary?.total, detail: 'Across all programs', tone: 'purple', icon: TotalProgramsIcon },
        { label: 'Program Heads', value: summary?.program_heads, detail: 'Assigned Program Heads', tone: 'blue', icon: ProgramHeadsIcon },
        { label: 'Active Programs', value: summary?.active, detail: summary && summary.total === summary.active ? 'All programs are active' : 'Currently active programs', tone: 'green', icon: ActiveProgramIcon },
        { label: 'Inactive Programs', value: summary?.inactive, detail: summary && summary.inactive === 0 ? 'No inactive programs' : 'Currently inactive programs', tone: 'yellow', icon: InactiveProgramIcon },
    ]

    function updateFilter(field, value) {
        setFilters(current => ({ ...current, [field]: value }))
        setPage(1)
        setLoading(true)
    }

    function refreshPrograms(message, updatedCode) {
        if (updatedCode && filters.program === editingProgram?.code) {
            setFilters(current => ({ ...current, program: updatedCode }))
        }
        setIsAddOpen(false)
        setEditingProgram(null)
        showNotice(message)
        setRefreshVersion(current => current + 1)
        setLoading(true)
    }

    return (
        <div className="admin-programs">
            <Sidebar />
            <main className="admin-programs-main">
                <Header
                    title="Programs"
                    subtitle="Manage student records, program assignments, and account status."
                    showNotification={false}
                    className="admin-programs-header"
                />
                <div className="admin-programs-content">
                    {error && <p className="admin-programs-error" role="alert">{error}</p>}
                    {notice && <p className="admin-programs-notice" role="status">{notice}</p>}
                    <section className="admin-programs-cards" aria-label="Program overview" aria-busy={!summary && !error}>
                        {cards.map(card => (
                            <article className={`admin-programs-card ${card.tone}`} key={card.label}>
                                <span className="admin-programs-card-icon" aria-hidden="true"><img src={card.icon} alt="" /></span>
                                <div>
                                    <h2>{card.label}</h2>
                                    <strong>{summary ? card.value : '—'}</strong>
                                    <p>{card.detail}</p>
                                </div>
                            </article>
                        ))}
                    </section>
                    <section className="admin-programs-panel" aria-label="Program list">
                        <h2>Program List</h2>
                        <div className="admin-programs-filter-bar">
                            <div className="admin-programs-filter-controls">
                                <label className="admin-programs-select program">
                                    <ModalSelect aria-label="Filter by program" value={filters.program} onChange={event => updateFilter('program', event.target.value)}>
                                        <option value="">All Programs</option>
                                        {orderedPrograms.map(program => <option key={program.id} value={program.code}>{program.code}</option>)}
                                    </ModalSelect>
                                </label>
                                <label className="admin-programs-select">
                                    <ModalSelect aria-label="Filter by status" value={filters.status} onChange={event => updateFilter('status', event.target.value)}>
                                        <option value="active">Active</option>
                                        <option value="inactive">Inactive</option>
                                    </ModalSelect>
                                </label>
                                <label className="admin-programs-search">
                                    <svg viewBox="0 0 20 20" fill="none" aria-hidden="true"><circle cx="8.7" cy="8.7" r="5.3" stroke="currentColor" strokeWidth="1.6" /><path d="m12.7 12.7 4 4" stroke="currentColor" strokeWidth="1.6" strokeLinecap="round" /></svg>
                                    <input type="search" aria-label="Search programs by name or ID" placeholder="Search name, ID..." maxLength={100} value={filters.search} onChange={event => updateFilter('search', event.target.value)} />
                                </label>
                            </div>
                            <button className="admin-programs-add-button" type="button" onClick={() => { clearNotice(); setIsAddOpen(true) }} aria-haspopup="dialog"><span aria-hidden="true">+</span> Add Program</button>
                        </div>
                        <div className="admin-programs-table-wrap" role="region" aria-label="Programs table" tabIndex="0" aria-busy={loading}>
                            <table className="admin-programs-table">
                                <thead><tr><th scope="col">Program Name</th><th scope="col">Program Code</th><th scope="col">Program Head</th><th scope="col">Students</th><th scope="col">Status</th><th scope="col">Actions</th></tr></thead>
                                <tbody>
                                    {loading ? <tr><td colSpan="6" className="admin-programs-table-state"><LoadingCircle label="Loading programs…" /></td></tr>
                                        : (programs?.data ?? []).length === 0 ? <tr><td colSpan="6" className="admin-programs-table-state">No programs match these filters.</td></tr>
                                            : programs.data.map(program => {
                                                const heads = program.program_heads ?? []
                                                return <tr key={program.id}>
                                                    <td><span className="admin-programs-name"><span className={`admin-programs-code-icon ${programTones[program.code] ?? 'slate'}`} aria-hidden="true">{program.code.slice(0, 5)}</span><span><strong>{program.code}</strong><small>{program.name}</small></span></span></td>
                                                    <td className="admin-programs-code">{program.code}</td>
                                                    <td><span className="admin-programs-heads">{heads.length ? heads.map(head => <span className="admin-programs-head" key={head.id}><span className={`admin-programs-head-avatar ${headTones[head.id % headTones.length]}`} aria-hidden="true">{initials(head.name)}</span><span>{head.name}</span></span>) : 'Unassigned'}</span></td>
                                                    <td>{program.students_count ?? 0}</td>
                                                    <td><span className={`admin-programs-status ${program.is_active ? 'active' : 'inactive'}`}>{program.is_active ? 'Active' : 'Inactive'}</span></td>
                                                    <td><button className="admin-programs-edit-button" type="button" aria-label={`Edit ${program.name}`} onClick={() => setEditingProgram(program)}><img src={EditIcon} alt="" /></button></td>
                                                </tr>
                                            })}
                                </tbody>
                            </table>
                        </div>
                        {programs?.last_page > 1 && <nav className="admin-programs-table-footer" aria-label="Program list pages"><button type="button" disabled={page <= 1 || loading} onClick={() => { setPage(current => current - 1); setLoading(true) }}>Previous</button><span>Page {programs.current_page} of {programs.last_page}</span><button type="button" disabled={page >= programs.last_page || loading} onClick={() => { setPage(current => current + 1); setLoading(true) }}>Next</button></nav>}
                    </section>
                </div>
            </main>
            {isAddOpen && <AddProgramModal onClose={() => setIsAddOpen(false)} onCreated={message => refreshPrograms(message || 'Program created successfully.')} />}
            {editingProgram && <EditProgramModal program={editingProgram} heads={pageData?.program_head_options ?? []} onClose={() => setEditingProgram(null)} onUpdated={(message, code) => refreshPrograms(message || 'Program updated successfully.', code)} />}
        </div>
    )
}

export default Programs
