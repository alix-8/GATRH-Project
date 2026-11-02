import { useEffect, useState } from 'react'
import CompletedIcon from '../../../assets/icons/completed-reports-icon.svg'
import IncompleteIcon from '../../../assets/icons/incomplete-reports-icon.svg'
import NoReportsIcon from '../../../assets/icons/no-reports-icon.svg'
import RecordedNoResponseIcon from '../../../assets/icons/recorded-no-response-icon.svg'
import IncompleteEntryIcon from '../../../assets/icons/incomplete-entry-icon.svg'
import ProgramHeadIcon from '../../../assets/icons/program-head-icon.svg'
import { request } from '../../../api/request'
import backendPercentage from '../../../utils/backendPercentage'
import RecordDate from '../../../components/RecordDate'
import MonitoringDetailSkeleton from './skeleton/MonitoringDetailSkeleton'
import './Monitoring.css'

const PROGRAM_DISPLAY_NAMES = {
    ACT: 'Associate in Computer Technology',
    BSIS: 'BS Information Systems',
    BSSW: 'BS Social Work',
    BSA: 'BS Accountancy',
    BSAIS: 'BS Accountancy Information System',
    BAB: 'BA Broadcasting',
}
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
function formatDate(value, options = { month: 'short', day: 'numeric', year: 'numeric' }) {
    if (!value) return '—'
    const [year, month, day] = value.slice(0, 10).split('-').map(Number)
    return new Date(year, month - 1, day).toLocaleDateString('en-US', options)
}

function getRecordRemark(entry) {
    const recordedBy = entry?.recorded_by

    switch (entry?.record_state) {
        case 'approved':
            return {
                label: 'Approved',
                tooltip: recordedBy ? `Approved by: ${recordedBy}` : 'Approved',
            }
        case 'draft':
            return {
                label: 'Validating',
                tooltip: recordedBy ? `${recordedBy} is still editing.` : 'The secretary is still editing.',
            }
        case 'pending':
            return {
                label: 'Pending',
                tooltip: recordedBy ? `Recorded by: ${recordedBy}` : 'Pending review',
            }
        default:
            return {
                label: 'No Submission yet',
                tooltip: recordedBy
                    ? `No attendance submission yet. Awaiting submission from: ${recordedBy}`
                    : 'No attendance submission yet.',
            }
    }
}

export default function MonitoringCourseDetailModal({ course: activeCourse, weeks, month, onClose: closeCourseDetails }) {
    const [selectedDetailWeek, setSelectedDetailWeek] = useState(weeks[0]?.number ?? null)
    const [activeIncompleteEntry, setActiveIncompleteEntry] = useState(null)
    const [courseDetails, setCourseDetails] = useState(null)
    const [detailFailure, setDetailFailure] = useState(null)
    const detailWeek = selectedDetailWeek ?? weeks[0]?.number
    const detailKey = `${activeCourse.program}-${activeCourse.year_level}-${month.year}-${month.month}-${detailWeek}`
    const currentDetails = courseDetails?.key === detailKey ? courseDetails.data : null
    const currentDetailError = detailFailure?.key === detailKey ? detailFailure.message : ''
    const detailLoading = Boolean(!currentDetails && !currentDetailError)
    useEffect(() => {
        const previousPageOverflow = document.documentElement.style.overflow
        document.documentElement.style.overflow = 'hidden'
        return () => { document.documentElement.style.overflow = previousPageOverflow }
    }, [])

    useEffect(() => {
        if (!detailWeek) return undefined
        const controller = new AbortController()
        const params = new URLSearchParams({
            year: String(month.year),
            month: String(month.month),
            week: String(detailWeek),
        })
        const key = detailKey

        request(`/monitoring/courses/${encodeURIComponent(activeCourse.program)}/${activeCourse.year_level}?${params}`, {
            signal: controller.signal,
        })
            .then((data) => {
                if (!controller.signal.aborted) setCourseDetails({ key, data })
            })
            .catch((error) => {
                if (error.name !== 'AbortError') {
                    setDetailFailure({ key, message: error.message || 'Unable to load course details.' })
                }
            })

        return () => controller.abort()
    }, [activeCourse.program, activeCourse.year_level, month.year, month.month, detailWeek, detailKey])

    useEffect(() => {
        const closeOnEscape = (event) => {
            if (event.key === 'Escape') closeCourseDetails()
        }
        document.addEventListener('keydown', closeOnEscape)
        return () => document.removeEventListener('keydown', closeOnEscape)
    }, [closeCourseDetails])

    const selectedWeek = weeks.find((item) => item.number === detailWeek)
    const weekTabs = (
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
    )

    return (
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
                            <button className="monitoring-detail-dismiss" type="button" onClick={closeCourseDetails} aria-label="Close details">
                                ×
                            </button>
                        </header>

                        {detailLoading && (
                            <MonitoringDetailSkeleton weekTabs={weekTabs} week={selectedWeek} />
                        )}
                        {currentDetailError && (
                            <div className="monitoring-detail-body">
                                <h3 className="monitoring-detail-section-title">Week Details</h3>
                                {weekTabs}
                                <p className="monitoring-message monitoring-error" role="alert">{currentDetailError}</p>
                            </div>
                        )}
                        {currentDetails && (
                            <div className="monitoring-detail-body">
                                {currentDetails.course.students_count === 0 && (
                                    <p className="monitoring-empty-roster">
                                        No students are currently enrolled in this year level.
                                    </p>
                                )}
                                <div
                                    className="monitoring-detail-progress"
                                    title={`${currentDetails.progress.completed} out of ${currentDetails.progress.total} gathering attendance completed`}
                                >
                                    <div>
                                        <span>Overall Progress</span>
                                        <span>{backendPercentage(currentDetails.progress.percentage)}%</span>
                                    </div>
                                    <span className="monitoring-detail-progress-track" role="progressbar" aria-label="Completed gathering attendance" aria-valuemin="0" aria-valuemax="100" aria-valuenow={backendPercentage(currentDetails.progress.percentage)}>
                                        <span style={{ width: `${backendPercentage(currentDetails.progress.percentage)}%` }} />
                                    </span>
                                </div>

                                <div className="monitoring-program-head-card">
                                    <img src={ProgramHeadIcon} alt="" />
                                    <span>
                                        <small>Program head</small>
                                        <strong>{currentDetails.course.program_head || 'Not assigned'}</strong>
                                    </span>
                                </div>

                                <h3 className="monitoring-detail-section-title">Week Details</h3>
                                {weekTabs}

                                <section className="monitoring-week-details">
                                    <h4>
                                        {currentDetails.week.label}
                                        <small> - {currentDetails.week.description}</small>
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
                                        const statusLabel = status === 'completed'
                                            ? 'Completed'
                                            : status === 'incomplete'
                                                ? 'Incomplete'
                                                : 'No response'
                                        const recordedNoResponses = status === 'no_report' && entry?.has_attendance
                                        const gatheringDates = entry?.dates?.length ? entry.dates : entry?.date ? [entry.date] : []
                                        const scheduledRange = currentDetails.week.gathering_ranges?.[gatheringItem.type]
                                        const gatheringDateRange = gatheringDates.length
                                            ? `${formatDate(gatheringDates[0])}${gatheringDates.length > 1 ? ` – ${formatDate(gatheringDates[gatheringDates.length - 1])}` : ''}`
                                            : `${formatDate(scheduledRange?.start || currentDetails.week.start)} – ${formatDate(scheduledRange?.end || currentDetails.week.end)}`
                                        const recordRemark = getRecordRemark(entry)
                                        const responseCount = `${entry?.responded_students ?? 0}/${entry?.total_students ?? 0}`
                                        return (
                                            <div className="monitoring-week-detail-row" key={gatheringItem.type}>
                                                <span title={`${gatheringItem.label} · ${gatheringDateRange}`}>{gatheringItem.label}</span>
                                                <RecordDate value={entry?.dates?.length ? entry.dates : entry?.date} compactRange />
                                                <span
                                                    className={`monitoring-detail-status-cell monitoring-detail-status-cell--${status}`}
                                                    title={statusLabel}
                                                    aria-label={`${statusLabel}: ${responseCount} students responded`}
                                                    tabIndex={0}
                                                >
                                                    <img
                                                        src={status === 'completed'
                                                            ? CompletedIcon
                                                            : status === 'incomplete'
                                                                ? IncompleteIcon
                                                            : recordedNoResponses ? RecordedNoResponseIcon : NoReportsIcon}
                                                        alt=""
                                                    />
                                                    {responseCount}
                                                </span>
                                                <span
                                                    className={`monitoring-detail-record-remark monitoring-detail-record-remark--${entry?.record_state || 'not_recorded'}`}
                                                    title={recordRemark.tooltip}
                                                    aria-label={`${recordRemark.label}. ${recordRemark.tooltip}`}
                                                    tabIndex={0}
                                                >
                                                    {recordRemark.label}
                                                </span>
                                            </div>
                                        )
                                    })}

                                    {currentDetails.incomplete_entries.length > 0 && <div className="monitoring-incomplete-details">
                                        <h4>
                                            <span className="monitoring-incomplete-icon" aria-hidden="true">
                                                <img src={IncompleteEntryIcon} alt="" />
                                            </span>
                                            Incomplete Entry Details
                                        </h4>
                                        {currentDetails.incomplete_entries.map((entry) => {
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
                                            })}
                                    </div>}
                                </section>
                            </div>
                        )}
                        <button className="monitoring-detail-close" type="button" onClick={closeCourseDetails}>
                            Close
                        </button>
                    </section>
                </div>
    )
}
