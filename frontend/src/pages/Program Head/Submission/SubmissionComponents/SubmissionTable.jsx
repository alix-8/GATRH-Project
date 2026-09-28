import { useCallback, useEffect, useMemo, useState } from 'react'
import { request } from '../../../../api/request'
import SearchIcon from '../../../../assets/search-icon.png'
import GatheringIcon from '../../../../components/Icons/GatheringFilterIcon.svg'
import YearIcon from '../../../../components/Icons/FilterYearIcon.svg'
import StatusIcon from '../../../../components/Icons/FilterStatusIcon.svg'
import SubmissionsTable from './SubmissionsTable'
import SubmissionDrawer from './SubmissionDrawer'
import FilterDropdown from '../../../../components/Filters/FilterDropdown'
import CalendarFilter from '../../../../components/Filters/CalendarFilter'

const GATHERINGS = [
    { value: '', label: 'Gathering' }, { value: 'pm', label: 'Prayer Meeting' },
    { value: 'ws', label: 'Worship Service' }, { value: 'tg', label: 'Thanksgiving' },
    { value: 'special', label: 'Special Gathering' },
]
const YEARS = [
    { value: '', label: 'Year' }, { value: '1', label: '1st Year' }, { value: '2', label: '2nd Year' },
    { value: '3', label: '3rd Year' }, { value: '4', label: '4th Year' },
]
const STATUSES = [{ value: '', label: 'Status' }, { value: 'pending', label: 'Pending' }]

export default function SubmissionTable() {
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
        } catch (err) {
            if (err.name !== 'AbortError') setError(err.message || 'Could not load submissions.')
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

    return (
        <>
            <div className="ph-submissions-content">
                <section className="ph-submission-filters" aria-label="Filter submissions">
                    <CalendarFilter value={filters.date} onChange={(value) => updateFilter('date', value)} />
                    <FilterDropdown label="Gathering" icon={GatheringIcon} options={GATHERINGS} value={filters.gathering_type} onChange={(value) => updateFilter('gathering_type', value)} />
                    <FilterDropdown label="Year" icon={YearIcon} options={YEARS} value={filters.year_level} onChange={(value) => updateFilter('year_level', value)} />
                    <FilterDropdown label="Status" icon={StatusIcon} options={STATUSES} value={filters.status} onChange={(value) => updateFilter('status', value)} />
                    <label className="ph-search-control"><img src={SearchIcon} alt="" /><input aria-label="Search submissions" type="search" placeholder="Search Submissions…" value={filters.search} onChange={(event) => updateFilter('search', event.target.value)} /></label>
                </section>
                <section className="ph-pending-banner" aria-live="polite">
                    <span className="ph-pending-count">{pendingCount}</span>
                    <div><h2>Pending Submissions</h2><p>Require your review and action.</p></div>
                </section>
                <SubmissionsTable rows={rows} loading={loading} error={error} onOpen={setActiveSubmission}
                    page={page} lastPage={meta.last_page} onPageChange={setPage} />
            </div>
            {activeSubmission && <SubmissionDrawer submission={activeSubmission} onClose={() => setActiveSubmission(null)} onUpdated={updateAfterReview} />}
        </>
    )
}
