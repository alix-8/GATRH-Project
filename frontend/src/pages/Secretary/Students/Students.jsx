import { useState, useRef, useEffect } from 'react'
import Sidebar from '../../../components/Sidebar/Sidebar'
import Header from '../../../components/Header/Header'
import SortbyIcon from '../../../assets/sort-by-icon.svg'
import DropdownIcon from '../../../assets/drop-down-icon.svg'
import ResponseIcon from '../../../assets/response-icon.svg'
import LevelIcon from '../../../assets/level-icon.svg'
import SearchIcon from '../../../assets/search-icon.svg'
import CalendarIcon from '../../../assets/calendar-icon.svg'
import LoadingCircle from '../../../components/Loading/LoadingCircle'
import UserAvatar from '../../../components/UserAvatar'
import './Students.css'

const SORT_OPTIONS = ['Sort by', 'Ascending', 'Descending']
const RESPONSE_OPTIONS = ['Response', 'Responded', 'No Response']
const LEVEL_OPTIONS = ['Escalation', 'Level 1', 'Level 2', 'Level 3']

function Students() {
    const [students, setStudents] = useState([])
    const [attendanceSession, setAttendanceSession] = useState(null)
    const [loading, setLoading] = useState(() => Boolean(localStorage.getItem('auth_token')))
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
    const [overviewGathering, setOverviewGathering] = useState('')
    const [overviewDate, setOverviewDate] = useState('')
    const [overviewStatus, setOverviewStatus] = useState('')
    const [overviewOpenFilter, setOverviewOpenFilter] = useState(null)
    const overviewFiltersRef = useRef(null)

    useEffect(() => {
        const token = localStorage.getItem('auth_token')
        if (!token) {
            return
        }

        fetch('http://127.0.0.1:8000/api/students/attendance-snapshot', {
            headers: {
                Accept: 'application/json',
                Authorization: `Bearer ${token}`,
            },
        })
            .then(res => res.ok ? res.json() : [])
            .then(data => {
                setStudents(Array.isArray(data.students) ? data.students : [])
                setAttendanceSession(data.session || null)
                setLoading(false)
            })
            .catch(() => {
                setStudents([])
                setAttendanceSession(null)
                setLoading(false)
            })
    }, [])

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
    const filteredStudents = students
        .filter((student) => !selectedResponse || student.response === selectedResponse)
        .filter((student) => {
            if (!selectedLevel) return true
            const bars = student.response === 'Responded' ? 0 : Math.min(3, Math.max(0, Number(student.no_response_count) || 0))
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
        if (student.response === 'Responded') return 0
        return Math.min(3, Math.max(0, Number(student.no_response_count) || 0))
    }
    const formatOverviewDate = (date) => date ? new Intl.DateTimeFormat('en-US', { year: 'numeric', month: 'short', day: 'numeric' }).format(new Date(`${date}T00:00:00`)) : '—'
    const openOverview = async (student) => {
        setOverview({ student, month: '', summary: {}, history: [] })
        setOverviewLoading(true)
        setOverviewError(null)
        setOverviewGathering('')
        setOverviewDate('')
        setOverviewStatus('')
        setOverviewOpenFilter(null)
        try {
            const response = await fetch(`http://127.0.0.1:8000/api/students/${student.id}/overview`, {
                headers: { Accept: 'application/json', Authorization: `Bearer ${localStorage.getItem('auth_token')}` },
            })
            if (!response.ok) throw new Error('Unable to load this student’s overview.')
            setOverview(await response.json())
        } catch (error) {
            setOverviewError(error.message)
        } finally {
            setOverviewLoading(false)
        }
    }
    const overviewHistory = (overview?.history || [])
        .filter((record) => !overviewGathering || record.gathering_type === overviewGathering)
        .filter((record) => !overviewDate || record.date === overviewDate)
        .filter((record) => !overviewStatus || record.status === overviewStatus)

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
                    <div className="sort-dropdown-container app-dropdown" ref={sortDropdownRef}>
                        <div
                            className={`filter-section-row app-dropdown-trigger ${isSortOpen ? 'active' : ''}`}
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
                            <ul className="sort-dropdown-menu app-dropdown-menu">
                                {SORT_OPTIONS.map((option) => (
                                    <li
                                        key={option}
                                        className={`sort-dropdown-item app-dropdown-option ${selectedSort === option ? 'selected' : ''}`}
                                        onClick={() => handleSelectSort(option)}
                                    >
                                        {option}
                                    </li>
                                ))}
                            </ul>
                        )}
                    </div>

                    <div className="response-dropdown-container app-dropdown" ref={responseDropdownRef}>
                        <div
                            className={`filter-section-row app-dropdown-trigger ${isResponseOpen ? 'active' : ''}`}
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
                            <ul className="response-dropdown-menu app-dropdown-menu">
                                {RESPONSE_OPTIONS.map((option) => (
                                    <li
                                        key={option}
                                        className={`response-dropdown-item app-dropdown-option ${selectedResponse === option ? 'selected' : ''}`}
                                        onClick={() => handleSelectResponse(option)}
                                    >
                                        {option}
                                    </li>
                                ))}
                            </ul>
                        )}
                    </div>

                    <div className="level-dropdown-container app-dropdown" ref={levelDropdownRef}>
                        <div
                            className={`filter-section-row app-dropdown-trigger ${isLevelOpen ? 'active' : ''}`}
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
                            <ul className="level-dropdown-menu app-dropdown-menu">
                                {LEVEL_OPTIONS.map((option) => (
                                    <li
                                        key={option}
                                        className={`level-dropdown-item app-dropdown-option ${selectedLevel === option ? 'selected' : ''}`}
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
                                    <th>Name</th><th>Year</th><th>Program</th><th>This Week's Response</th><th>Escalation</th><th>Action</th>
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
                                            {student.response && (
                                                <span className={`response-badge ${student.response === 'Responded' ? 'responded' : 'no-response'}`}>{student.response}</span>
                                            )}
                                        </td>
                                        <td>
                                            <span className="escalation-bars" aria-label={`${student.no_response_count} no-response records`}>
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
                <div className="student-overview-backdrop" onMouseDown={(event) => event.target === event.currentTarget && setOverview(null)}>
                    <aside className="student-overview-drawer" role="dialog" aria-modal="true" aria-label="Student overview">
                        <h2>Student Overview</h2>
                        <p className="overview-month">{overview.month || 'Loading…'}</p>

                        <section className="overview-student-card">
                            <UserAvatar className="overview-avatar" name={overview.student.name} program={overview.student.program} />
                            <div><strong>{overview.student.name}</strong><small>{overview.student.program} {normalizedYear(overview.student.year_level)}</small></div>
                        </section>

                        <div className="overview-filters" ref={overviewFiltersRef}>
                            <div className="overview-filter-container app-dropdown">
                                <button type="button" className="overview-filter-control app-dropdown-trigger" aria-expanded={overviewOpenFilter === 'gathering'} onClick={() => setOverviewOpenFilter(overviewOpenFilter === 'gathering' ? null : 'gathering')}>
                                    <img src={ResponseIcon} alt="" />
                                    <span>{overviewGathering ? { pm: 'Prayer Meeting', ws: 'Worship Service', tg: 'Thanksgiving', special: 'Special Gathering' }[overviewGathering] : 'Gathering'}</span>
                                    <img className="overview-dropdown-icon" src={DropdownIcon} alt="" />
                                </button>
                                {overviewOpenFilter === 'gathering' && <ul className="overview-filter-menu app-dropdown-menu" role="listbox">
                                    <li role="option" className={`app-dropdown-option ${!overviewGathering ? 'selected' : ''}`} aria-selected={!overviewGathering} onClick={(event) => { event.stopPropagation(); setOverviewGathering(''); setOverviewOpenFilter(null) }}>Gathering</li>
                                    {[['pm', 'Prayer Meeting'], ['ws', 'Worship Service'], ['tg', 'Thanksgiving'], ['special', 'Special Gathering']].map(([value, label]) => <li role="option" className={`app-dropdown-option ${overviewGathering === value ? 'selected' : ''}`} aria-selected={overviewGathering === value} key={value} onClick={(event) => { event.stopPropagation(); setOverviewGathering(value); setOverviewOpenFilter(null) }}>{label}</li>)}
                                </ul>}
                            </div>
                            <div className="overview-filter-container app-dropdown">
                                <button type="button" className="overview-filter-control app-dropdown-trigger" aria-expanded={overviewOpenFilter === 'date'} onClick={() => setOverviewOpenFilter(overviewOpenFilter === 'date' ? null : 'date')}>
                                    <img src={CalendarIcon} alt="" />
                                    <span>{overviewDate ? formatOverviewDate(overviewDate) : 'Date'}</span>
                                    <img className="overview-dropdown-icon" src={DropdownIcon} alt="" />
                                </button>
                                {overviewOpenFilter === 'date' && <ul className="overview-filter-menu app-dropdown-menu" role="listbox">
                                    <li role="option" className={`app-dropdown-option ${!overviewDate ? 'selected' : ''}`} aria-selected={!overviewDate} onClick={(event) => { event.stopPropagation(); setOverviewDate(''); setOverviewOpenFilter(null) }}>Date</li>
                                    {[...new Set((overview?.history || []).map((record) => record.date).filter(Boolean))].map((date) => <li role="option" className={`app-dropdown-option ${overviewDate === date ? 'selected' : ''}`} aria-selected={overviewDate === date} key={date} onClick={(event) => { event.stopPropagation(); setOverviewDate(date); setOverviewOpenFilter(null) }}>{formatOverviewDate(date)}</li>)}
                                </ul>}
                            </div>
                            <div className="overview-filter-container app-dropdown">
                                <button type="button" className="overview-filter-control app-dropdown-trigger" aria-expanded={overviewOpenFilter === 'status'} onClick={() => setOverviewOpenFilter(overviewOpenFilter === 'status' ? null : 'status')}>
                                    <img src={LevelIcon} alt="" />
                                    <span>{overviewStatus === 'present' ? 'Present' : overviewStatus === 'no_response' ? 'No Response' : 'Status'}</span>
                                    <img className="overview-dropdown-icon" src={DropdownIcon} alt="" />
                                </button>
                                {overviewOpenFilter === 'status' && <ul className="overview-filter-menu app-dropdown-menu" role="listbox">
                                    <li role="option" className={`app-dropdown-option ${!overviewStatus ? 'selected' : ''}`} aria-selected={!overviewStatus} onClick={(event) => { event.stopPropagation(); setOverviewStatus(''); setOverviewOpenFilter(null) }}>Status</li>
                                    <li role="option" className={`app-dropdown-option ${overviewStatus === 'present' ? 'selected' : ''}`} aria-selected={overviewStatus === 'present'} onClick={(event) => { event.stopPropagation(); setOverviewStatus('present'); setOverviewOpenFilter(null) }}>Present</li>
                                    <li role="option" className={`app-dropdown-option ${overviewStatus === 'no_response' ? 'selected' : ''}`} aria-selected={overviewStatus === 'no_response'} onClick={(event) => { event.stopPropagation(); setOverviewStatus('no_response'); setOverviewOpenFilter(null) }}>No Response</li>
                                </ul>}
                            </div>
                        </div>

                        <div className="overview-history-header">
                            <h2>Attendance List</h2>
                        </div>
                        <div className="overview-history">

                            {overviewLoading && <p className="overview-state"><LoadingCircle label="Loading attendance history…" /></p>}
                            {overviewError && <p className="overview-state overview-error">{overviewError}</p>}
                            {!overviewLoading && !overviewError && overviewHistory.length === 0 && <p className="overview-state">No attendance records yet.</p>}
                            {!overviewLoading && !overviewError && overviewHistory.length > 0 && (
                                <table><thead><tr><th>Gathering</th><th>Time</th><th>Date</th><th>Location</th><th>Status</th></tr></thead>
                                    <tbody>{overviewHistory.map((record) => <tr key={record.id}><td>{record.gathering}</td><td>{record.time}</td><td>{formatOverviewDate(record.date)}</td><td>{record.location}</td><td><span className={`overview-status ${record.status}`}>{record.status === 'present' ? 'Present' : 'No Response'}</span></td></tr>)}</tbody></table>
                            )}
                        </div>
                    </aside>
                </div>
            )}
        </div>
    )
}

export default Students
