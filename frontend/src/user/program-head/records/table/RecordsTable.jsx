import { useCallback, useEffect, useMemo, useState } from 'react'
import { API_BASE_URL, request } from '../../../../api/request'
import SearchIcon from '../../../../assets/icons/search-icon.svg'
import GatheringIcon from '../../../../assets/icons/GatheringFilterIcon.svg'
import YearIcon from '../../../../assets/icons/FilterYearIcon.svg'
import FilterDropdown from '../../../../components/Filters/FilterDropdown'
import CalendarFilter from '../../../../components/Filters/CalendarFilter'
import ProgramTableSkeleton from '../../shared/ProgramTableSkeleton'
import UserAvatar from '../../../../components/identity/UserAvatar'
import RecordDate from '../../../../components/record-display/RecordDate'
import SubmissionDrawer from '../../submissions/drawer/SubmissionDrawer'
import '../../submissions/Submissions.css'
import './RecordsTable.css'
import useLiveRefresh from '../../../../hooks/useLiveRefresh'

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

export default function RecordsTable() {
    const [rows, setRows] = useState([])
    const [filters, setFilters] = useState({ gathering_type: '', date: '', year_level: '', search: '' })
    const [loading, setLoading] = useState(true)
    const [loadError, setLoadError] = useState('')
    const [exportNotice, setExportNotice] = useState(null)
    const [activeRecord, setActiveRecord] = useState(null)

    const params = useMemo(() => {
        const values = new URLSearchParams({ all: '1' })
        Object.entries(filters).forEach(([key, value]) => {
            if (value) values.set(key, value)
        })
        return values.toString()
    }, [filters])

    const loadRecords = useCallback(async (signal, silent = false) => {
        if (!silent) {
            setLoading(true)
            setLoadError('')
        }
        try {
            const result = await request(`/program-head/records?${params}`, { signal })
            setRows(Array.isArray(result.data) ? result.data : [])
            setLoadError('')
        } catch (err) {
            if (err.name !== 'AbortError') {
                setLoadError(err.message || 'Could not load records.')
            }
        } finally {
            if (!signal?.aborted && !silent) setLoading(false)
        }
    }, [params])

    const refreshRecords = useCallback((signal) => (
        activeRecord ? Promise.resolve() : loadRecords(signal, true)
    ), [activeRecord, loadRecords])
    useLiveRefresh(refreshRecords, 30_000)

    useEffect(() => {
        const controller = new AbortController()
        loadRecords(controller.signal)
        return () => controller.abort()
    }, [loadRecords])

    useEffect(() => {
        if (!exportNotice) return undefined

        const timeoutId = window.setTimeout(() => setExportNotice(null), 8000)
        return () => window.clearTimeout(timeoutId)
    }, [exportNotice])

    const updateFilter = (key, value) => {
        setFilters((current) => ({ ...current, [key]: value }))
    }

    const exportRecord = async (record) => {
        setExportNotice(null)
        try {
            const params = new URLSearchParams({
                year_level: String(record.year_level),
                ...(record.batch_key?.includes('|')
                    ? { batch_key: record.batch_key }
                    : { batch_id: record.batch_key }),
            })
            const response = await fetch(`${API_BASE_URL}/program-head/records/export?${params}`, {
                credentials: 'include',
                headers: {
                    Accept: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                },
            })
            if (!response.ok) {
                const result = await response.json().catch(() => ({}))
                throw new Error(result.message || 'Could not export records.')
            }
            const url = URL.createObjectURL(await response.blob())
            const link = document.createElement('a')
            link.href = url
            const disposition = response.headers.get('Content-Disposition') || ''
            const exportedFilename = disposition.match(/filename="?([^";]+)"?/i)?.[1]
            link.download = exportedFilename || `approved-${record.gathering_type}-${record.date_iso}.xls`
            document.body.append(link)
            link.click()
            link.remove()
            window.setTimeout(() => URL.revokeObjectURL(url), 1000)
            setExportNotice({
                type: 'success',
                message: `Started download: ${link.download}`,
            })
        } catch (err) {
            setExportNotice({
                type: 'error',
                message: err.message || 'Could not download the attendance record.',
            })
        }
    }

    return (
        <section className="ph-records-content">
            <section className="ph-record-filters" aria-label="Filter approved records">
                <FilterDropdown
                    label="Gathering"
                    icon={GatheringIcon}
                    options={GATHERINGS}
                    value={filters.gathering_type}
                    onChange={(value) => updateFilter('gathering_type', value)}
                    primary
                />
                <CalendarFilter value={filters.date} onChange={(value) => updateFilter('date', value)} />
                <FilterDropdown
                    label="Year"
                    icon={YearIcon}
                    options={YEARS}
                    value={filters.year_level}
                    onChange={(value) => updateFilter('year_level', value)}
                />
                <label className="ph-search-control">
                    <img src={SearchIcon} alt="" />
                    <input
                        aria-label="Search records"
                        type="search"
                        placeholder="Search Records…"
                        value={filters.search}
                        onChange={(event) => updateFilter('search', event.target.value)}
                    />
                </label>
            </section>
            {loadError && <p className="ph-record-error" role="alert">{loadError}</p>}
            {exportNotice && (
                <div
                    className={`ph-record-export-notice ${exportNotice.type}`}
                    role={exportNotice.type === 'error' ? 'alert' : 'status'}
                    aria-live={exportNotice.type === 'error' ? 'assertive' : 'polite'}
                >
                    <span>{exportNotice.message}</span>
                    <button type="button" aria-label="Dismiss export message" onClick={() => setExportNotice(null)}>
                        ×
                    </button>
                </div>
            )}
            <section className="ph-submissions-table-panel" aria-label="Approved attendance records">
                <div className="ph-submissions-table-scroll">
                    <table className="ph-submissions-table ph-records-table" aria-busy={loading}>
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
                                <ProgramTableSkeleton count={6} />
                            ) : loadError ? (
                                <tr>
                                    <td className="ph-submissions-state ph-submissions-error" colSpan="7">
                                        Could not load approved records.
                                    </td>
                                </tr>
                            ) : rows.length === 0 ? (
                                <tr>
                                    <td className="ph-submissions-state" colSpan="7">
                                        No approved records match these filters.
                                    </td>
                                </tr>
                            ) : rows.map((row) => (
                                <tr key={row.id}>
                                    <td title={row.secretary}>
                                        <UserAvatar
                                            className="ph-submitter-avatar"
                                            name={row.secretary}
                                            program={row.program}
                                        />
                                        {row.secretary}
                                    </td>
                                    <td title={row.gathering}>{row.gathering}</td>
                                    <td>{row.program}</td>
                                    <td>{row.year_label}</td>
                                    <td><RecordDate value={row.date_iso || row.date} /></td>
                                    <td>
                                        <span className="ph-submission-status approved">
                                            <i />
                                            Approved
                                        </span>
                                    </td>
                                    <td>
                                        <button
                                            type="button"
                                            className="ph-submission-review"
                                            onClick={() => setActiveRecord(row)}
                                        >
                                            View <span>›</span>
                                        </button>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </section>
            {activeRecord && (
                <SubmissionDrawer
                    submission={{ ...activeRecord, id: activeRecord.batch_key, status: 'approved' }}
                    onClose={() => setActiveRecord(null)}
                    onUpdated={() => {}}
                    onExport={exportRecord}
                />
            )}
        </section>
    )
}
