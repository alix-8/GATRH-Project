import { useEffect, useMemo, useRef, useState } from 'react'
import Sidebar from '../../../components/layout/Sidebar'
import Header from '../../../components/layout/Header'
import { request } from '../../../api/request'
import useLiveRefresh from '../../../hooks/useLiveRefresh'
import backendPercentage from '../../../utils/backendPercentage'
import DropdownIcon from '../../../assets/icons/drop-down-icon.svg'
import CompletedProgramsIcon from '../../../assets/icons/completed-programs-icon.svg'
import InProgressProgramsIcon from '../../../assets/icons/in-progress-programs-icon.svg'
import NoReportProgramsIcon from '../../../assets/icons/no-report-programs-icon.svg'
import TotalProgramsIcon from '../../../assets/icons/total-programs-icon.svg'
import CompletedReportsIcon from '../../../assets/icons/completed-reports-icon.svg'
import IncompleteReportsIcon from '../../../assets/icons/incomplete-reports-icon.svg'
import NoReportsIcon from '../../../assets/icons/no-reports-icon.svg'
import './Dashboard.css'

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
}

function getTodayDateValue() {
    const today = new Date()
    return `${today.getFullYear()}-${String(today.getMonth() + 1).padStart(2, '0')}-${String(today.getDate()).padStart(2, '0')}`
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

function ChancellorDashboard() {
    const [yearLevel, setYearLevel] = useState('1')
    const [requestedYearLevel, setRequestedYearLevel] = useState('1')
    const [programReporting, setProgramReporting] = useState([])
    const [programYearReporting, setProgramYearReporting] = useState([])
    const [weeklyProgress, setWeeklyProgress] = useState([])
    const [reportingWeeks, setReportingWeeks] = useState([])
    const [summary, setSummary] = useState({
        completed: 0,
        incomplete: 0,
        no_report: 0,
        total_programs: 0,
    })
    const [overallProgress, setOverallProgress] = useState({
        percentage: 0,
        tally: '0 of 0 expected responses recorded',
    })
    const [loadError, setLoadError] = useState('')
    const [isYearOpen, setIsYearOpen] = useState(false)
    const [pollTick, setPollTick] = useState(0)
    useLiveRefresh(() => setPollTick((current) => current + 1), 30_000)
    const yearRef = useRef(null)
    const query = useMemo(() => {
        const params = new URLSearchParams({
            date: getTodayDateValue(),
            year_level: requestedYearLevel,
        })
        return params.toString()
    }, [requestedYearLevel])

    useEffect(() => {
        const timer = window.setTimeout(() => setRequestedYearLevel(yearLevel), 300)
        return () => window.clearTimeout(timer)
    }, [yearLevel])

    useEffect(() => {
        const controller = new AbortController()

        async function loadDashboard() {
            setLoadError('')

            try {
                const data = await request(`/dashboard?${query}`, { signal: controller.signal })
                if (controller.signal.aborted) return
                setSummary(data.summary || {
                    completed: 0,
                    incomplete: 0,
                    no_report: 0,
                    total_programs: 0,
                })
                setOverallProgress(data.overall_progress || {
                    percentage: 0,
                    tally: '0 of 0 expected responses recorded',
                })
                setProgramReporting(data.program_reporting || [])
                setProgramYearReporting(data.program_year_reporting || [])
                setWeeklyProgress(data.weekly_progress || [])
                setReportingWeeks(data.reporting_weeks || [])
            } catch (error) {
                if (error.name !== 'AbortError') {
                    setLoadError(error.message || 'Could not load dashboard filters.')
                }
            }
        }

        loadDashboard()
        return () => {
            controller.abort()
        }
    }, [query, pollTick])

    useEffect(() => {
        const closeOutside = (event) => {
            if (yearRef.current && !yearRef.current.contains(event.target)) {
                setIsYearOpen(false)
            }
        }
        const closeOnEscape = (event) => {
            if (event.key === 'Escape') {
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

    const progressPercentage = backendPercentage(overallProgress.percentage)
    const completionOverview = programReporting
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
                <section className="chancellor-summary-cards" aria-label="Reporting summary">
                    <article className="chancellor-summary-card chancellor-summary-card--completed">
                        <span className="chancellor-summary-icon">
                            <img src={CompletedProgramsIcon} alt="" />
                        </span>
                        <div className="chancellor-summary-content">
                            <span className="chancellor-summary-label">Completed Weeks</span>
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
                                <p>Monthly student responses for the selected program year.</p>
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
                                aria-valuetext={`${progressPercentage}% of expected student responses recorded; ${overallProgress.tally}`}
                            >
                                <span style={{ width: `${progressPercentage}%` }} />
                            </div>
                        </section>
                        <section className="chancellor-programs-attention" aria-labelledby="chancellor-programs-attention-title">
                            <div className="chancellor-programs-attention-header">
                                <div>
                                    <h2 id="chancellor-programs-attention-title">Programs Needing Attention</h2>
                                    <p>Monthly status with a weekly reporting breakdown.</p>
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
                                            {reportingWeeks.map((week) => (
                                                <th key={week.week_start} scope="col" title={week.range_label}>
                                                    {week.label}
                                                </th>
                                            ))}
                                            <th scope="col">Status</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {programYearReporting.map((report) => (
                                            <tr key={report.code}>
                                                <th scope="row">
                                                    <span>
                                                        {PROGRAM_DISPLAY_NAMES[report.code] || report.name || report.code}{' '}
                                                        {report.year_level ?? yearLevel}
                                                    </span>
                                                </th>
                                                {reportingWeeks.map((week, index) => {
                                                    const status = report.weekly_statuses?.[index] || 'no_report'
                                                    return (
                                                    <td key={`${report.code}-${week.week_start}`} title={`${week.range_label}: ${REPORT_STATUS_LABELS[status] || 'No Report'}`}>
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
                                                    )
                                                })}
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
                                                <td className="chancellor-program-reporting-empty" colSpan={reportingWeeks.length + 2}>
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
                                    {weeklyProgress.map((week) => `${week.label} (${week.range_label}): ${week.completed || 0} completed, ${week.incomplete || 0} in progress, ${week.no_report || 0} no report`).join('; ') || 'No weekly progress data available'}
                                </desc>
                                {weeklyChartTicks.map((tick) => (
                                    <text key={tick} className="chancellor-weekly-axis-label" x="58" y={220 - tick / weeklyChartScale.maximum * 123}>
                                        {tick}
                                    </text>
                                ))}
                                <line className="chancellor-weekly-axis-line" x1="96" x2="500" y1={WEEKLY_CHART.baseline} y2={WEEKLY_CHART.baseline} />
                                {weeklyProgress.map((week, index) => {
                                    let bottom = WEEKLY_CHART.baseline
                                    const slotWidth = 400 / Math.max(1, weeklyProgress.length)
                                    const barWidth = Math.min(60, slotWidth * 0.6)
                                    const barX = 99 + index * slotWidth + (slotWidth - barWidth) / 2
                                    const segments = [
                                        ['completed', week.completed],
                                        ['in-progress', week.incomplete],
                                        ['no-report', week.no_report],
                                    ]
                                    return (
                                        <g key={week.label}>
                                            <title>{`${week.label}: ${week.range_label}`}</title>
                                            {segments.map(([status, count]) => {
                                                const height = Math.max(0, Number(count) || 0) / weeklyChartScale.maximum * (WEEKLY_CHART.baseline - WEEKLY_CHART.top)
                                                bottom -= height
                                                return <rect key={status} className={`chancellor-weekly-segment--${status}`} x={barX} y={bottom} width={barWidth} height={height} />
                                            })}
                                            <text className="chancellor-weekly-label" x={barX + barWidth / 2} y="257">{week.label}</text>
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
                            <h2 id="chancellor-program-completion-overview-title">Program Response Overview</h2>
                            <p>Monthly student responses per program for the selected year</p>
                            <ul className="chancellor-program-completion-list">
                                {completionOverview.map((program) => (
                                    <li key={program.code} className="chancellor-program-completion-row">
                                        <div className="chancellor-program-completion-header">
                                            <span>{PROGRAM_DISPLAY_NAMES[program.code] || program.name || program.code}</span>
                                            <strong>{backendPercentage(program.completion)}%</strong>
                                        </div>
                                        <div className="chancellor-program-completion-track" role="progressbar" aria-label={`${program.code} student response rate`} aria-valuemin="0" aria-valuemax="100" aria-valuenow={backendPercentage(program.completion)}>
                                            <span style={{ width: `${backendPercentage(program.completion)}%` }} />
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
