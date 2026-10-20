import { useEffect, useState } from 'react'
import Header from '../../../components/layout/Header'
import Sidebar from '../../../components/layout/Sidebar'
import { request } from '../../../api/request'
import TotalProgramsIcon from '../../../assets/icons/active-programs-icon.svg'
import ProgramHeadsIcon from '../../../assets/icons/active-users-icon.svg'
import ActiveProgramIcon from '../../../assets/icons/active-program-icon.svg'
import InactiveProgramIcon from '../../../assets/icons/inactive-program-icon.svg'
import AddProgramModal from '../dashboard/AddProgramModal'
import './Programs.css'

const programOrder = ['ACT', 'BSIS', 'BSSW', 'BSA', 'BSAIS', 'BAB']

function Programs() {
    const [pageData, setPageData] = useState(null)
    const [filters, setFilters] = useState({ program: '', status: '', search: '' })
    const [searchQuery, setSearchQuery] = useState('')
    const [refreshVersion, setRefreshVersion] = useState(0)
    const [isAddOpen, setIsAddOpen] = useState(false)
    const [loading, setLoading] = useState(true)
    const [notice, setNotice] = useState('')
    const [error, setError] = useState('')

    useEffect(() => {
        const timeout = window.setTimeout(() => setSearchQuery(filters.search.trim()), 300)
        return () => window.clearTimeout(timeout)
    }, [filters.search])

    useEffect(() => {
        let active = true
        const params = new URLSearchParams()
        if (filters.program) params.set('program', filters.program)
        if (filters.status) params.set('status', filters.status)
        if (searchQuery) params.set('search', searchQuery)

        request(`/admin/programs?${params}`)
            .then(data => {
                if (active) {
                    setPageData(data)
                    setError('')
                }
            })
            .catch(error => {
                if (active) setError(error.message || 'Unable to load program counts.')
            })
            .finally(() => { if (active) setLoading(false) })

        return () => { active = false }
    }, [filters.program, filters.status, searchQuery, refreshVersion])

    const summary = pageData?.summary
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
        setLoading(true)
    }

    function handleProgramCreated(message) {
        setIsAddOpen(false)
        setNotice(message || 'Program created successfully.')
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
                                    <select aria-label="Filter by program" value={filters.program} onChange={event => updateFilter('program', event.target.value)}>
                                        <option value="">All Programs</option>
                                        {orderedPrograms.map(program => <option key={program.id} value={program.code}>{program.code}</option>)}
                                    </select>
                                </label>
                                <label className="admin-programs-select">
                                    <select aria-label="Filter by status" value={filters.status} onChange={event => updateFilter('status', event.target.value)}>
                                        <option value="">All Statuses</option>
                                        <option value="active">Active</option>
                                        <option value="inactive">Inactive</option>
                                    </select>
                                </label>
                                <label className="admin-programs-search">
                                    <svg viewBox="0 0 20 20" fill="none" aria-hidden="true"><circle cx="8.7" cy="8.7" r="5.3" stroke="currentColor" strokeWidth="1.6" /><path d="m12.7 12.7 4 4" stroke="currentColor" strokeWidth="1.6" strokeLinecap="round" /></svg>
                                    <input type="search" aria-label="Search programs by name or ID" placeholder="Search name, ID..." maxLength={100} value={filters.search} onChange={event => updateFilter('search', event.target.value)} />
                                </label>
                            </div>
                            <button className="admin-programs-add-button" type="button" onClick={() => { setNotice(''); setIsAddOpen(true) }} aria-haspopup="dialog"><span aria-hidden="true">+</span> Add Program</button>
                        </div>
                        <p className="admin-programs-result-count" role="status" aria-live="polite">{loading ? 'Loading programs…' : error ? 'Unable to load programs.' : `${pageData?.programs?.total ?? 0} ${pageData?.programs?.total === 1 ? 'program' : 'programs'} found`}</p>
                    </section>
                </div>
            </main>
            {isAddOpen && <AddProgramModal onClose={() => setIsAddOpen(false)} onCreated={handleProgramCreated} />}
        </div>
    )
}

export default Programs
