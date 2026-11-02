import { Fragment, useEffect, useMemo, useState } from 'react'
import Header from '../../../components/layout/Header'
import Sidebar from '../../../components/layout/Sidebar'
import CompletedIcon from '../../../assets/icons/completed-reports-icon.svg'
import IncompleteIcon from '../../../assets/icons/incomplete-reports-icon.svg'
import NoReportsIcon from '../../../assets/icons/no-reports-icon.svg'
import RecordedNoResponseIcon from '../../../assets/icons/recorded-no-response-icon.svg'
import { request } from '../../../api/request'
import useLiveRefresh from '../../../hooks/useLiveRefresh'
import useLoginSkeleton from '../../../hooks/useLoginSkeleton'
import backendPercentage from '../../../utils/backendPercentage'
import MonitoringSkeleton from './skeleton/MonitoringSkeleton'
import MonitoringCourseDetailModal from './MonitoringCourseDetailModal'
import './Monitoring.css'

const GATHERING_LABELS = {
    pm: 'PM',
    ws: 'WS',
    tg: 'TG',
}

const PROGRAM_DISPLAY_NAMES = {
    ACT: 'Associate in Computer Technology',
    BSIS: 'BS Information Systems',
    BSSW: 'BS Social Work',
    BSA: 'BS Accountancy',
    BSAIS: 'BS Accountancy Information System',
    BAB: 'BA Broadcasting',
}

const PROGRAM_ORDER = ['ACT', 'BSIS', 'BSSW', 'BSA', 'BSAIS', 'BAB']
const PROGRAM_TYPES = {
    ACT: 'Computer Technology',
    BAB: 'Broadcasting',
    BSA: 'Accountancy',
    BSAIS: 'Accountancy Information System',
    BSIS: 'Information Systems',
    BSSW: 'Social Work',
}
const GATHERING_TYPES = ['pm', 'ws', 'tg']
const EMPTY_COURSES = []
const ATTENDANCE_STATUS_HELP = {
    completed: {
        label: 'Completed',
        definition: 'All expected students responded.',
    },
    incomplete: {
        label: 'Incomplete',
        definition: 'Some student responses are still missing.',
    },
    no_response: {
        label: 'No Response',
        definition: 'Attendance was recorded, but no student responded.',
    },
    no_report: {
        label: 'No Report',
        definition: 'No attendance has been recorded.',
    },
}

function formatDate(value, options = { month: 'short', day: 'numeric', year: 'numeric' }) {
    if (!value) return '—'
    const [year, month, day] = value.slice(0, 10).split('-').map(Number)
    return new Date(year, month - 1, day).toLocaleDateString('en-US', options)
}

function CoordinatorMonitoring() {
    const showLoginSkeleton = useLoginSkeleton()
    const today = new Date()
    const currentMonth = `${today.getFullYear()}-${String(today.getMonth() + 1).padStart(2, '0')}`
    const [selectedMonth, setSelectedMonth] = useState(currentMonth)
    const [monitoring, setMonitoring] = useState(null)
    const [hasLoadedMonitoring, setHasLoadedMonitoring] = useState(false)
    const [collapsedPrograms, setCollapsedPrograms] = useState([])
    const [settledQuery, setSettledQuery] = useState(null)
    const [requestError, setRequestError] = useState(null)
    const [activeCourse, setActiveCourse] = useState(null)
    const [pollTick, setPollTick] = useState(0)
    useLiveRefresh(() => setPollTick((current) => current + 1), 30_000)

    useEffect(() => {
        const controller = new AbortController()

        const loadMonitoring = () => {
            const [year, month] = selectedMonth.split('-')
            const params = new URLSearchParams({ year: String(Number(year)), month: String(Number(month)) })
            request(`/monitoring?${params}`, { signal: controller.signal })
                .then((data) => {
                    if (controller.signal.aborted) return
                    setMonitoring(data)
                    setHasLoadedMonitoring(true)
                    setRequestError(null)
                })
                .catch((loadError) => {
                    if (loadError.name !== 'AbortError') {
                        setRequestError({
                            message: loadError.message || 'Unable to load monitoring data.',
                        })
                    }
                })
                .finally(() => {
                    if (!controller.signal.aborted) setSettledQuery(selectedMonth)
                })
        }
        loadMonitoring()

        return () => {
            controller.abort()
        }
    }, [pollTick, selectedMonth])

    const loading = settledQuery !== selectedMonth
    const error = requestError?.message || ''
    const courses = monitoring?.courses ?? EMPTY_COURSES
    const weeks = monitoring?.weeks || []
    const gatheringTypes = GATHERING_TYPES
    const groupedCourses = useMemo(() => courses.reduce((groups, course) => {
        const key = course.program
        if (!groups[key]) groups[key] = []
        groups[key].push(course)
        return groups
    }, {}), [courses])
    const orderedProgramGroups = Object.entries(groupedCourses).sort(([first], [second]) => {
        const firstOrder = PROGRAM_ORDER.indexOf(first.toUpperCase())
        const secondOrder = PROGRAM_ORDER.indexOf(second.toUpperCase())
        return (firstOrder === -1 ? PROGRAM_ORDER.length : firstOrder)
            - (secondOrder === -1 ? PROGRAM_ORDER.length : secondOrder)
    })

    const toggleProgram = (programCode) => {
        setCollapsedPrograms((current) => current.includes(programCode)
            ? current.filter((code) => code !== programCode)
            : [...current, programCode]
        )
    }

    const openCourseDetails = (course) => {
        setActiveCourse(course)
    }

    const closeCourseDetails = () => {
        setActiveCourse(null)
    }

    const changeMonth = (month) => {
        if (month > currentMonth || month < '2000-01' || month === selectedMonth) return
        setSelectedMonth(month)
        setMonitoring(null)
        setRequestError(null)
        setSettledQuery(null)
        closeCourseDetails()
    }

    const navigateMonth = (direction) => {
        const [year, month] = selectedMonth.split('-').map(Number)
        const next = new Date(year, month - 1 + direction, 1)
        changeMonth(`${next.getFullYear()}-${String(next.getMonth() + 1).padStart(2, '0')}`)
    }
    const monthLabel = formatDate(`${selectedMonth}-01`, { month: 'long', year: 'numeric' })

    if (loading && !error && !hasLoadedMonitoring && showLoginSkeleton) return <MonitoringSkeleton month={selectedMonth} />

    return (
        <div className="coordinator-dashboard">
            <Sidebar />
            <main className="coordinator-dashboard-main">
                <Header
                    title="Monitoring"
                    subtitle="Monitor attendance reporting progress across all programs."
                    className="coordinator-dashboard-header"
                />

                <section
                    className="monitoring-content"
                    aria-label="Coordinator monitoring"
                >
                    <nav className="monitoring-month-navigation" aria-label="Monitoring month">
                        <div className="monitoring-month-controls">
                            <button type="button" onClick={() => navigateMonth(-1)} disabled={selectedMonth <= '2000-01'} aria-label="Previous month">‹</button>
                            <strong aria-live="polite">{monthLabel}</strong>
                            <button type="button" onClick={() => navigateMonth(1)} disabled={selectedMonth >= currentMonth} aria-label="Next month">›</button>
                        </div>
                    </nav>
                    {loading && !error && <MonitoringSkeleton month={selectedMonth} tableOnly />}
                    {error && <p className="monitoring-message monitoring-error" role="alert">{error}</p>}
                    {!error && !loading && courses.length === 0 && (
                        <p className="monitoring-message">No program attendance data is available.</p>
                    )}

                    {!error && !loading && courses.length > 0 && (
                        <div className="monitoring-table-scroll" aria-busy={loading}>
                            <table
                                className="monitoring-table"
                                style={{ '--monitoring-status-width': `${54 / Math.max(1, weeks.length * gatheringTypes.length)}%` }}
                            >
                                <colgroup>
                                    <col className="monitoring-program-column" />
                                </colgroup>
                                <colgroup>
                                    <col className="monitoring-type-column" />
                                </colgroup>
                                {weeks.map((item) => (
                                    <colgroup key={item.number}>
                                        {gatheringTypes.map((type) => (
                                            <col key={type} className="monitoring-status-column" />
                                        ))}
                                    </colgroup>
                                ))}
                                <colgroup>
                                    <col className="monitoring-progress-column" />
                                </colgroup>
                                <colgroup>
                                    <col className="monitoring-action-column" />
                                </colgroup>
                                <thead>
                                    <tr className="monitoring-table-heading">
                                        <th className="monitoring-program-heading" scope="col"><div className="monitoring-heading-card">Program</div></th>
                                        <th className="monitoring-type-heading" scope="col"><div className="monitoring-heading-card">Type</div></th>
                                        {weeks.map((item) => (
                                            <th
                                                key={item.number}
                                                className="monitoring-week-heading"
                                                scope="colgroup"
                                                colSpan={gatheringTypes.length}
                                                title={`${item.label}: ${formatDate(item.start)} – ${formatDate(item.end)}`}
                                            >
                                                <div className="monitoring-heading-card monitoring-week-card">
                                                    <span className="monitoring-week-title">{item.label}</span>
                                                    <small>{item.description}</small>
                                                    <span className="monitoring-week-gatherings">
                                                        {gatheringTypes.map((type) => (
                                                            <span
                                                                key={type}
                                                                title={`${GATHERING_LABELS[type]} · ${formatDate(item.gathering_ranges?.[type]?.start || item.start)} – ${formatDate(item.gathering_ranges?.[type]?.end || item.end)}`}
                                                            >{GATHERING_LABELS[type]}</span>
                                                        ))}
                                                    </span>
                                                </div>
                                            </th>
                                        ))}
                                        <th className="monitoring-progress-heading" scope="col"><div className="monitoring-heading-card">Progress</div></th>
                                        <th className="monitoring-action-heading" scope="col"><div className="monitoring-heading-card">Action</div></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {orderedProgramGroups.map(([programCode, programCourses]) => (
                                        <Fragment key={programCode}>
                                            <tr className="monitoring-program-spacer" aria-hidden="true">
                                                <td colSpan={weeks.length * gatheringTypes.length + 4} />
                                            </tr>
                                            <tr className={`monitoring-program-group ${collapsedPrograms.includes(programCode) ? 'collapsed' : ''}`}>
                                                <th colSpan={weeks.length * gatheringTypes.length + 4}>
                                                    <button
                                                        type="button"
                                                        className="monitoring-program-toggle"
                                                        aria-expanded={!collapsedPrograms.includes(programCode)}
                                                        onClick={() => toggleProgram(programCode)}
                                                    >
                                                        <span
                                                            className={`monitoring-program-arrow ${collapsedPrograms.includes(programCode) ? 'collapsed' : ''}`}
                                                            aria-hidden="true"
                                                        />
                                                        {PROGRAM_DISPLAY_NAMES[programCode.toUpperCase()] || programCode}
                                                    </button>
                                                </th>
                                            </tr>
                                            {!collapsedPrograms.includes(programCode) && programCourses.map((course, courseIndex) => (
                                                <tr
                                                    key={`${course.program}-${course.year_level}`}
                                                    className={[
                                                        courseIndex === 0 ? 'monitoring-course-first' : '',
                                                        courseIndex === programCourses.length - 1 ? 'monitoring-course-last' : '',
                                                    ].filter(Boolean).join(' ')}
                                                >
                                                    <td className="monitoring-course-name">{course.label}</td>
                                                    <td className="monitoring-course-type">
                                                        <span
                                                            className={`monitoring-course-type-pill monitoring-course-type-pill--${course.program.toLowerCase()}`}
                                                            title={PROGRAM_TYPES[course.program.toUpperCase()] || course.program}
                                                        >
                                                            {PROGRAM_TYPES[course.program.toUpperCase()] || course.program}
                                                        </span>
                                                    </td>
                                                    {weeks.flatMap((item) => {
                                                        const courseWeek = course.weeks.find((entry) => entry.number === item.number)
                                                        return gatheringTypes.map((type) => {
                                                            const itemStatus = courseWeek?.gatherings.find((entry) => entry.type === type)
                                                            const hasAttendance = Boolean(itemStatus?.has_attendance)
                                                            const status = itemStatus?.status === 'no_report' && hasAttendance
                                                                ? 'no_response'
                                                                : itemStatus?.status || 'no_report'
                                                            const noAttendanceRecorded = status === 'no_report'
                                                            const statusHelp = ATTENDANCE_STATUS_HELP[status] || ATTENDANCE_STATUS_HELP.no_report
                                                            const responseCount = `${itemStatus?.responded_students ?? 0}/${itemStatus?.total_students ?? course.students_count}`
                                                            const statusTooltip = noAttendanceRecorded
                                                                ? `${statusHelp.label}: ${statusHelp.definition}`
                                                                : `${statusHelp.label}: ${statusHelp.definition} (${responseCount} students responded)`
                                                            return (
                                                                <td
                                                                    key={`${course.label}-${item.number}-${type}`}
                                                                    className={`monitoring-status-cell monitoring-status-cell-tooltip ${type === gatheringTypes[gatheringTypes.length - 1] ? 'monitoring-week-end' : ''}`}
                                                                    data-tooltip={statusTooltip}
                                                                    data-tooltip-tone={noAttendanceRecorded || status === 'no_response' ? 'error' : undefined}
                                                                >
                                                                    <span
                                                                        className={`monitoring-status monitoring-status-${itemStatus?.status || 'no_report'} ${noAttendanceRecorded ? 'monitoring-status-no-attendance' : ''}`}
                                                                        role="img"
                                                                        aria-label={`${item.label} ${GATHERING_LABELS[type]}: ${statusHelp.label}. ${statusTooltip}`}
                                                                    >
                                                                        <img
                                                                            src={status === 'completed'
                                                                                ? CompletedIcon
                                                                                : status === 'incomplete'
                                                                                    ? IncompleteIcon
                                                                                    : status === 'no_response' ? RecordedNoResponseIcon : NoReportsIcon}
                                                                            alt=""
                                                                        />
                                                                    </span>
                                                                </td>
                                                            )
                                                        })
                                                    })}
                                                    <td
                                                        className="monitoring-progress-cell"
                                                        title={`${course.progress.completed} out of ${course.progress.total} gathering attendance completed`}
                                                    >
                                                        <span>{backendPercentage(course.progress.percentage)}%</span>
                                                        <div className="monitoring-progress-track" role="progressbar" aria-label={`${course.label} completed gathering attendance`} aria-valuemin="0" aria-valuemax="100" aria-valuenow={backendPercentage(course.progress.percentage)}>
                                                            <span style={{ width: `${backendPercentage(course.progress.percentage)}%` }} />
                                                        </div>
                                                    </td>
                                                    <td className="monitoring-action-cell">
                                                        <button
                                                            type="button"
                                                            onClick={() => openCourseDetails(course)}
                                                        >
                                                            View
                                                            <svg viewBox="0 0 12 12" fill="none" aria-hidden="true">
                                                                <path d="m4 2 4 4-4 4" stroke="currentColor" strokeWidth="1.5" strokeLinecap="round" strokeLinejoin="round" />
                                                            </svg>
                                                        </button>
                                                    </td>
                                                </tr>
                                            ))}
                                        </Fragment>
                                    ))}
                                </tbody>
                            </table>
                            <div className="monitoring-status-legend" aria-label="Attendance status legend">
                                <span>
                                    <span className="monitoring-legend-icon monitoring-legend-completed">
                                        <img src={CompletedIcon} alt="" />
                                    </span>
                                    <span title={ATTENDANCE_STATUS_HELP.completed.definition}>Completed</span>
                                </span>
                                <span>
                                    <span className="monitoring-legend-icon monitoring-legend-in-progress">
                                        <img src={IncompleteIcon} alt="" />
                                    </span>
                                    <span title={ATTENDANCE_STATUS_HELP.incomplete.definition}>Incomplete</span>
                                </span>
                                <span>
                                    <span className="monitoring-legend-icon monitoring-legend-recorded-no-response">
                                        <img src={RecordedNoResponseIcon} alt="" />
                                    </span>
                                    <span title={ATTENDANCE_STATUS_HELP.no_response.definition}>No Response</span>
                                </span>
                                <span>
                                    <span className="monitoring-legend-icon monitoring-legend-no-report">
                                        <img src={NoReportsIcon} alt="" />
                                    </span>
                                    <span title={ATTENDANCE_STATUS_HELP.no_report.definition}>No Report</span>
                                </span>
                            </div>
                        </div>
                    )}
                </section>
            </main>

            {activeCourse && (
                <MonitoringCourseDetailModal
                    course={activeCourse}
                    weeks={weeks}
                    month={monitoring?.month}
                    onClose={closeCourseDetails}
                />
            )}
        </div>
    )
}

export default CoordinatorMonitoring
