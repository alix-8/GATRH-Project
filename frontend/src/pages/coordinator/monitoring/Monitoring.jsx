import { Fragment, useEffect, useMemo, useState } from 'react'
import Header from '../../../components/layout/Header'
import Sidebar from '../../../components/layout/Sidebar'
import LoadingCircle from '../../../components/feedback/LoadingCircle'
import CompletedIcon from '../../../assets/icons/completed-reports-icon.svg'
import IncompleteIcon from '../../../assets/icons/incomplete-reports-icon.svg'
import NoReportsIcon from '../../../assets/icons/no-reports-icon.svg'
import IncompleteEntryIcon from '../../../assets/icons/incomplete-entry-icon.svg'
import ProgramHeadIcon from '../../../assets/icons/program-head-icon.svg'
import { request } from '../../../api/request'
import './Monitoring.css'

const GATHERING_LABELS = {
    pm: 'PM',
    tg: 'TG',
    ws: 'WS',
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
const GATHERINGS = [
    { type: 'pm', label: 'Prayer Meeting' },
    { type: 'ws', label: 'Worship Service' },
    { type: 'tg', label: 'Thanksgiving' },
]
const EMPTY_COURSES = []

function formatDate(value, options = { month: 'short', day: 'numeric', year: 'numeric' }) {
    if (!value) return '—'
    const [year, month, day] = value.slice(0, 10).split('-').map(Number)
    return new Date(year, month - 1, day).toLocaleDateString('en-US', options)
}

function formatGatheringDate(entry) {
    return formatDate(entry?.date, { month: 'long', day: 'numeric', year: 'numeric' })
}

function CoordinatorMonitoring() {
    const [monitoring, setMonitoring] = useState(null)
    const [collapsedPrograms, setCollapsedPrograms] = useState([])
    const [settledQuery, setSettledQuery] = useState(null)
    const [requestError, setRequestError] = useState(null)
    const [activeCourse, setActiveCourse] = useState(null)
    const [selectedDetailWeek, setSelectedDetailWeek] = useState(null)
    const [activeIncompleteEntry, setActiveIncompleteEntry] = useState(null)
    const [courseDetails, setCourseDetails] = useState(null)
    const [detailFailure, setDetailFailure] = useState(null)

    useEffect(() => {
        const controller = new AbortController()

        request('/monitoring', { signal: controller.signal })
            .then((data) => {
                if (controller.signal.aborted) return
                setMonitoring(data)
            })
            .catch((loadError) => {
                if (loadError.name !== 'AbortError') {
                    setRequestError({
                        message: loadError.message || 'Unable to load monitoring data.',
                    })
                }
            })
            .finally(() => {
                if (!controller.signal.aborted) setSettledQuery(true)
            })

        return () => controller.abort()
    }, [])

    const loading = !settledQuery
    const error = requestError?.message || ''
    const courses = monitoring?.courses ?? EMPTY_COURSES
    const weeks = monitoring?.weeks || []
    const gatheringTypes = ['pm', 'tg', 'ws']
    const detailWeek = selectedDetailWeek ?? weeks[0]?.number
    const detailKey = activeCourse
        ? `${activeCourse.program}-${activeCourse.year_level}-${monitoring?.month?.year}-${monitoring?.month?.month}-${detailWeek}`
        : ''
    const currentDetails = courseDetails?.key === detailKey ? courseDetails.data : null
    const currentDetailError = detailFailure?.key === detailKey ? detailFailure.message : ''
    const detailLoading = Boolean(activeCourse && !currentDetails && !currentDetailError)
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

    useEffect(() => {
        if (!activeCourse || !detailWeek) return undefined

        const controller = new AbortController()
        const params = new URLSearchParams({
            year: String(monitoring.month.year),
            month: String(monitoring.month.month),
            week: String(detailWeek),
        })
        const key = `${activeCourse.program}-${activeCourse.year_level}-${monitoring.month.year}-${monitoring.month.month}-${detailWeek}`

        request(
            `/monitoring/courses/${encodeURIComponent(activeCourse.program)}/${activeCourse.year_level}?${params}`,
            { signal: controller.signal }
        )
            .then((data) => {
                if (!controller.signal.aborted) setCourseDetails({ key, data })
            })
            .catch((detailError) => {
                if (detailError.name !== 'AbortError') {
                    setDetailFailure({
                        key,
                        message: detailError.message || 'Unable to load course details.',
                    })
                }
            })

        return () => controller.abort()
    }, [activeCourse, monitoring?.month?.year, monitoring?.month?.month, detailWeek])

    const openCourseDetails = (course) => {
        setActiveCourse(course)
        setSelectedDetailWeek(weeks[0]?.number ?? null)
        setActiveIncompleteEntry(null)
    }

    const closeCourseDetails = () => {
        setActiveCourse(null)
        setActiveIncompleteEntry(null)
    }

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
                    className={`monitoring-content ${!error && loading ? 'monitoring-content--loading' : ''}`}
                    aria-label="Coordinator monitoring"
                >
                    {error && <p className="monitoring-message monitoring-error" role="alert">{error}</p>}
                    {!error && loading && (
                        <div className="monitoring-loading">
                            <LoadingCircle label="Loading monitoring data…" size={28} />
                        </div>
                    )}
                    {!error && !loading && courses.length === 0 && (
                        <p className="monitoring-message">No program attendance data is available.</p>
                    )}

                    {!error && courses.length > 0 && (
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
                                        <th className="monitoring-program-heading" scope="col">Program</th>
                                        <th className="monitoring-type-heading" scope="col">Type</th>
                                        {weeks.map((item) => (
                                            <th key={item.number} className="monitoring-week-heading" scope="colgroup" colSpan={gatheringTypes.length}>
                                                <span className="monitoring-week-title">{item.label}</span>
                                                <small>{item.description}</small>
                                                <span className="monitoring-week-gatherings">
                                                    {gatheringTypes.map((type) => (
                                                        <span key={type} title={{
                                                            pm: 'Prayer Meeting',
                                                            tg: 'Thanksgiving',
                                                            ws: 'Worship Service',
                                                        }[type]}>{GATHERING_LABELS[type]}</span>
                                                    ))}
                                                </span>
                                            </th>
                                        ))}
                                        <th className="monitoring-progress-heading" scope="col">Progress</th>
                                        <th className="monitoring-action-heading" scope="col">Action</th>
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
                                                            return (
                                                                <td
                                                                    key={`${course.label}-${item.number}-${type}`}
                                                                    className={`monitoring-status-cell ${type === gatheringTypes[gatheringTypes.length - 1] ? 'monitoring-week-end' : ''}`}
                                                                >
                                                                    <span
                                                                        className={`monitoring-status monitoring-status-${itemStatus?.status || 'no_report'}`}
                                                                        role="img"
                                                                        aria-label={`${item.label} ${GATHERING_LABELS[type]}: ${itemStatus?.status?.replace('_', ' ') || 'No report'}`}
                                                                        title={itemStatus
                                                                            ? `${itemStatus.completed_students}/${itemStatus.total_students} students`
                                                                            : 'No report'}
                                                                    >
                                                                        <img
                                                                            src={itemStatus?.status === 'completed'
                                                                                ? CompletedIcon
                                                                                : itemStatus?.status === 'incomplete'
                                                                                    ? IncompleteIcon
                                                                                    : NoReportsIcon}
                                                                            alt=""
                                                                        />
                                                                    </span>
                                                                </td>
                                                            )
                                                        })
                                                    })}
                                                    <td className="monitoring-progress-cell">
                                                        <span>{course.progress.completed}/{course.progress.total}</span>
                                                        <div className="monitoring-progress-track">
                                                            <span style={{ width: `${course.progress.percentage}%` }} />
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
                                    Completed
                                </span>
                                <span>
                                    <span className="monitoring-legend-icon monitoring-legend-in-progress">
                                        <img src={IncompleteIcon} alt="" />
                                    </span>
                                    Incomplete
                                </span>
                                <span>
                                    <span className="monitoring-legend-icon monitoring-legend-no-report">
                                        <img src={NoReportsIcon} alt="" />
                                    </span>
                                    No Report
                                </span>
                            </div>
                        </div>
                    )}
                </section>
            </main>

            {activeCourse && (
                <div className="monitoring-detail-backdrop" onMouseDown={(event) => {
                    if (event.target === event.currentTarget) closeCourseDetails()
                }}>
                    <section
                        className="monitoring-detail-panel"
                        role="dialog"
                        aria-modal="true"
                        aria-labelledby="monitoring-detail-title"
                    >
                        <header className="monitoring-detail-header">
                            <div className="monitoring-detail-heading">
                                <span className={`monitoring-detail-program-type monitoring-course-type-pill--${activeCourse.program.toLowerCase()}`}>
                                    {PROGRAM_TYPES[activeCourse.program.toUpperCase()] || activeCourse.program}
                                </span>
                                <h2 id="monitoring-detail-title">{activeCourse.label}</h2>
                                <p>{currentDetails?.course?.program_name && currentDetails.course.program_name !== activeCourse.program
                                    ? currentDetails.course.program_name
                                    : PROGRAM_DISPLAY_NAMES[activeCourse.program.toUpperCase()] || activeCourse.program} {activeCourse.year_level}</p>
                            </div>
                        </header>

                        {detailLoading && <p className="monitoring-message" role="status">Loading course details...</p>}
                        {currentDetailError && <p className="monitoring-message monitoring-error" role="alert">{currentDetailError}</p>}
                        {currentDetails && (
                            <div className="monitoring-detail-body">
                                {currentDetails.course.students_count === 0 && (
                                    <p className="monitoring-empty-roster">
                                        No students are currently enrolled in this year level.
                                    </p>
                                )}
                                <div className="monitoring-detail-progress">
                                    <div>
                                        <span>Overall Progress</span>
                                        <span>{currentDetails.progress.completed}/{currentDetails.progress.total}</span>
                                    </div>
                                    <span className="monitoring-detail-progress-track">
                                        <span style={{ width: `${currentDetails.progress.percentage}%` }} />
                                    </span>
                                </div>

                                <div className="monitoring-approval-card">
                                    <div>
                                        <svg className="monitoring-calendar-icon" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                            <path d="M18.5 5.5H5.5C4.4 5.5 3.5 6.4 3.5 7.5V18C3.5 19.1 4.4 20 5.5 20H18.5C19.6 20 20.5 19.1 20.5 18V7.5C20.5 6.4 19.6 5.5 18.5 5.5Z" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round" />
                                            <path d="M8 4V7M16 4V7M3.5 9.5H20.5" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round" />
                                        </svg>
                                        <span>
                                            <small>Approved on</small>
                                            <strong>{currentDetails.approval.approved_on
                                                ? new Date(currentDetails.approval.approved_on).toLocaleString('en-US', {
                                                    month: 'short',
                                                    day: 'numeric',
                                                    year: 'numeric',
                                                    hour: '2-digit',
                                                    minute: '2-digit',
                                                })
                                                : 'Not yet approved'}
                                            </strong>
                                        </span>
                                    </div>
                                    <div>
                                        <img className="monitoring-program-head-icon" src={ProgramHeadIcon} alt="" />
                                        <span>
                                            <small>Program head</small>
                                            <strong>{currentDetails.approval.program_head || ''}</strong>
                                        </span>
                                    </div>
                                </div>

                                <h3 className="monitoring-detail-section-title">Week Details</h3>
                                <div className="monitoring-detail-week-tabs" aria-label="Select reporting week">
                                    {weeks.map((item) => (
                                        <button
                                            key={item.number}
                                            type="button"
                                            className={item.number === detailWeek ? 'active' : ''}
                                            aria-pressed={item.number === detailWeek}
                                            onClick={() => {
                                                setSelectedDetailWeek(item.number)
                                                setActiveIncompleteEntry(null)
                                            }}
                                        >
                                            {item.label}
                                        </button>
                                    ))}
                                </div>

                                <section className="monitoring-week-details">
                                    <h4>
                                        {currentDetails.week.label}
                                        <small> - {currentDetails.week.description.toLowerCase()}</small>
                                    </h4>
                                    <div className="monitoring-week-detail-headings">
                                        <span>Gathering</span>
                                        <span>Date</span>
                                        <span>Status</span>
                                        <span>Remarks</span>
                                    </div>
                                    {GATHERINGS.map((gatheringItem) => {
                                        const entry = currentDetails.week_details.find(
                                            (item) => item.gathering_type === gatheringItem.type
                                        )
                                        const status = entry?.status || 'no_report'
                                        return (
                                            <div className="monitoring-week-detail-row" key={gatheringItem.type}>
                                                <span>{gatheringItem.label}</span>
                                                <span title={entry?.dates?.length > 1
                                                    ? entry.dates.map((date) => formatDate(date, { month: 'long', day: 'numeric', year: 'numeric' })).join(', ')
                                                    : undefined}>
                                                    {formatGatheringDate(entry)}
                                                </span>
                                                <span className={`monitoring-detail-status-cell monitoring-detail-status-cell--${status}`}>
                                                    <img
                                                        src={status === 'completed'
                                                            ? CompletedIcon
                                                            : status === 'incomplete'
                                                                ? IncompleteIcon
                                                                : NoReportsIcon}
                                                        alt=""
                                                    />
                                                    {status === 'completed'
                                                        ? 'Completed'
                                                        : status === 'incomplete'
                                                            ? 'Incomplete'
                                                            : 'No Report'}
                                                </span>
                                                <span>{entry?.remarks || '—'}</span>
                                            </div>
                                        )
                                    })}

                                    <div className="monitoring-incomplete-details">
                                        <h4>
                                            <span className="monitoring-incomplete-icon" aria-hidden="true">
                                                <img src={IncompleteEntryIcon} alt="" />
                                            </span>
                                            Incomplete Entry Details
                                        </h4>
                                        {currentDetails.incomplete_entries.length === 0
                                            ? <p>No incomplete entries for this week.</p>
                                            : currentDetails.incomplete_entries.map((entry) => {
                                                const entryKey = entry.gathering_type
                                                const isOpen = activeIncompleteEntry === entryKey
                                                return (
                                                    <article key={entryKey}>
                                                        <div>
                                                            <strong>{entry.gathering} - {currentDetails.week.label}</strong>
                                                            <small>+{entry.missing_students.length} students did not submit attendance</small>
                                                        </div>
                                                        <button
                                                            type="button"
                                                            aria-expanded={isOpen}
                                                            onClick={() => setActiveIncompleteEntry(isOpen ? null : entryKey)}
                                                        >
                                                            {isOpen ? 'Hide' : 'View'}
                                                            <svg viewBox="0 0 12 12" fill="none" aria-hidden="true">
                                                                <path d="m4 2 4 4-4 4" stroke="currentColor" strokeWidth="1.5" strokeLinecap="round" strokeLinejoin="round" />
                                                            </svg>
                                                        </button>
                                                        {isOpen && (
                                                            <ul>
                                                                {entry.missing_students.map((student) => (
                                                                    <li key={student.id}>{student.name}</li>
                                                                ))}
                                                            </ul>
                                                        )}
                                                    </article>
                                                )
                                            })
                                        }
                                    </div>
                                </section>
                            </div>
                        )}
                        <button className="monitoring-detail-close" type="button" onClick={closeCourseDetails}>
                            Close
                        </button>
                    </section>
                </div>
            )}
        </div>
    )
}

export default CoordinatorMonitoring
