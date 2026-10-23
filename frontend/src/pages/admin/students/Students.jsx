import { useEffect, useState } from 'react'
import Header from '../../../components/layout/Header'
import Sidebar from '../../../components/layout/Sidebar'
import { request } from '../../../api/request'
import useLiveRefresh from '../../../hooks/useLiveRefresh'
import ActiveUsersIcon from '../../../assets/icons/active-users-icon.svg'
import SecretariesIcon from '../../../assets/icons/secretaries-icon.svg'
import InactiveClockIcon from '../../../assets/icons/in-progress-programs-icon.svg'
import ProgramsIcon from '../../../assets/icons/active-programs-icon.svg'
import EditIcon from '../../../assets/icons/edit-icon.svg'
import ImportIcon from '../../../assets/icons/admin-import-icon.svg'
import ModalSelect from '../dashboard/ModalSelect'
import AddStudentModal from '../dashboard/AddStudentModal'
import LoadingCircle from '../../../components/feedback/LoadingCircle'
import EditStudentModal from './EditStudentModal'
import ImportStudentsModal from './ImportStudentsModal'
import './Students.css'

const programOrder = ['ACT', 'BSIS', 'BSSW', 'BSA', 'BSAIS', 'BAB']
const avatarTones = ['blue', 'pink', 'orange', 'yellow', 'slate', 'purple']

function initials(name) {
    return name.trim().split(/\s+/).slice(0, 2).map(part => part[0]).join('').toUpperCase()
}

function yearLabel(value) {
    const year = Number.parseInt(value, 10)
    return Number.isInteger(year) && year >= 1 && year <= 4
        ? `${year}${['st', 'nd', 'rd', 'th'][year - 1]} Year`
        : value
}

function Students() {
    const [pageData, setPageData] = useState(null)
    const [filters, setFilters] = useState({ program: '', year_level: '', status: '', search: '' })
    const [searchQuery, setSearchQuery] = useState('')
    const [page, setPage] = useState(1)
    const [refreshVersion, setRefreshVersion] = useState(0)
    const [loading, setLoading] = useState(true)
    const [isAddOpen, setIsAddOpen] = useState(false)
    const [isImportOpen, setIsImportOpen] = useState(false)
    const [editingStudent, setEditingStudent] = useState(null)
    const [notice, setNotice] = useState('')
    const [error, setError] = useState('')
    const [pollTick, setPollTick] = useState(0)

    useLiveRefresh(() => {
        if (!isAddOpen && !isImportOpen && !editingStudent) setPollTick((current) => current + 1)
    }, 30_000)

    useEffect(() => {
        const timeout = window.setTimeout(() => setSearchQuery(filters.search.trim()), 300)
        return () => window.clearTimeout(timeout)
    }, [filters.search])

    useEffect(() => {
        const controller = new AbortController()
        const params = new URLSearchParams({ per_page: '100', page: String(page) })
        if (filters.program) params.set('program', filters.program)
        if (filters.year_level) params.set('year_level', filters.year_level)
        if (filters.status) params.set('status', filters.status)
        if (searchQuery) params.set('search', searchQuery)

        request(`/admin/students?${params}`, { signal: controller.signal })
            .then(data => {
                setPageData(data)
                setError('')
            })
            .catch(error => {
                if (error.name !== 'AbortError') setError(error.message || 'Unable to load students.')
            })
            .finally(() => { if (!controller.signal.aborted) setLoading(false) })
        return () => controller.abort()
    }, [filters.program, filters.year_level, filters.status, searchQuery, page, refreshVersion, pollTick])

    const summary = pageData?.summary
    const students = pageData?.students
    const orderedPrograms = [...(pageData?.programs ?? [])].sort((a, b) => {
        const aIndex = programOrder.indexOf(a.code)
        const bIndex = programOrder.indexOf(b.code)
        if (aIndex === -1 && bIndex === -1) return a.code.localeCompare(b.code)
        if (aIndex === -1) return 1
        if (bIndex === -1) return -1
        return aIndex - bIndex
    })
    const yearLevels = ['1', '2', '3', '4']
    const percentage = count => `${summary?.total ? ((count / summary.total) * 100).toFixed(1) : '0.0'}% of total`
    const cards = [
        { label: 'Total Students', value: summary?.total, detail: 'Across all programs', tone: 'blue', icon: ActiveUsersIcon },
        { label: 'Active Students', value: summary?.active, detail: percentage(summary?.active ?? 0), tone: 'green', icon: SecretariesIcon },
        { label: 'Inactive Students', value: summary?.inactive, detail: percentage(summary?.inactive ?? 0), tone: 'yellow', icon: InactiveClockIcon },
        { label: 'Programs', value: summary?.programs, detail: 'Assigned Programs', tone: 'purple', icon: ProgramsIcon },
    ]

    function updateFilter(field, value) {
        setFilters(current => ({ ...current, [field]: value }))
        setPage(1)
        setLoading(true)
    }

    function refresh(message) {
        setNotice(message)
        setRefreshVersion(current => current + 1)
        setLoading(true)
    }

    return (
        <div className="admin-students">
            <Sidebar />
            <main className="admin-students-main">
                <Header
                    title="Students"
                    subtitle="Manage student records, program assignments, and account status."
                    showNotification={false}
                    className="admin-students-header"
                />
                <div className="admin-students-content">
                    {error && <p className="admin-students-error" role="alert">{error}</p>}
                    {notice && <p className="admin-students-notice" role="status">{notice}</p>}
                    <section className="admin-student-cards" aria-label="Student overview" aria-busy={!summary && !error}>
                        {cards.map(card => <article className={`admin-student-card ${card.tone}`} key={card.label}>
                            <span className="admin-student-card-icon" aria-hidden="true"><img src={card.icon} alt="" /></span>
                            <div>
                                <h2>{card.label}</h2>
                                <strong>{summary ? card.value : '—'}</strong>
                                <p>{card.detail}</p>
                            </div>
                        </article>)}
                    </section>
                    <section className="admin-students-panel" aria-label="Student records">
                        <div className="admin-students-filter-bar">
                            <div className="admin-students-filter-controls">
                                <div className="admin-students-select program"><ModalSelect aria-label="Filter by program" value={filters.program} onChange={event => updateFilter('program', event.target.value)}><option value="">All Programs</option>{orderedPrograms.map(program => <option key={program.id} value={program.code}>{program.code}</option>)}</ModalSelect></div>
                                <div className="admin-students-select year"><ModalSelect aria-label="Filter by year level" value={filters.year_level} onChange={event => updateFilter('year_level', event.target.value)}><option value="">All Year Levels</option>{yearLevels.map(year => <option key={year} value={year}>{yearLabel(year)}</option>)}</ModalSelect></div>
                                <div className="admin-students-select status"><ModalSelect aria-label="Filter by status" value={filters.status} onChange={event => updateFilter('status', event.target.value)}><option value="">All Statuses</option><option value="active">Active</option><option value="inactive">Inactive</option></ModalSelect></div>
                                <label className="admin-students-search"><svg viewBox="0 0 20 20" fill="none" aria-hidden="true"><circle cx="8.7" cy="8.7" r="5.3" stroke="currentColor" strokeWidth="1.6" /><path d="m12.7 12.7 4 4" stroke="currentColor" strokeWidth="1.6" strokeLinecap="round" /></svg><input type="search" aria-label="Search students by full name" placeholder="Search full name..." value={filters.search} onChange={event => updateFilter('search', event.target.value)} /></label>
                            </div>
                            <div className="admin-students-actions">
                                <button className="admin-students-add-button" type="button" onClick={() => { setNotice(''); setIsAddOpen(true) }} aria-haspopup="dialog"><span aria-hidden="true">+</span> Add Student</button>
                                <button className="admin-students-import-button" type="button" onClick={() => { setNotice(''); setIsImportOpen(true) }} aria-haspopup="dialog"><img src={ImportIcon} alt="" />Import</button>
                            </div>
                        </div>
                        <div className="admin-students-table-wrap" role="region" aria-label="Student records table" tabIndex="0" aria-busy={loading}>
                            <table className="admin-students-table">
                                <thead><tr><th scope="col">Full Name</th><th scope="col">Program</th><th scope="col">Year Level</th><th scope="col">Status</th><th scope="col">Actions</th></tr></thead>
                                <tbody>
                                    {loading ? <tr><td colSpan="5" className="admin-students-table-state"><LoadingCircle label="Loading students…" /></td></tr>
                                        : (students?.data ?? []).length === 0 ? <tr><td colSpan="5" className="admin-students-table-state">No students match these filters.</td></tr>
                                            : students.data.map(student => <tr key={student.id}>
                                                <td><span className="admin-students-name"><span className={`admin-students-avatar ${avatarTones[student.id % avatarTones.length]}`} aria-hidden="true">{initials(student.name)}</span><strong>{student.name}</strong></span></td>
                                                <td>{student.program}</td>
                                                <td>{yearLabel(student.year_level)}</td>
                                                <td><span className={`admin-students-status ${student.is_active ? 'active' : 'inactive'}`}>{student.is_active ? 'Active' : 'Inactive'}</span></td>
                                                <td><button className="admin-students-edit-button" type="button" aria-label={`Edit ${student.name}`} onClick={() => setEditingStudent(student)}><img src={EditIcon} alt="" /></button></td>
                                            </tr>)}
                                </tbody>
                            </table>
                        </div>
                        {students && students.last_page > 1 && <nav className="admin-students-pagination" aria-label="Student list pages"><button type="button" disabled={page <= 1 || loading} onClick={() => { setPage(current => current - 1); setLoading(true) }}>Previous</button><span>Page {students.current_page} of {students.last_page}</span><button type="button" disabled={page >= students.last_page || loading} onClick={() => { setPage(current => current + 1); setLoading(true) }}>Next</button></nav>}
                    </section>
                </div>
            </main>
            {isAddOpen && <AddStudentModal onClose={() => setIsAddOpen(false)} onCreated={message => { setIsAddOpen(false); refresh(message || 'Student created successfully.') }} />}
            {isImportOpen && <ImportStudentsModal programs={orderedPrograms} onClose={() => setIsImportOpen(false)} onImported={message => { setIsImportOpen(false); refresh(message || 'Students imported successfully.') }} />}
            {editingStudent && <EditStudentModal student={editingStudent} programs={orderedPrograms} onClose={() => setEditingStudent(null)} onUpdated={message => { setEditingStudent(null); refresh(message || 'Student updated successfully.') }} />}
        </div>
    )
}

export default Students
