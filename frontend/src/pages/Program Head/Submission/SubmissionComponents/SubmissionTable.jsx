import { useCallback, useEffect, useMemo, useState } from 'react'
import { useLocation, useNavigate } from 'react-router-dom'
import { request } from '../../../../api/request'
import SearchIcon from '../../../../assets/search-icon.svg'
import GatheringIcon from '../../../../components/Icons/GatheringFilterIcon.svg'
import YearIcon from '../../../../components/Icons/FilterYearIcon.svg'
import StatusIcon from '../../../../components/Icons/FilterStatusIcon.svg'
import SubmissionDrawer from './SubmissionDrawer'
import SubmissionPagination from './SubmissionPagination'
import FilterDropdown from '../../../../components/Filters/FilterDropdown'
import CalendarFilter from '../../../../components/Filters/CalendarFilter'
import LoadingCircle from '../../../../components/Loading/LoadingCircle'
import UserAvatar from '../../../../components/UserAvatar'

const GATHERINGS = [
    { value: '', label: 'Gathering' },
    { value: 'pm', label: 'Prayer Meeting' },
    { value: 'ws', label: 'Worship Service' },
    { value: 'tg', label: 'Thanksgiving' },
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
]

export default function SubmissionTable() {
    const location = useLocation()
    const navigate = useNavigate()
    const [rows, setRows] = useState([])
    const [pendingCount, setPendingCount] = useState(0)
    const [meta, setMeta] = useState({ current_page: 1, last_page: 1, total: 0 })
    const [filters, setFilters] = useState({ date: '', gathering_type: '', year_level: '', status: '', search: '' })
    const [page, setPage] = useState(1)
    const [loading, setLoading] = useState(true)
    const [error, setError] = useState('')
    const [activeSubmission, setActiveSubmission] = useState(() => location.state?.openSubmission ?? null)
    const [refresh, setRefresh] = useState(0)

    const params = useMemo(() => {
        const values = new URLSearchParams({ page: String(page), per_page: '5' })
        Object.entries(filters).forEach(([key, value]) => {
            if (value) values.set(key, value)
        })
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
            if (err.name !== 'AbortError') {
                setError(err.message || 'Could not load submissions.')
            }
        } finally {
            if (!signal?.aborted) setLoading(false)
        }
    }, [params])

    useEffect(() => {
        const controller = new AbortController()
        loadSubmissions(controller.signal)
        return () => controller.abort()
    }, [loadSubmissions, refresh])

    useEffect(() => {
        if (!location.state?.openSubmission) return
        navigate(location.pathname, { replace: true, state: null })
    }, [location.pathname, location.state, navigate])

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
                    <FilterDropdown
                        label="Gathering"
                        icon={GatheringIcon}
                        options={GATHERINGS}
                        value={filters.gathering_type}
                        onChange={(value) => updateFilter('gathering_type', value)}
                    />
                    <FilterDropdown
                        label="Year"
                        icon={YearIcon}
                        options={YEARS}
                        value={filters.year_level}
                        onChange={(value) => updateFilter('year_level', value)}
                    />
                    <FilterDropdown
                        label="Status"
                        icon={StatusIcon}
                        options={STATUSES}
                        value={filters.status}
                        onChange={(value) => updateFilter('status', value)}
                    />
                    <label className="ph-search-control">
                        <img src={SearchIcon} alt="" />
                        <input
                            aria-label="Search submissions"
                            type="search"
                            placeholder="Search Submissions…"
                            value={filters.search}
                            onChange={(event) => updateFilter('search', event.target.value)}
                        />
                    </label>
                </section>
                <section className="ph-pending-banner" aria-live="polite">
                    <span className="ph-pending-count">{pendingCount}</span>
                    <div>
                        <h2>Pending Submissions</h2>
                        <p>Require your review and action.</p>
                    </div>
                </section>
                <section className="ph-submissions-table-panel" aria-label="Submissions list">
                    <div className="ph-submissions-table-scroll">
                        <table className="ph-submissions-table">
                            <thead>
                                <tr>
                                    <th>Secretary</th>
                                    <th>Gathering</th>
                                    <th>Program</th>
                                    <th>Year</th>
                                    <th>Date</th>
                                    <th>Status</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                {loading ? (
                                    <tr>
                                        <td className="ph-submissions-state" colSpan="7">
                                            <LoadingCircle label="Loading submissions…" />
                                        </td>
                                    </tr>
                                ) : error ? (
                                    <tr>
                                        <td className="ph-submissions-state ph-submissions-error" colSpan="7">
                                            {error}
                                        </td>
                                    </tr>
                                ) : rows.length === 0 ? (
                                    <tr>
                                        <td className="ph-submissions-state" colSpan="7">
                                            No submissions match these filters.
                                        </td>
                                    </tr>
                                ) : rows.map((row) => (
                                    <tr key={row.id}>
                                        <td>
                                            <UserAvatar
                                                className="ph-submitter-avatar"
                                                name={row.secretary}
                                                program={row.program}
                                            />
                                            {row.secretary}
                                        </td>
                                        <td>{row.gathering}</td>
                                        <td>{row.program}</td>
                                        <td>{row.year_label}</td>
                                        <td>{row.date}</td>
                                        <td>
                                            <span className={`ph-submission-status ${row.status}`}>
                                                <i />
                                                {row.status_label}
                                            </span>
                                        </td>
                                        <td>
                                            <button
                                                type="button"
                                                className="ph-submission-review"
                                                onClick={() => setActiveSubmission(row)}
                                            >
                                                {row.status === 'pending' ? 'Review' : 'View'}
                                                <span>›</span>
                                            </button>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                    <SubmissionPagination page={page} lastPage={meta.last_page} onPageChange={setPage} disabled={loading} />
                </section>
            </div>
            {activeSubmission && <SubmissionDrawer submission={activeSubmission} onClose={() => setActiveSubmission(null)} onUpdated={updateAfterReview} />}
        </>
    )
}
