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
import CompletedReportsIcon from '../../../assets/completed-reports-icon.svg'
import IncompleteReportsIcon from '../../../assets/incomplete-reports-icon.svg'
import NoReportsIcon from '../../../assets/no-reports-icon.svg'
import './ChancellorDashboard.css'

const PROGRAM_OPTIONS = ['ACT', 'BAB', 'BSA', 'BSAIS', 'BSIS', 'BSSW']
const YEAR_LEVEL_OPTIONS = ['1', '2', '3', '4']
const YEAR_LEVEL_OPTION_LABELS = {
    1: '1st Year',
    2: '2nd Year',
    3: '3rd Year',
    4: '4th Year',
}
const PROGRAM_DISPLAY_NAMES = {
    ACT: 'ACT',
    BAB: 'BA Broadcasting',
    BSA: 'BS Accounting',
    BSAIS: 'BS Accounting Information Systems',
    BSIS: 'BS Information Systems',
    BSSW: 'BS Social Work',
}
const REPORT_STATUS_LABELS = {
    completed: 'Completed',
    incomplete: 'Incomplete',
    no_report: 'No Report',
}

const WEEKLY_CHART = {
    baseline: 225,
    top: 94,
    barWidth: 60,
    barX: [119, 219, 319, 419],
}

function getWeeklyChartScale(weeks) {
    const largestTotal = Math.max(1, ...weeks.map((week) =>
        (Number(week.completed) || 0) + (Number(week.incomplete) || 0) + (Number(week.no_report) || 0)
    ))
    const roughStep = Math.max(1, largestTotal / 3)
    const magnitude = 10 ** Math.floor(Math.log10(roughStep))
    const step = [1, 2, 5, 10].map((value) => value * magnitude)
        .reduce((closest, value) => Math.abs(value - roughStep) < Math.abs(closest - roughStep) ? value : closest)
    return { maximum: largestTotal, step: Math.max(1, step) }
}

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
    const [yearLevel, setYearLevel] = useState('1')
    const [programYearReporting, setProgramYearReporting] = useState([])
    const [weeklyProgress, setWeeklyProgress] = useState([])
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
    const [isYearOpen, setIsYearOpen] = useState(false)
    const calendarRef = useRef(null)
    const programRef = useRef(null)
    const weekRef = useRef(null)
    const yearRef = useRef(null)
    const query = useMemo(() => {
        const params = new URLSearchParams({
            date: date || getTodayDateValue(),
            year_level: yearLevel,
        })
        if (week !== 'all') params.set('week', week)
        return params.toString()
    }, [date, week, yearLevel])
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
                setProgramYearReporting(data.program_year_reporting || [])
                setWeeklyProgress(data.weekly_progress || [])
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
            if (yearRef.current && !yearRef.current.contains(event.target)) {
                setIsYearOpen(false)
            }
        }
        const closeOnEscape = (event) => {
            if (event.key === 'Escape') {
                setIsCalendarOpen(false)
                setIsProgramOpen(false)
                setIsWeekOpen(false)
                setIsYearOpen(false)
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
    const completionOverview = programYearReporting.length > 0 ? programYearReporting : []
    const weeklyChartScale = getWeeklyChartScale(weeklyProgress)
    const weeklyChartTicks = Array.from(
        { length: Math.floor(weeklyChartScale.maximum / weeklyChartScale.step) + 1 },
        (_, index) => index * weeklyChartScale.step
    ).reverse()

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
                <div className="chancellor-dashboard-content-grid">
                    <div className="chancellor-dashboard-content-column">
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
                        <section className="chancellor-programs-attention" aria-labelledby="chancellor-programs-attention-title">
                            <div className="chancellor-programs-attention-header">
                                <div>
                                    <h2 id="chancellor-programs-attention-title">Programs Needing Attention</h2>
                                    <p>Programs with incomplete or missing reports.</p>
                                </div>
                                <div className="chancellor-year-filter" ref={yearRef}>
                                    <span>Program year</span>
                                    <div className={`chancellor-year-dropdown ${isYearOpen ? 'is-open' : ''}`}>
                                        <button
                                            type="button"
                                            className="chancellor-year-trigger"
                                            aria-haspopup="listbox"
                                            aria-expanded={isYearOpen}
                                            aria-label={`Program year: ${YEAR_LEVEL_OPTION_LABELS[yearLevel]}`}
                                            onClick={() => setIsYearOpen((open) => !open)}
                                        >
                                            <span>{YEAR_LEVEL_OPTION_LABELS[yearLevel]}</span>
                                            <img
                                                className={`chancellor-year-chevron ${isYearOpen ? 'open' : ''}`}
                                                src={DropdownIcon}
                                                alt=""
                                            />
                                        </button>
                                        {isYearOpen && (
                                            <ul className="chancellor-year-menu" role="listbox" aria-label="Program year">
                                                {YEAR_LEVEL_OPTIONS.map((year) => (
                                                    <li key={year}>
                                                        <button
                                                            type="button"
                                                            role="option"
                                                            aria-selected={yearLevel === year}
                                                            className={`chancellor-year-option ${yearLevel === year ? 'selected' : ''}`}
                                                            onClick={() => {
                                                                setYearLevel(year)
                                                                setIsYearOpen(false)
                                                            }}
                                                        >
                                                            {YEAR_LEVEL_OPTION_LABELS[year]}
                                                        </button>
                                                    </li>
                                                ))}
                                            </ul>
                                        )}
                                    </div>
                                </div>
                            </div>
                            <div className="chancellor-program-reporting-scroll">
                                <table className="chancellor-program-reporting-table">
                                    <thead>
                                        <tr>
                                            <th scope="col">Program</th>
                                            {[1, 2, 3, 4].map((weekNumber) => (
                                                <th key={weekNumber} scope="col">Week {weekNumber}</th>
                                            ))}
                                            <th scope="col">Status</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {programYearReporting.map((report) => (
                                            <tr key={report.code}>
                                                <th scope="row">
                                                    <span>{PROGRAM_DISPLAY_NAMES[report.code] || report.name || report.code}</span>
                                                </th>
                                                {(report.weekly_statuses || []).map((status, index) => (
                                                    <td key={`${report.code}-${index}`}>
                                                        <span
                                                            className={`chancellor-report-status-icon chancellor-report-status-icon--${status}`}
                                                            role="img"
                                                            aria-label={REPORT_STATUS_LABELS[status] || 'No Report'}
                                                        >
                                                            <img
                                                                src={status === 'completed'
                                                                    ? CompletedReportsIcon
                                                                    : status === 'incomplete'
                                                                        ? IncompleteReportsIcon
                                                                        : NoReportsIcon}
                                                                alt=""
                                                            />
                                                        </span>
                                                    </td>
                                                ))}
                                                <td>
                                                    <span className={`chancellor-report-status-label chancellor-report-status-label--${report.status}`}>
                                                        <span />
                                                        {REPORT_STATUS_LABELS[report.status] || 'No Report'}
                                                    </span>
                                                </td>
                                            </tr>
                                        ))}
                                        {programYearReporting.length === 0 && (
                                            <tr>
                                                <td className="chancellor-program-reporting-empty" colSpan="6">
                                                    No program reporting data is available.
                                                </td>
                                            </tr>
                                        )}
                                    </tbody>
                                </table>
                            </div>
                            <div className="chancellor-program-reporting-footer">
                                <div className="chancellor-program-reporting-legend" aria-label="Report status legend">
                                    {Object.entries(REPORT_STATUS_LABELS).map(([status, label]) => (
                                        <span key={status}>
                                            <span className={`chancellor-report-status-icon chancellor-report-status-icon--${status}`} aria-hidden="true">
                                                <img
                                                    src={status === 'completed'
                                                        ? CompletedReportsIcon
                                                        : status === 'incomplete'
                                                            ? IncompleteReportsIcon
                                                            : NoReportsIcon}
                                                    alt=""
                                                />
                                            </span>
                                            {label}
                                        </span>
                                    ))}
                                </div>
                                <span>Showing {programYearReporting.length} programs</span>
                            </div>
                        </section>
                    </div>
                    <aside className="chancellor-dashboard-side-column">
                        <section className="chancellor-progress-by-week" aria-labelledby="chancellor-progress-by-week-title">
                            <h2 id="chancellor-progress-by-week-title">Progress by Week</h2>
                            <p>Number of programs with completed reports</p>
                            <svg className="chancellor-weekly-chart" viewBox="0 0 616 275" role="img" aria-labelledby="chancellor-weekly-chart-title chancellor-weekly-chart-description">
                                <title id="chancellor-weekly-chart-title">Progress by Week</title>
                                <desc id="chancellor-weekly-chart-description">
                                    {weeklyProgress.map((week) => `${week.label}: ${week.completed || 0} completed, ${week.incomplete || 0} in progress, ${week.no_report || 0} no report`).join('; ') || 'No weekly progress data available'}
                                </desc>
                                {weeklyChartTicks.map((tick) => (
                                    <text key={tick} className="chancellor-weekly-axis-label" x="58" y={220 - tick / weeklyChartScale.maximum * 123}>
                                        {tick}
                                    </text>
                                ))}
                                <line className="chancellor-weekly-axis-line" x1="96" x2="500" y1={WEEKLY_CHART.baseline} y2={WEEKLY_CHART.baseline} />
                                {weeklyProgress.slice(0, 4).map((week, index) => {
                                    let bottom = WEEKLY_CHART.baseline
                                    const segments = [
                                        ['completed', week.completed],
                                        ['in-progress', week.incomplete],
                                        ['no-report', week.no_report],
                                    ]
                                    return (
                                        <g key={week.label}>
                                            {segments.map(([status, count]) => {
                                                const height = Math.max(0, Number(count) || 0) / weeklyChartScale.maximum * (WEEKLY_CHART.baseline - WEEKLY_CHART.top)
                                                bottom -= height
                                                return <rect key={status} className={`chancellor-weekly-segment--${status}`} x={WEEKLY_CHART.barX[index]} y={bottom} width={WEEKLY_CHART.barWidth} height={height} />
                                            })}
                                            <text className="chancellor-weekly-label" x={WEEKLY_CHART.barX[index] + WEEKLY_CHART.barWidth / 2} y="257">{week.label}</text>
                                        </g>
                                    )
                                })}
                                <g className="chancellor-weekly-legend">
                                    <circle className="chancellor-weekly-segment--completed" cx="530" cy="119" r="5.5" />
                                    <text x="549" y="123">Completed</text>
                                    <circle className="chancellor-weekly-segment--in-progress" cx="530" cy="153" r="5.5" />
                                    <text x="549" y="157">In Progress</text>
                                    <circle className="chancellor-weekly-segment--no-report" cx="530" cy="187" r="5.5" />
                                    <text className="chancellor-weekly-no-report-label" x="549" y="191">No Report</text>
                                </g>
                            </svg>
                        </section>
                        <section className="chancellor-program-completion-overview" aria-labelledby="chancellor-program-completion-overview-title">
                            <h2 id="chancellor-program-completion-overview-title">Program Completion Overview</h2>
                            <p>Completion rate per program</p>
                            <ul className="chancellor-program-completion-list">
                                {completionOverview.map((program) => (
                                    <li key={program.code} className="chancellor-program-completion-row">
                                        <div className="chancellor-program-completion-header">
                                            <span>{PROGRAM_DISPLAY_NAMES[program.code] || program.name || program.code}</span>
                                            <strong>{program.completion || 0}%</strong>
                                        </div>
                                        <div className="chancellor-program-completion-track">
                                            <span style={{ width: `${program.completion || 0}%` }} />
                                        </div>
                                    </li>
                                ))}
                            </ul>
                        </section>
                    </aside>
                </div>
                {loadError && <p className="chancellor-dashboard-error" role="alert">{loadError}</p>}
            </main>
        </div>
    )
}

export default ChancellorDashboard
