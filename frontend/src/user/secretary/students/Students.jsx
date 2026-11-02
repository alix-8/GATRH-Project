import { useState, useRef, useEffect } from 'react'
import { API_BASE_URL } from '../../../api/request'
import Sidebar from '../../../components/layout/Sidebar'
import Header from '../../../components/layout/Header'
import SortbyIcon from '../../../assets/icons/sort-by-icon.svg'
import DropdownIcon from '../../../assets/icons/drop-down-icon.svg'
import ResponseIcon from '../../../assets/icons/response-icon.svg'
import LevelIcon from '../../../assets/icons/level-icon.svg'
import SearchIcon from '../../../assets/icons/search-icon.svg'
import CalendarIcon from '../../../assets/icons/calendar-icon.svg'
import LoadingCircle from '../../../components/feedback/LoadingCircle'
import UserAvatar from '../../../components/identity/UserAvatar'
import RecordDate from '../../../components/RecordDate'
import VenueLabel from '../../../components/VenueLabel'
import useLiveRefresh from '../../../hooks/useLiveRefresh'
import './Students.css'

const SORT_OPTIONS = ['Sort by', 'Ascending', 'Descending']
const RESPONSE_OPTIONS = ['Response', 'Responded', 'No Response']
const LEVEL_OPTIONS = ['Escalation', 'Level 1', 'Level 2', 'Level 3']
const WEEKLY_GATHERING_TYPES = [
    { type: 'pm', abbreviation: 'PM', label: 'Prayer Meeting' },
    { type: 'ws', abbreviation: 'WS', label: 'Worship Service' },
    { type: 'tg', abbreviation: 'TG', label: 'Thanksgiving' },
]

function OverviewSkeletonBlock({ className = '' }) {
    return <span className={`overview-skeleton-block ${className}`} aria-hidden="true" />
}

function OverviewHistorySkeletonRows({ count = 4 }) {
    return Array.from({ length: count }, (_, index) => (
        <tr key={`overview-history-skeleton-${index}`} aria-hidden="true">
            <td><OverviewSkeletonBlock className="overview-skeleton-cell overview-skeleton-gathering" /></td>
            <td><OverviewSkeletonBlock className="overview-skeleton-cell overview-skeleton-time" /></td>
            <td><OverviewSkeletonBlock className="overview-skeleton-cell overview-skeleton-date" /></td>
            <td><OverviewSkeletonBlock className="overview-skeleton-cell overview-skeleton-location" /></td>
            <td><OverviewSkeletonBlock className="overview-skeleton-cell overview-skeleton-status" /></td>
        </tr>
    ))
}

function OverviewHistorySkeleton({ count = 4 }) {
    return (
        <table className="overview-history-skeleton" aria-hidden="true">
            <thead><tr><th>Gathering</th><th>Time</th><th>Date</th><th>Location</th><th>Status</th></tr></thead>
            <tbody><OverviewHistorySkeletonRows count={count} /></tbody>
        </table>
    )
}

function OverviewStatsSkeleton() {
    return (
        <section className="overview-stats overview-stats-skeleton" aria-label="Loading attendance summary" aria-busy="true">
            <div className="overview-stat-card">
                <OverviewSkeletonBlock className="overview-skeleton-stat-label" />
                <OverviewSkeletonBlock className="overview-skeleton-stat-value" />
                <OverviewSkeletonBlock className="overview-skeleton-stat-detail" />
            </div>
            <div className="overview-stat-card">
                <OverviewSkeletonBlock className="overview-skeleton-stat-label" />
                <div className="overview-skeleton-week-dots"><i /><i /><i /></div>
            </div>
            <div className="overview-stat-card">
                <OverviewSkeletonBlock className="overview-skeleton-stat-label" />
                <OverviewSkeletonBlock className="overview-skeleton-stat-value" />
                <OverviewSkeletonBlock className="overview-skeleton-stat-detail" />
            </div>
        </section>
    )
}

function formatOverviewTime(value) {
    if (!value || value === '—') return '—'

    const time = String(value).trim()
    const match = /^(\d{1,2}):(\d{2})(?::\d{2})?\s*(am|pm)?$/i.exec(time)
    if (!match) return time

    const hour = Number(match[1])
    const minute = Number(match[2])
    if (minute > 59 || hour > (match[3] ? 12 : 23) || (match[3] && hour === 0)) return time

    const period = match[3]?.toLowerCase() || (hour >= 12 ? 'pm' : 'am')
    return `${hour % 12 || 12}:${match[2]}${period}`
}

function Students() {
    const [students, setStudents] = useState([])
    const [attendanceSession, setAttendanceSession] = useState(null)
    const [latestGatheringType, setLatestGatheringType] = useState(null)
    const [responseGathering, setResponseGathering] = useState(null)
    const [escalationWeekRange, setEscalationWeekRange] = useState(null)
    const [loading, setLoading] = useState(() => Boolean(localStorage.getItem('user')))
    const [pollTick, setPollTick] = useState(0)
    useLiveRefresh(() => setPollTick((current) => current + 1), 30_000)
    const [searchQuery, setSearchQuery] = useState('')
    const [selectedSort, setSelectedSort] = useState(null)
    const [isSortOpen, setIsSortOpen] = useState(false)
    const sortDropdownRef = useRef(null)

    const [selectedResponse, setSelectedResponse] = useState(null)
    const [isResponseOpen, setIsResponseOpen] = useState(false)
    const responseDropdownRef = useRef(null)

    const [selectedLevel, setSelectedLevel] = useState(null)
    const [isLevelOpen, setIsLevelOpen] = useState(false)
    const levelDropdownRef = useRef(null)
    const [overview, setOverview] = useState(null)
    const [overviewLoading, setOverviewLoading] = useState(false)
    const [overviewError, setOverviewError] = useState(null)
    const [overviewHistoryLoading, setOverviewHistoryLoading] = useState(false)
    const [overviewHistoryError, setOverviewHistoryError] = useState(null)
    const overviewRequestId = useRef(0)
    const [overviewGathering, setOverviewGathering] = useState('')
    const [overviewDate, setOverviewDate] = useState('')
    const [overviewStatus, setOverviewStatus] = useState('')
    const [overviewOpenFilter, setOverviewOpenFilter] = useState(null)
    const overviewFiltersRef = useRef(null)

    useEffect(() => {
        if (!localStorage.getItem('user')) {
            return
        }

        const controller = new AbortController()
        fetch(`${API_BASE_URL}/students/attendance-snapshot`, {
            credentials: 'include',
            signal: controller.signal,
            headers: {
                Accept: 'application/json',
            },
        })
            .then(res => res.ok ? res.json() : [])
            .then(data => {
                if (controller.signal.aborted) return
                setStudents(Array.isArray(data.students) ? data.students : [])
                setAttendanceSession(data.session || null)
                setLatestGatheringType(data.latest_gathering_type || null)
                setEscalationWeekRange(data.week_range || null)
                setLoading(false)
            })
            .catch(() => {
                if (controller.signal.aborted) return
                setStudents([])
                setAttendanceSession(null)
                setLatestGatheringType(null)
                setEscalationWeekRange(null)
                setLoading(false)
            })

        return () => controller.abort()
    }, [pollTick])

    useEffect(() => {
        function handleClickOutside(event) {
            if (sortDropdownRef.current && !sortDropdownRef.current.contains(event.target)) {
                setIsSortOpen(false)
            }
            if (responseDropdownRef.current && !responseDropdownRef.current.contains(event.target)) {
                setIsResponseOpen(false)
            }
            if (levelDropdownRef.current && !levelDropdownRef.current.contains(event.target)) {
                setIsLevelOpen(false)
            }
            if (overviewFiltersRef.current && !overviewFiltersRef.current.contains(event.target)) {
                setOverviewOpenFilter(null)
            }
        }
        document.addEventListener('mousedown', handleClickOutside)
        return () => document.removeEventListener('mousedown', handleClickOutside)
    }, [])

    const handleSelectSort = (option) => {
        setSelectedSort(option === 'Sort by' ? null : option)
        setIsSortOpen(false)
    }

    const handleSelectResponse = (option) => {
        setSelectedResponse(option === 'Response' ? null : option)
        setIsResponseOpen(false)
    }

    const handleSelectLevel = (option) => {
        setSelectedLevel(option === 'Escalation' ? null : option)
        setIsLevelOpen(false)
    }

    const normalizedYear = (year) => {
        const yearLabels = { 1: '1st Year', 2: '2nd Year', 3: '3rd Year', 4: '4th Year' }
        return yearLabels[year] || year
    }
    const activeResponseGathering = responseGathering || latestGatheringType || 'pm'
    const changeResponseGathering = (direction) => {
        const index = WEEKLY_GATHERING_TYPES.findIndex(({ type }) => type === activeResponseGathering)
        setResponseGathering(WEEKLY_GATHERING_TYPES[(index + direction + WEEKLY_GATHERING_TYPES.length) % WEEKLY_GATHERING_TYPES.length].type)
    }
    const filteredStudents = students
        .map((student) => ({ ...student, response: student.responses_by_gathering?.[activeResponseGathering] ?? null }))
        .filter((student) => !selectedResponse || student.response === selectedResponse)
        .filter((student) => {
            if (!selectedLevel) return true
            const bars = Math.min(3, Math.max(0, Number(student.no_response_count) || 0))
            return bars === Number(selectedLevel.replace('Level ', ''))
        })
        .filter((student) => student.name.toLowerCase().includes(searchQuery.trim().toLowerCase()))
        .sort((a, b) => {
            if (!selectedSort) return 0
            const comparison = a.name.localeCompare(b.name)
            return selectedSort === 'Descending' ? -comparison : comparison
        })

    const hasAttendanceSession = Boolean(attendanceSession)
    const respondedCount = hasAttendanceSession ? students.filter((student) => student.response === 'Responded').length : 0
    const noResponseCount = hasAttendanceSession ? students.filter((student) => student.response === 'No Response').length : 0
    const responseRate = students.length ? Math.round((respondedCount / students.length) * 100) : 0
    const noResponseRate = students.length ? Math.round((noResponseCount / students.length) * 100) : 0
    const escalationBars = (student) => {
        return Math.min(3, Math.max(0, Number(student.no_response_count) || 0))
    }
    const formatEscalationDate = (value) => {
        const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(String(value || ''))
        if (!match) return null
        const date = new Date(Number(match[1]), Number(match[2]) - 1, Number(match[3]))
        return date.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' })
    }
    const escalationDateRange = [
        formatEscalationDate(escalationWeekRange?.start),
        formatEscalationDate(escalationWeekRange?.end),
    ].filter(Boolean).join(' – ')
    const escalationGuide = `Weekly Escalation Level\n${escalationDateRange ? `Date range: ${escalationDateRange}` : ''}\n\n1 No-response = 1 level`
    const formatOverviewDate = (date) => date ? new Intl.DateTimeFormat('en-US', { year: 'numeric', month: 'short', day: 'numeric' }).format(new Date(`${date}T00:00:00`)) : '—'
    const openOverview = async (student) => {
        const requestId = ++overviewRequestId.current
        setOverview({ student, month: '', summary: {}, history: [] })
        setOverviewLoading(true)
        setOverviewError(null)
        setOverviewHistoryLoading(false)
        setOverviewHistoryError(null)
        setOverviewGathering('')
        setOverviewDate('')
        setOverviewStatus('')
        setOverviewOpenFilter(null)
        try {
            const response = await fetch(`${API_BASE_URL}/students/${student.id}/overview/current-week`, {
                credentials: 'include',
                headers: { Accept: 'application/json' },
            })
            if (!response.ok) throw new Error('Unable to load this student’s overview.')
            const currentWeekOverview = await response.json()
            if (requestId !== overviewRequestId.current) return

            setOverview(currentWeekOverview)
            setOverviewLoading(false)
            setOverviewHistoryLoading(true)

            try {
                const historyResponse = await fetch(`${API_BASE_URL}/students/${student.id}/overview/history`, {
                    credentials: 'include',
                    headers: { Accept: 'application/json' },
                })
                if (!historyResponse.ok) throw new Error('Unable to load older attendance history.')
                const historyData = await historyResponse.json()
                if (requestId === overviewRequestId.current) {
                    setOverview(current => current ? { ...current, history: historyData.history || [] } : current)
                }
            } catch (error) {
                if (requestId === overviewRequestId.current) setOverviewHistoryError(error.message)
            } finally {
                if (requestId === overviewRequestId.current) setOverviewHistoryLoading(false)
            }
        } catch (error) {
            if (requestId === overviewRequestId.current) setOverviewError(error.message)
        } finally {
            if (requestId === overviewRequestId.current) setOverviewLoading(false)
        }
    }
    const closeOverview = () => {
        overviewRequestId.current += 1
        setOverview(null)
        setOverviewLoading(false)
        setOverviewHistoryLoading(false)
    }
    const overviewHistory = (overview?.history || [])
        .filter((record) => !overviewGathering || record.gathering_type === overviewGathering)
        .filter((record) => !overviewDate || record.date === overviewDate)
        .filter((record) => !overviewStatus || record.status === overviewStatus)
    const overviewWeeklyStatus = Object.fromEntries(
        (overview?.summary?.this_week || []).map((gathering) => [gathering.type, gathering.status])
    )

    return (
        
        <div className="students">
            <Sidebar />

            <main className="students-main">
                <Header
                    title="Students"
                    subtitle="Manage student information and attendance history."
                    className="students-header"
                />

                <section className="filter-section">
                    <div className="sort-dropdown-container" ref={sortDropdownRef}>
                        <div
                            className={`filter-section-row ${isSortOpen ? 'active' : ''}`}
                            onClick={() => {
                                setIsSortOpen((prev) => !prev)
                                setIsResponseOpen(false)
                                setIsLevelOpen(false)
                            }}
                        >
                            <img
                                className="sort-by-icon"
                                src={SortbyIcon}
                                alt="Sort by Icon"
                            />
                            <p className="sort-by-text">{selectedSort || 'Sort by'}</p>
                            <img
                                className={`drop-down-icon ${isSortOpen ? 'rotate' : ''}`}
                                src={DropdownIcon}
                                alt="Dropdown Icon"
                            />
                        </div>

                        {isSortOpen && (
                            <ul className="sort-dropdown-menu">
                                {SORT_OPTIONS.map((option) => (
                                    <li
                                        key={option}
                                        className={`sort-dropdown-item ${selectedSort === option ? 'selected' : ''}`}
                                        onClick={() => handleSelectSort(option)}
                                    >
                                        {option}
                                    </li>
                                ))}
                            </ul>
                        )}
                    </div>

                    <div className="response-dropdown-container" ref={responseDropdownRef}>
                        <div
                            className={`filter-section-row ${isResponseOpen ? 'active' : ''}`}
                            onClick={() => {
                                setIsResponseOpen((prev) => !prev)
                                setIsSortOpen(false)
                                setIsLevelOpen(false)
                            }}
                        >
                            <img
                                className="response-icon"
                                src={ResponseIcon}
                                alt="Response Icon"
                            />
                            <p className="response-text">{selectedResponse || 'Response'}</p>
                            <img
                                className={`drop-down-icon ${isResponseOpen ? 'rotate' : ''}`}
                                src={DropdownIcon}
                                alt="Dropdown Icon"
                            />
                        </div>

                        {isResponseOpen && (
                            <ul className="response-dropdown-menu">
                                {RESPONSE_OPTIONS.map((option) => (
                                    <li
                                        key={option}
                                        className={`response-dropdown-item ${selectedResponse === option ? 'selected' : ''}`}
                                        onClick={() => handleSelectResponse(option)}
                                    >
                                        {option}
                                    </li>
                                ))}
                            </ul>
                        )}
                    </div>

                    <div className="level-dropdown-container" ref={levelDropdownRef}>
                        <div
                            className={`filter-section-row ${isLevelOpen ? 'active' : ''}`}
                            onClick={() => {
                                setIsLevelOpen((prev) => !prev)
                                setIsSortOpen(false)
                                setIsResponseOpen(false)
                            }}
                        >
                            <img
                                className="level-icon"
                                src={LevelIcon}
                                alt="Level Icon"
                            />
                            <p className="level-text">{selectedLevel || 'Escalation'}</p>
                            <img
                                className={`drop-down-icon ${isLevelOpen ? 'rotate' : ''}`}
                                src={DropdownIcon}
                                alt="Dropdown Icon"
                            />
                        </div>

                        {isLevelOpen && (
                            <ul className="level-dropdown-menu">
                                {LEVEL_OPTIONS.map((option) => (
                                    <li
                                        key={option}
                                        className={`level-dropdown-item ${selectedLevel === option ? 'selected' : ''}`}
                                        onClick={() => handleSelectLevel(option)}
                                    >
                                        {option}
                                    </li>
                                ))}
                            </ul>
                        )}
                    </div>

                    <div className="search-students-container">
                        <img
                            className="search-students-icon"
                            src={SearchIcon}
                            alt="Search Icon"
                        />
                        <input
                            type="text"
                            className="search-students-input"
                            placeholder="Search Students..."
                            value={searchQuery}
                            onChange={(e) => setSearchQuery(e.target.value)}
                        />
                    </div>
                </section>

                <section className="students-stats-section">
                    <div className="stat-card stat-card-total">
                        <div className="stat-card-header">
                            <h4 className="stat-card-title">Total Students</h4>
                            <p className="stat-card-subtitle">Overall enrolled</p>
                        </div>
                        <h2 className="stat-card-value">{loading ? '—' : students.length}</h2>
                    </div>

                    <div className="stat-card stat-card-responded">
                        <div className="stat-card-header">
                            <h4 className="stat-card-title">This Week Respondents</h4>
                            <p className="stat-card-subtitle">{loading ? '—' : `${responseRate}% response rate`}</p>
                        </div>
                        <h2 className="stat-card-value">{loading ? '—' : respondedCount}<span className="stat-card-slash">/{loading ? '—' : students.length}</span></h2>
                    </div>

                    <div className="stat-card stat-card-no-response">
                        <div className="stat-card-header">
                            <h4 className="stat-card-title">No Response</h4>
                            <p className="stat-card-subtitle">{loading ? '—' : `${noResponseRate}% of overall`}</p>
                        </div>
                        <h2 className="stat-card-value">{loading ? '—' : noResponseCount}</h2>
                    </div>
                </section>

                <section className="students-table-section">
                    <div className="students-table-heading">
                        <h2>Students</h2>
                    </div>
                    <div className="students-table-wrapper">
                        <table className="students-table">
                            <thead>
                                <tr>
                                    <th>Name</th><th>Year</th><th>Program</th>
                                    <th>
                                        <div className="students-response-navigation">
                                            <button type="button" aria-label="Previous gathering response" onClick={() => changeResponseGathering(-1)}>‹</button>
                                            <span aria-live="polite">Response ({activeResponseGathering.toUpperCase()})</span>
                                            <button type="button" aria-label="Next gathering response" onClick={() => changeResponseGathering(1)}>›</button>
                                        </div>
                                    </th>
                                    <th>Escalation</th><th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                {loading && <tr><td colSpan="6" className="students-table-state"><LoadingCircle label="Loading students…" /></td></tr>}
                                {!loading && filteredStudents.length === 0 && <tr><td colSpan="6" className="students-table-state">No students found.</td></tr>}
                                {!loading && filteredStudents.map((student) => (
                                    <tr key={student.id}>
                                        <td><span className="student-name-cell">{student.name}</span></td>
                                        <td>{normalizedYear(student.year_level)}</td>
                                        <td>{student.program}</td>
                                        <td>
                                            {student.response ? (
                                                <span className={`response-badge ${student.response === 'Responded' ? 'responded' : 'no-response'}`}>{student.response}</span>
                                            ) : (
                                                <span
                                                    className="response-badge placeholder"
                                                    aria-label="No attendance for this gathering this week yet"
                                                    title="No attendance for this gathering this week yet"
                                                >
                                                    —
                                                </span>
                                            )}
                                        </td>
                                        <td>
                                            <span
                                                className={`escalation-bars escalation-level-${escalationBars(student)}`}
                                                aria-label={`${student.no_response_count} no-response records`}
                                                title={escalationGuide}
                                            >
                                                {[0, 1, 2].map((bar) => <i key={bar} className={bar < escalationBars(student) ? 'filled' : ''} />)}
                                            </span>
                                        </td>
                                        <td><button type="button" className="student-view-button" onClick={() => openOverview(student)}>View&nbsp; ›</button></td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </section>
            </main>

            {overview && (
                <div className="student-overview-backdrop" onMouseDown={(event) => event.target === event.currentTarget && closeOverview()}>
                    <aside className="student-overview-drawer" role="dialog" aria-modal="true" aria-label="Student overview">
                        <button type="button" className="student-overview-close-icon" aria-label="Close student overview" onClick={closeOverview}>×</button>
                        <div className="student-overview-content">
                        <h2>Student Overview</h2>
                        <p className="overview-month">
                            {overviewLoading
                                ? <OverviewSkeletonBlock className="overview-skeleton-month" />
                                : overview.month}
                        </p>

                        <section className="overview-student-card">
                            <UserAvatar className="overview-avatar" name={overview.student.name} program={overview.student.program} />
                            <div><strong>{overview.student.name}</strong><small>{overview.student.program} {normalizedYear(overview.student.year_level)}</small></div>
                        </section>

                        {overviewLoading ? <OverviewStatsSkeleton /> : <section className="overview-stats" aria-label="Attendance summary">
                            <div className="overview-stat-card">
                                <strong>This Month</strong>
                                <div className="overview-stat-value">
                                    <span className="overview-month-icon">
                                        <img src={ResponseIcon} alt="" />
                                    </span>
                                    <b>{overview.summary?.this_month ?? 0}/{overview.summary?.this_month_total ?? 12}</b>
                                </div>
                            </div>
                            <div className="overview-stat-card overview-stat-card-weekly">
                                <strong>This week</strong>
                                <div className="overview-week-gatherings">
                                    {WEEKLY_GATHERING_TYPES.map(({ type, abbreviation, label }) => (
                                        <span key={type} aria-label={`${label}: ${overviewWeeklyStatus[type] === 'present' ? 'Responded' : 'No response recorded'}`}>
                                            <small>{abbreviation}</small>
                                            <i className={overviewWeeklyStatus[type] === 'present' ? 'present' : ''} />
                                        </span>
                                    ))}
                                </div>
                            </div>
                            <div className="overview-stat-card">
                                <strong>Escalation</strong>
                                <div className="overview-stat-escalation">
                                    <span
                                        className={`overview-escalation-indicator escalation-level-${Math.min(3, Math.max(0, Number(overview.summary?.escalation_count) || 0))}`}
                                        aria-label={`${overview.summary?.escalation_count ?? 0} escalation levels`}
                                        title={escalationGuide}
                                    >
                                        {[0, 1, 2].map((level) => (
                                            <i
                                                key={level}
                                                className={level < Math.min(3, Number(overview.summary?.escalation_count) || 0) ? 'filled' : ''}
                                            />
                                        ))}
                                    </span>
                                    <b>{overview.summary?.escalation_count ?? 0}</b>
                                </div>
                            </div>
                        </section>}

                        <div className="overview-filters" ref={overviewFiltersRef}>
                            <div className="overview-filter-container">
                                <button type="button" className="overview-filter-control" aria-expanded={overviewOpenFilter === 'gathering'} onClick={() => setOverviewOpenFilter(overviewOpenFilter === 'gathering' ? null : 'gathering')}>
                                    <img src={ResponseIcon} alt="" />
                                    <span>{overviewGathering ? { pm: 'Prayer Meeting', ws: 'Worship Service', tg: 'Thanksgiving' }[overviewGathering] : 'Gathering'}</span>
                                    <img className="overview-dropdown-icon" src={DropdownIcon} alt="" />
                                </button>
                                {overviewOpenFilter === 'gathering' && <ul className="overview-filter-menu" role="listbox">
                                    <li role="option" className={`overview-filter-option ${!overviewGathering ? 'selected' : ''}`} aria-selected={!overviewGathering} onClick={(event) => { event.stopPropagation(); setOverviewGathering(''); setOverviewOpenFilter(null) }}>Gathering</li>
                                    {[['pm', 'Prayer Meeting'], ['ws', 'Worship Service'], ['tg', 'Thanksgiving']].map(([value, label]) => <li role="option" className={`overview-filter-option ${overviewGathering === value ? 'selected' : ''}`} aria-selected={overviewGathering === value} key={value} onClick={(event) => { event.stopPropagation(); setOverviewGathering(value); setOverviewOpenFilter(null) }}>{label}</li>)}
                                </ul>}
                            </div>
                            <div className="overview-filter-container">
                                <button type="button" className="overview-filter-control" aria-expanded={overviewOpenFilter === 'date'} onClick={() => setOverviewOpenFilter(overviewOpenFilter === 'date' ? null : 'date')}>
                                    <img src={CalendarIcon} alt="" />
                                    <span>{overviewDate ? formatOverviewDate(overviewDate) : 'Date'}</span>
                                    <img className="overview-dropdown-icon" src={DropdownIcon} alt="" />
                                </button>
                                {overviewOpenFilter === 'date' && <ul className="overview-filter-menu" role="listbox">
                                    <li role="option" className={`overview-filter-option ${!overviewDate ? 'selected' : ''}`} aria-selected={!overviewDate} onClick={(event) => { event.stopPropagation(); setOverviewDate(''); setOverviewOpenFilter(null) }}>Date</li>
                                    {[...new Set((overview?.history || []).map((record) => record.date).filter(Boolean))].map((date) => <li role="option" className={`overview-filter-option ${overviewDate === date ? 'selected' : ''}`} aria-selected={overviewDate === date} key={date} onClick={(event) => { event.stopPropagation(); setOverviewDate(date); setOverviewOpenFilter(null) }}>{formatOverviewDate(date)}</li>)}
                                </ul>}
                            </div>
                            <div className="overview-filter-container">
                                <button type="button" className="overview-filter-control" aria-expanded={overviewOpenFilter === 'status'} onClick={() => setOverviewOpenFilter(overviewOpenFilter === 'status' ? null : 'status')}>
                                    <img src={LevelIcon} alt="" />
                                    <span>{overviewStatus === 'present' ? 'Present' : overviewStatus === 'no_response' ? 'No Response' : 'Status'}</span>
                                    <img className="overview-dropdown-icon" src={DropdownIcon} alt="" />
                                </button>
                                {overviewOpenFilter === 'status' && <ul className="overview-filter-menu" role="listbox">
                                    <li role="option" className={`overview-filter-option ${!overviewStatus ? 'selected' : ''}`} aria-selected={!overviewStatus} onClick={(event) => { event.stopPropagation(); setOverviewStatus(''); setOverviewOpenFilter(null) }}>Status</li>
                                    <li role="option" className={`overview-filter-option ${overviewStatus === 'present' ? 'selected' : ''}`} aria-selected={overviewStatus === 'present'} onClick={(event) => { event.stopPropagation(); setOverviewStatus('present'); setOverviewOpenFilter(null) }}>Present</li>
                                    <li role="option" className={`overview-filter-option ${overviewStatus === 'no_response' ? 'selected' : ''}`} aria-selected={overviewStatus === 'no_response'} onClick={(event) => { event.stopPropagation(); setOverviewStatus('no_response'); setOverviewOpenFilter(null) }}>No Response</li>
                                </ul>}
                            </div>
                        </div>

                        <div className="overview-history-header">
                            <h2>Attendance List</h2>
                        </div>
                        <div className="overview-history" aria-busy={overviewLoading || overviewHistoryLoading}>
                            {overviewLoading && !overviewError && <OverviewHistorySkeleton />}
                            {overviewError && <p className="overview-state overview-error">{overviewError}</p>}
                            {overviewHistoryError && <p className="overview-state overview-error">{overviewHistoryError}</p>}
                            {!overviewLoading && overviewHistoryLoading && overviewHistory.length === 0 && <OverviewHistorySkeleton count={3} />}
                            {!overviewLoading && !overviewError && !overviewHistoryLoading && !overviewHistoryError && overviewHistory.length === 0 && <p className="overview-state">No attendance records yet.</p>}
                            {!overviewLoading && !overviewError && overviewHistory.length > 0 && (
                                <table><thead><tr><th>Gathering</th><th>Time</th><th>Date</th><th>Location</th><th>Status</th></tr></thead>
                                    <tbody>
                                        {overviewHistory.map((record) => <tr key={record.id}><td>{record.gathering}</td><td>{formatOverviewTime(record.time)}</td><td><RecordDate value={record.date} tooltip="weekday" /></td><td><VenueLabel value={record.location} fallback="" /></td><td><span className={`overview-status ${record.status}`}>{record.status === 'present' ? 'Present' : record.status === 'excused' ? 'Excused' : 'No Response'}</span></td></tr>)}
                                        {overviewHistoryLoading && <OverviewHistorySkeletonRows count={3} />}
                                    </tbody>
                                </table>
                            )}
                        </div>
                        </div>
                        <div className="student-overview-footer">
                            <button type="button" className="student-overview-close-button" onClick={closeOverview}>Close</button>
                        </div>
                    </aside>
                </div>
            )}
        </div>
    )
}

export default Students
