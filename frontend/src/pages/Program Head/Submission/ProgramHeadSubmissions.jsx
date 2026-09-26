import { useCallback, useEffect, useMemo, useState } from 'react'
import Sidebar from '../../../components/Sidebar/Sidebar'
import Header from '../../../components/Header/Header'
import { request } from '../../../api/request'
import CalendarIcon from '../../../assets/calendar-icon.png'
import GatheringIcon from '../../../assets/gathering-icon.png'
import YearIcon from '../../../assets/level-icon.png'
import StatusIcon from '../../../assets/completed-icon.png'
import SearchIcon from '../../../assets/search-icon.png'
import './ProgramHeadSubmissions.css'
import SubmissionsTable from './SubmissionComponents/SubmissionsTable'
import SubmissionDrawer from './SubmissionComponents/SubmissionDrawer'

const GATHERINGS = [
    { value: '', label: 'Gathering' },
    { value: 'pm', label: 'Prayer Meeting' },
    { value: 'ws', label: 'Worship Service' },
    { value: 'tg', label: 'Thanksgiving' },
    { value: 'special', label: 'Special Gathering' },
]

const YEARS = [
    { value: '', label: 'Year' },
    { value: '1', label: '1st Year' },
    { value: '2', label: '2nd Year' },
    { value: '3', label: '3rd Year' },
    { value: '4', label: '4th Year' },
]

const STATUSES = [
    { value: '', label: 'Status' },
    { value: 'pending', label: 'Pending' },
    { value: 'approved', label: 'Approved' },
    { value: 'rejected', label: 'Rejected' },
]

function ProgramHeadSubmissions() {
    const [rows, setRows] = useState([])
    const [pendingCount, setPendingCount] = useState(0)
    const [meta, setMeta] = useState({ current_page: 1, last_page: 1, total: 0 })
    const [filters, setFilters] = useState({ date: '', gathering_type: '', year_level: '', status: '', search: '' })
    const [page, setPage] = useState(1)
    const [loading, setLoading] = useState(true)
    const [error, setError] = useState('')
    const [activeSubmission, setActiveSubmission] = useState(null)
    const [refresh, setRefresh] = useState(0)

    const params = useMemo(() => {
        const values = new URLSearchParams({ page: String(page), per_page: '5' })
        Object.entries(filters).forEach(([key, value]) => value && values.set(key, value))
        return values.toString()
    }, [filters, page])

    const loadSubmissions = useCallback(async (signal) => {
        setLoading(true)
        setError('')
        try {
            const result = await request(`/program-head/submissions?${params}`, { signal })
            setRows(Array.isArray(result.data) ? result.data : [])
            setPendingCount(result.pending_count ?? 0)
            setMeta(result.meta ?? { current_page: 1, last_page: 1, total: 0 })
        } catch (requestError) {
            if (requestError.name !== 'AbortError') setError(requestError.message || 'Could not load submissions.')
        } finally {
            if (!signal?.aborted) setLoading(false)
        }
    }, [params])

    useEffect(() => {
        const controller = new AbortController()
        loadSubmissions(controller.signal)
        return () => controller.abort()
    }, [loadSubmissions, refresh])

    const updateFilter = (key, value) => {
        setFilters((current) => ({ ...current, [key]: value }))
        setPage(1)
    }

    const updateAfterReview = () => {
        setActiveSubmission(null)
        setRefresh((current) => current + 1)
    }

    const firstItem = meta.total === 0 ? 0 : ((meta.current_page - 1) * 5) + 1
    const lastItem = Math.min(meta.current_page * 5, meta.total)

    return (
        <div className="ph-submissions-page">
            <Sidebar />
            <main className="ph-submissions-main">
                <Header title="Submissions" subtitle="Review and manage submitted attendance records." className="ph-submissions-header" />
                <div className="ph-submissions-content">
                    <section className="ph-submission-filters" aria-label="Filter submissions">
                        <label className="ph-filter-control"><img src={CalendarIcon} alt="" /><input aria-label="Filter by date" type="date" value={filters.date} onChange={(event) => updateFilter('date', event.target.value)} /></label>
                        <label className="ph-filter-control"><img src={GatheringIcon} alt="" /><select aria-label="Filter by gathering" value={filters.gathering_type} onChange={(event) => updateFilter('gathering_type', event.target.value)}>{GATHERINGS.map((option) => <option key={option.value || 'all'} value={option.value}>{option.label}</option>)}</select></label>
                        <label className="ph-filter-control"><img src={YearIcon} alt="" /><select aria-label="Filter by year" value={filters.year_level} onChange={(event) => updateFilter('year_level', event.target.value)}>{YEARS.map((option) => <option key={option.value || 'all'} value={option.value}>{option.label}</option>)}</select></label>
                        <label className="ph-filter-control"><img src={StatusIcon} alt="" /><select aria-label="Filter by status" value={filters.status} onChange={(event) => updateFilter('status', event.target.value)}>{STATUSES.map((option) => <option key={option.value || 'all'} value={option.value}>{option.label}</option>)}</select></label>
                        <label className="ph-search-control"><img src={SearchIcon} alt="" /><input aria-label="Search submissions" type="search" placeholder="Search Submissions…" value={filters.search} onChange={(event) => updateFilter('search', event.target.value)} /></label>
                    </section>

                    <section className="ph-pending-banner" aria-live="polite">
                        <span className="ph-pending-count">{pendingCount}</span>
                        <div><h2>Pending Submissions</h2><p>Require your review and action.</p></div>
                    </section>

                    <SubmissionsTable rows={rows} loading={loading} error={error} onOpen={setActiveSubmission}
                        firstItem={firstItem} lastItem={lastItem} total={meta.total} page={page}
                        lastPage={meta.last_page} onPageChange={setPage} />
                </div>
            </main>
            {activeSubmission && <SubmissionDrawer submission={activeSubmission} onClose={() => setActiveSubmission(null)} onUpdated={updateAfterReview} />}
        </div>
    )
}

export default ProgramHeadSubmissions
