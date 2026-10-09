import { useEffect, useMemo, useRef, useState } from 'react'
import Sidebar from '../../../components/Sidebar/Sidebar'
import Header from '../../../components/Header/Header'
import Calendar from '../../../components/Calendar/Calendar'
import { request } from '../../../api/request'
import CalendarIcon from '../../../assets/calendar-icon.svg'
import AllProgramsIcon from '../../../assets/all-programs-icon.svg'
import AllWeeksIcon from '../../../assets/all-weeks-icon.svg'
import DropdownIcon from '../../../assets/drop-down-icon.svg'
import CompletedProgramsIcon from '../../../assets/completed-programs-icon.svg'
import InProgressProgramsIcon from '../../../assets/in-progress-programs-icon.svg'
import NoReportProgramsIcon from '../../../assets/no-report-programs-icon.svg'
import TotalProgramsIcon from '../../../assets/total-programs-icon.svg'
import './ChancellorDashboard.css'

const PROGRAM_OPTIONS = ['ACT', 'BAB', 'BSA', 'BSAIS', 'BSIS', 'BSSW']

function getTodayDateValue() {
    const today = new Date()
    return `${today.getFullYear()}-${String(today.getMonth() + 1).padStart(2, '0')}-${String(today.getDate()).padStart(2, '0')}`
}

function fromDateValue(value) {
    const [year, month, day] = value.split('-').map(Number)
    return new Date(year, month - 1, day)
}

function toDateValue(date) {
    return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`
}

function ChancellorDashboard() {
    const [date, setDate] = useState('')
    const [program, setProgram] = useState('')
    const [week, setWeek] = useState('all')
    const [summary, setSummary] = useState({
        completed: 0,
        incomplete: 0,
        no_report: 0,
        total_programs: 0,
    })
    const [overallProgress, setOverallProgress] = useState({
        percentage: 0,
        tally: '0 of 0 programs completed',
    })
    const [loadError, setLoadError] = useState('')
    const [isCalendarOpen, setIsCalendarOpen] = useState(false)
    const [isProgramOpen, setIsProgramOpen] = useState(false)
    const [isWeekOpen, setIsWeekOpen] = useState(false)
    const calendarRef = useRef(null)
    const programRef = useRef(null)
    const weekRef = useRef(null)
    const query = useMemo(() => {
        const params = new URLSearchParams({ date: date || getTodayDateValue() })
        if (week !== 'all') params.set('week', week)
        return params.toString()
    }, [date, week])
    const selectedDate = date ? fromDateValue(date) : null

    useEffect(() => {
        const controller = new AbortController()

        async function loadDashboard() {
            setLoadError('')

            try {
                const data = await request(`/dashboard?${query}`, { signal: controller.signal })
                setSummary(data.summary || {
                    completed: 0,
                    incomplete: 0,
                    no_report: 0,
                    total_programs: 0,
                })
                setOverallProgress(data.overall_progress || {
                    percentage: 0,
                    tally: '0 of 0 programs completed',
                })
            } catch (error) {
                if (error.name !== 'AbortError') {
                    setLoadError(error.message || 'Could not load dashboard filters.')
                }
            }
        }

        loadDashboard()
        return () => controller.abort()
    }, [query])

    useEffect(() => {
        const closeOutside = (event) => {
            if (calendarRef.current && !calendarRef.current.contains(event.target)) {
                setIsCalendarOpen(false)
            }
            if (programRef.current && !programRef.current.contains(event.target)) {
                setIsProgramOpen(false)
            }
            if (weekRef.current && !weekRef.current.contains(event.target)) {
                setIsWeekOpen(false)
            }
        }
        const closeOnEscape = (event) => {
            if (event.key === 'Escape') {
                setIsCalendarOpen(false)
                setIsProgramOpen(false)
                setIsWeekOpen(false)
            }
        }

        document.addEventListener('mousedown', closeOutside)
        document.addEventListener('keydown', closeOnEscape)
        return () => {
            document.removeEventListener('mousedown', closeOutside)
            document.removeEventListener('keydown', closeOnEscape)
        }
    }, [])

    const progressPercentage = Math.min(100, Math.max(0, Number(overallProgress.percentage) || 0))

    return (
        <div className="chancellor-dashboard">
            <Sidebar />
            <main className="chancellor-dashboard-main">
                <Header
                    title="Dashboard"
                    subtitle="Monitor attendance reporting progress across all programs."
                    className="chancellor-dashboard-header"
                />
                <section className="chancellor-dashboard-filters" aria-label="Dashboard filters">
                    <div className={`chancellor-filter-dropdown ${isCalendarOpen ? 'is-open' : ''}`} ref={calendarRef}>
                        <button
                            type="button"
                            className="chancellor-filter-trigger"
                            aria-expanded={isCalendarOpen}
                            onClick={() => {
                                setIsCalendarOpen((open) => !open)
                                setIsProgramOpen(false)
                            }}
                        >
                            <img className="chancellor-filter-icon" src={CalendarIcon} alt="" />
                            <span>{date ? selectedDate.toLocaleDateString(undefined, {
                                month: 'short',
                                day: 'numeric',
                                year: 'numeric',
                            }) : 'Date'}</span>
                            <img
                                className={`chancellor-filter-chevron ${isCalendarOpen ? 'open' : ''}`}
                                src={DropdownIcon}
                                alt=""
                            />
                        </button>
                        {isCalendarOpen && (
                            <div className="chancellor-calendar-menu">
                                <Calendar
                                    value={selectedDate}
                                    initialMonth={selectedDate || new Date()}
                                    onChange={(nextDate) => {
                                        setDate(toDateValue(nextDate))
                                        setIsCalendarOpen(false)
                                    }}
                                />
                            </div>
                        )}
                    </div>
                    <div className={`chancellor-filter-dropdown ${isProgramOpen ? 'is-open' : ''}`} ref={programRef}>
                        <button
                            type="button"
                            className="chancellor-filter-trigger"
                            aria-expanded={isProgramOpen}
                            onClick={() => {
                                setIsProgramOpen((open) => !open)
                                setIsCalendarOpen(false)
                            }}
                        >
                            <img className="chancellor-filter-icon" src={AllProgramsIcon} alt="" />
                            <span className="chancellor-program-label">{program || 'All Programs'}</span>
                            <img
                                className={`chancellor-filter-chevron ${isProgramOpen ? 'open' : ''}`}
                                src={DropdownIcon}
                                alt=""
                            />
                        </button>
                        {isProgramOpen && (
                            <ul className="chancellor-program-menu" role="listbox" aria-label="Programs">
                                <li>
                                    <button
                                        type="button"
                                        role="option"
                                        aria-selected={!program}
                                        className={`chancellor-program-option ${!program ? 'selected' : ''}`}
                                        onClick={() => {
                                            setProgram('')
                                            setIsProgramOpen(false)
                                        }}
                                    >
                                        All Programs
                                    </button>
                                </li>
                                {PROGRAM_OPTIONS.map((code) => (
                                    <li key={code}>
                                        <button
                                            type="button"
                                            role="option"
                                            aria-selected={program === code}
                                            className={`chancellor-program-option ${program === code ? 'selected' : ''}`}
                                            onClick={() => {
                                                setProgram(code)
                                                setIsProgramOpen(false)
                                            }}
                                        >
                                            {code}
                                        </button>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </div>
                    
                    <div className={`chancellor-filter-dropdown ${isWeekOpen ? 'is-open' : ''}`} ref={weekRef}>
                        <button
                            type="button"
                            className="chancellor-filter-trigger"
                            aria-expanded={isWeekOpen}
                            onClick={() => {
                                setIsWeekOpen((open) => !open)
                                setIsCalendarOpen(false)
                                setIsProgramOpen(false)
                            }}
                        >
                            <img className="chancellor-filter-icon" id="all-weeks-icon" src={AllWeeksIcon} alt="" />
                            <span>{week === 'all' ? 'All Weeks' : `Week ${week}`}</span>
                            <img
                                className={`chancellor-filter-chevron ${isWeekOpen ? 'open' : ''}`}
                                src={DropdownIcon}
                                alt=""
                            />
                        </button>
                        {isWeekOpen && (
                            <ul className="chancellor-program-menu chancellor-week-menu" role="listbox" aria-label="Weeks">
                                {['all', '1', '2', '3', '4'].map((weekOption) => (
                                    <li key={weekOption}>
                                        <button
                                            type="button"
                                            role="option"
                                            aria-selected={week === weekOption}
                                            className={`chancellor-program-option ${week === weekOption ? 'selected' : ''}`}
                                            onClick={() => {
                                                setWeek(weekOption)
                                                setIsWeekOpen(false)
                                            }}
                                        >
                                            {weekOption === 'all' ? 'All Weeks' : `Week ${weekOption}`}
                                        </button>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </div>
                   
                </section>
                <section className="chancellor-summary-cards" aria-label="Reporting summary">
                    <article className="chancellor-summary-card chancellor-summary-card--completed">
                        <span className="chancellor-summary-icon">
                            <img src={CompletedProgramsIcon} alt="" />
                        </span>
                        <div className="chancellor-summary-content">
                            <span className="chancellor-summary-label">Completed</span>
                            <strong>{summary.completed}</strong>
                            <p>Programs with full reports</p>
                        </div>
                    </article>
                    <article className="chancellor-summary-card chancellor-summary-card--in-progress">
                        <span className="chancellor-summary-icon">
                            <img src={InProgressProgramsIcon} alt="" />
                        </span>
                        <div className="chancellor-summary-content">
                            <span className="chancellor-summary-label">In Progress</span>
                            <strong>{summary.incomplete}</strong>
                            <p>Programs with partial reports</p>
                        </div>
                    </article>
                    <article className="chancellor-summary-card chancellor-summary-card--no-report">
                        <span className="chancellor-summary-icon">
                            <img src={NoReportProgramsIcon} alt="" />
                        </span>
                        <div className="chancellor-summary-content">
                            <span className="chancellor-summary-label">No Report</span>
                            <strong>{summary.no_report}</strong>
                            <p>Programs with no submission</p>
                        </div>
                    </article>
                    
                    <article className="chancellor-summary-card chancellor-summary-card--total">
                        <span className="chancellor-summary-icon">
                            <img src={TotalProgramsIcon} alt="" />
                        </span>
                        <div className="chancellor-summary-content">
                            <span className="chancellor-summary-label">Total Programs</span>
                            <strong>{summary.total_programs}</strong>
                            <p>Across all departments</p>
                        </div>
                    </article>
                   
                </section>
                <section className="chancellor-overall-progress" aria-labelledby="chancellor-overall-progress-title">
                    <div className="chancellor-overall-progress-heading">
                        <h2 id="chancellor-overall-progress-title">Overall Reporting Progress</h2>
                        <p>Total attendance reports from all programs.</p>
                    </div>
                    <div className="chancellor-overall-progress-value">
                        <strong>{progressPercentage}%</strong>
                        <span>{overallProgress.tally}</span>
                    </div>
                    <div
                        className="chancellor-overall-progress-track"
                        role="progressbar"
                        aria-label="Overall reporting progress"
                        aria-valuemin="0"
                        aria-valuemax="100"
                        aria-valuenow={progressPercentage}
                        aria-valuetext={`${progressPercentage}% complete; ${overallProgress.tally}`}
                    >
                        <span style={{ width: `${progressPercentage}%` }} />
                    </div>
                </section>
                {loadError && <p className="chancellor-dashboard-error" role="alert">{loadError}</p>}
            </main>
        </div>
    )
}

export default ChancellorDashboard
