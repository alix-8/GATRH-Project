import { useEffect, useRef, useState } from 'react'
import { useLocation, useNavigate } from 'react-router-dom'
import Sidebar from '../../components/layout/Sidebar'
import Header from '../../components/layout/Header'
import RecordDate from '../../components/RecordDate'
import formatDisplayTime from '../../utils/formatDisplayTime'
import VenueLabel from '../../components/VenueLabel'
import RemindersIcon from '../../assets/icons/reminders-icon.svg'
import NeedsActionIcon from '../../assets/needs-action.png'
import EscalationsIcon from '../../assets/icons/escalations-icon.svg'
import StudentsIcon from '../../assets/icons/students-icon.svg'
import CompletedIcon from '../../assets/icons/completed-icon.svg'
import NotificationIcon from '../../assets/icons/notification-icon.svg'
import CalendarIcon from '../../assets/icons/calendar-icon.svg'
import GatheringIcon from '../../assets/icons/gathering-icon.svg'
import MyReminderIcon from '../../assets/icons/my-reminder-icon.svg'
import DownloadIcon from '../../assets/icons/download-icon.svg'
import EditIcon from '../../assets/icons/edit-icon.svg'
import AddReminderIcon from '../../assets/icons/add-reminder-icon.svg'
import DeleteIcon from '../../assets/icons/delete-icon.svg'
import { request, API_BASE_URL, xsrfHeaders } from '../../api/request'
import useLiveRefresh from '../../hooks/useLiveRefresh'
import useAutoDismissNotice from '../../hooks/useAutoDismissNotice'
import Calendar from '../../components/Calendar/Calendar'
import { TrashIcon } from '../../components/Icons/Reusables'
import ReviewDrawer from '../../user/secretary/attendance/ReviewDrawer'
import { markNotificationIdsSeen } from '../../components/feedback/notificationReadState'
import './Reminders.css'

const FILTERS = [
    { value: 'all', label: 'All Reminders' },
    { value: 'today', label: 'Today' },
    { value: 'escalation', label: 'Escalation' },
    { value: 'exports', label: 'Exports' },
    { value: 'needs_action', label: 'Needs Action' },
    { value: 'completed', label: 'Completed' },
    { value: 'mine', label: 'My reminder' },
]

const REMINDER_TYPES = [
    { value: 'submission', label: 'Submission' },
    { value: 'gathering', label: 'Gathering' },
    { value: 'general', label: 'General' },
]

const GATHERING_OPTIONS = [
    { value: 'pm', label: 'Prayer Meeting' },
    { value: 'ws', label: 'Worship Service' },
    { value: 'tg', label: 'Thanksgiving' },
]

function ReminderListSkeleton({ count = 5 }) {
    return (
        <div className="reminders-skeleton-list" role="status" aria-label="Loading reminders" aria-busy="true">
            {Array.from({ length: count }, (_, index) => (
                <div className="reminders-skeleton-item" key={`reminder-skeleton-${index}`} aria-hidden="true">
                    <div className="reminders-skeleton-copy">
                        <i />
                        <span><b /><b /><b /></span>
                    </div>
                    <div className="reminders-skeleton-actions"><i /><i /></div>
                </div>
            ))}
        </div>
    )
}

function EscalationReportSkeleton() {
    return (
        <div className="escalation-report-skeleton" role="status" aria-label="Loading attendance history" aria-busy="true">
            <div className="escalation-report-summary" aria-hidden="true">
                {[0, 1, 2].map(card => (
                    <div className="escalation-report-card escalation-report-skeleton-card" key={card}>
                        <span className="escalation-report-skeleton-block escalation-report-skeleton-label" />
                        <span className="escalation-report-skeleton-block escalation-report-skeleton-icon" />
                        <span className="escalation-report-skeleton-block escalation-report-skeleton-value" />
                    </div>
                ))}
            </div>
            <div className="escalation-report-history">
                <div className="escalation-report-skeleton-head" aria-hidden="true">
                    {['Gathering', 'Time', 'Date', 'Location', 'Status'].map(heading => <span key={heading}>{heading}</span>)}
                </div>
                {[0, 1, 2, 3].map(row => (
                    <div className="escalation-report-skeleton-row" key={row} aria-hidden="true">
                        {[0, 1, 2, 3, 4].map(cell => (
                            <span className={`escalation-report-skeleton-block escalation-report-skeleton-cell-${cell}`} key={cell} />
                        ))}
                    </div>
                ))}
            </div>
        </div>
    )
}

function escalationLevelNumber(value) {
    return Math.min(3, Math.max(0, Number(value?.match(/\d+/)?.[0]) || 0))
}

function SelectChevron() {
    return (
        <svg className="select-chevron" viewBox="0 0 16 10" fill="none" aria-hidden="true" focusable="false">
            <path d="M2 2L8 8L14 2" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" />
        </svg>
    )
}

const EMPTY_FORM = {
    title: '',
    description: '',
    type: '',
    gathering: '',
    date: null,
    time: '',
}
const NOTES_LIMIT = 100

const ATTENDANCE_TASK_STATUS = {
    validating: {
        label: 'Attendance record ready for submission',
        tone: 'needs-action',
        action: 'Review',
    },
    pending: {
        label: 'Attendance record recorded',
        tone: 'completed',
        action: 'Review',
    },
    approved: {
        label: 'Attendance record approved',
        tone: 'completed',
        action: 'view',
    },
}

function dateKeyInLocalTime(value) {
    if (!value) return ''

    const date = new Date(value)
    if (Number.isNaN(date.getTime())) return String(value).slice(0, 10)

    return [
        date.getFullYear(),
        String(date.getMonth() + 1).padStart(2, '0'),
        String(date.getDate()).padStart(2, '0'),
    ].join('-')
}

function buildDateTime(parts) {
    return [parts.date, parts.time].filter(Boolean).join(' · ')
}

function fallbackSubmissionDeadline(gatheringDate) {
    const match = String(gatheringDate || '').match(/^(\d{4})-(\d{2})-(\d{2})$/)
    if (!match) return null

    const [, year, month, day] = match
    const deadline = new Date(Number(year), Number(month) - 1, Number(day) + 7)
    return Number.isNaN(deadline.getTime()) ? null : deadline
}

function formatCountdownDuration(totalSeconds) {
    const days = Math.floor(totalSeconds / 86400)
    const hours = Math.floor((totalSeconds % 86400) / 3600)
    const minutes = Math.floor((totalSeconds % 3600) / 60)
    const seconds = totalSeconds % 60
    const parts = []

    if (days > 0) parts.push(`${days}d`)
    if (hours > 0) parts.push(`${hours}h`)
    if (minutes > 0) parts.push(`${minutes}m`)
    if (seconds > 0 || parts.length === 0) parts.push(`${seconds}s`)

    return parts.join(' ')
}

function isDeadlinePassed(dueDate, now = Date.now()) {
    const dueAt = new Date(dueDate).getTime()
    return Number.isFinite(dueAt) && dueAt <= now
}

function formatDeadlineCountdown(dueDate, now = Date.now()) {
    if (!dueDate) return ''

    const dueAt = new Date(dueDate).getTime()
    if (!Number.isFinite(dueAt)) return ''

    const remainingSeconds = Math.ceil((dueAt - now) / 1000)
    if (remainingSeconds <= 0) {
        const elapsedSeconds = Math.abs(remainingSeconds)
        return `Deadline passed ${formatCountdownDuration(elapsedSeconds)} ago`
    }

    return `Deadline in ${formatCountdownDuration(remainingSeconds)}`
}

function formatReminderDate(date) {
    if (!date) return ''
    return date.toLocaleDateString('en-US', { month: 'long', day: 'numeric', year: 'numeric' })
}

const GATHERING_LABELS = {
    pm: 'Prayer Meeting',
    ws: 'Worship Service',
    tg: 'Thanksgiving',
}

function formatSavedReminderDateTime(value) {
    if (!value) return ''
    const date = new Date(value)

    if (Number.isNaN(date.getTime())) return value

    const formattedDate = date.toLocaleDateString('en-US', {
        month: 'long',
        day: 'numeric',
        year: 'numeric',
    })
    const formattedTime = date.toLocaleTimeString('en-US', {
        hour: 'numeric',
        minute: '2-digit',
        hour12: true,
    })

    return `${formattedDate} · ${formattedTime}`
}

function formatGatheringReminderDateTime(reminder) {
    const date = reminder.gathering_date || reminder.due_date
    const formattedDate = formatSavedReminderDateTime(date).split(' · ')[0]
    const program = [reminder.program, reminder.year_level].filter(Boolean).join('-')

    return [formattedDate, program].filter(Boolean).join(' · ')
}

function splitDueDate(value) {
    if (!value) return { date: null, time: '' }

    const date = new Date(value)

    if (Number.isNaN(date.getTime())) return { date: null, time: '' }

    const hours = String(date.getHours()).padStart(2, '0')
    const minutes = String(date.getMinutes()).padStart(2, '0')

    return { date, time: `${hours}:${minutes}` }
}

function toDateValue(date) {
    if (!date) return ''
    const year = date.getFullYear()
    const month = String(date.getMonth() + 1).padStart(2, '0')
    const day = String(date.getDate()).padStart(2, '0')
    return `${year}-${month}-${day}`
}

function toTimeLabel(value) {
    if (!value) return ''
    const [hourValue, minute] = value.split(':')
    const hour = Number(hourValue)
    const period = hour >= 12 ? 'pm' : 'am'
    const displayHour = String(hour % 12 || 12).padStart(2, '0')
    return `${displayHour}:${minute} ${period}`
}

function getNextAvailableReminderTime(now = new Date()) {
    const nextMinute = new Date(now)
    nextMinute.setSeconds(0, 0)
    nextMinute.setMinutes(nextMinute.getMinutes() + 1)

    return `${String(nextMinute.getHours()).padStart(2, '0')}:${String(nextMinute.getMinutes()).padStart(2, '0')}`
}

function notifyRemindersChanged() {
    window.dispatchEvent(new Event('reminders:changed'))
}

async function fetchProgramHeadSubmissions(signal) {
    const firstPage = await request('/program-head/submissions?page=1&per_page=50', { signal })
    const submissions = [...(firstPage.data ?? [])]
    const lastPage = Number(firstPage.meta?.last_page ?? 1)

    for (let page = 2; page <= lastPage; page += 1) {
        const result = await request(`/program-head/submissions?page=${page}&per_page=50`, { signal })
        submissions.push(...(result.data ?? []))
    }

    return { submissions, pendingCount: firstPage.pending_count ?? submissions.length }
}

function Reminders() {
    const currentUser = (() => {
        try { return JSON.parse(localStorage.getItem('user') || 'null') } catch { return null }
    })()
    const role = currentUser?.role
    const [roleSummary, setRoleSummary] = useState({ attendance_count: 0, escalation_count: 0 })
    const [summary, setSummary] = useState({
        upcoming: 0,
        needs_action: 0,
        completed: 0,
    })
    const [reminders, setReminders] = useState([])
    const [escalations, setEscalations] = useState([])
    const [attendanceTasks, setAttendanceTasks] = useState([])
    const [countdownNow, setCountdownNow] = useState(() => Date.now())
    const [attendanceEntries, setAttendanceEntries] = useState([])
    const [attendanceDrawerOpen, setAttendanceDrawerOpen] = useState(false)
    const [activeAttendanceRecord, setActiveAttendanceRecord] = useState(null)
    const [attendanceRefreshTrigger, setAttendanceRefreshTrigger] = useState(0)
    const [highlightedReminderId, setHighlightedReminderId] = useState(null)
    const [activeFilter, setActiveFilter] = useState('all')
    const [loading, setLoading] = useState(() => Boolean(localStorage.getItem('user')))
    const [error, setError] = useState('')
    const [notice, showNotice, clearNotice] = useAutoDismissNotice()
    const [isAddOpen, setIsAddOpen] = useState(false)
    const [editingReminder, setEditingReminder] = useState(null)
    useLiveRefresh(() => {
        if (!attendanceDrawerOpen && !isAddOpen) {
            setAttendanceRefreshTrigger((current) => current + 1)
        }
    }, 30_000)
    const location = useLocation()
    const [formData, setFormData] = useState(EMPTY_FORM)
    const [openMenu, setOpenMenu] = useState('')
    const [saving, setSaving] = useState(false)
    const [completingReminderId, setCompletingReminderId] = useState(null)
    const [exportingReminderId, setExportingReminderId] = useState(null)
    const [exportConfirmation, setExportConfirmation] = useState(null)
    const [deletingReminder, setDeletingReminder] = useState(null)
    const [deleting, setDeleting] = useState(false)
    const [escalationReport, setEscalationReport] = useState(null)
    const timeInputRef = useRef(null)
    const escalationReportControllerRef = useRef(null)
    const navigate = useNavigate()

    useEffect(() => {
        const intervalId = window.setInterval(() => setCountdownNow(Date.now()), 1000)
        return () => window.clearInterval(intervalId)
    }, [])

    useEffect(() => () => escalationReportControllerRef.current?.abort(), [])

    useEffect(() => {
        if (attendanceDrawerOpen || isAddOpen) return undefined

        if (!localStorage.getItem('user')) {
            return
        }

        const controller = new AbortController()

        async function fetchReminderData() {
            setError('')

            try {
                const [remindersData, attendanceData, roleSummaryData, programHeadSubmissionData] = await Promise.all([
                    request('/reminders', { signal: controller.signal }),
                    role === 'secretary'
                        ? request('/attendance?per_page=100&sort_by=day&sort_dir=desc', { signal: controller.signal })
                        : Promise.resolve(null),
                    ['program_head', 'chancellor', 'cm_coordinator'].includes(role)
                        ? request('/reminders/role-summary', { signal: controller.signal }).catch(error => {
                            if (error.name === 'AbortError') throw error
                            return null
                        })
                        : Promise.resolve(null),
                    role === 'program_head' || role === 'program_secretary'
                        ? fetchProgramHeadSubmissions(controller.signal)
                        : Promise.resolve(null),
                ])

                const nextReminders = remindersData.data ?? []
                const seenNotificationIds = nextReminders.flatMap(reminder => {
                    if (reminder.status === 'completed') return []

                    if (reminder.reminder_type === 'submission_countdown') {
                        const dueAt = reminder.due_date ? new Date(reminder.due_date).getTime() : NaN
                        return Number.isFinite(dueAt) && dueAt <= Date.now()
                            ? [`deadline-${reminder.parent_reminder_id ?? reminder.id}`]
                            : []
                    }

                    const ids = [`reminder-${reminder.id}`]
                    if (reminder.reminder_type === 'custom' && reminder.due_date) {
                        const dueAt = new Date(reminder.due_date).getTime()
                        if (Number.isFinite(dueAt) && dueAt <= Date.now()) {
                            ids.push(`my-reminder-due-${reminder.id}`)
                        }
                    }
                    return ids
                })
                // Opening the Reminders page marks its current reminder and
                // deadline notifications as seen for every role.
                markNotificationIdsSeen(seenNotificationIds)
                const nextEscalations = ['secretary', 'program_head', 'cm_coordinator'].includes(role) ? remindersData.escalations ?? [] : []
                const attendanceRows = attendanceData?.data ?? []
                const submissionDeadlines = new Map(
                    nextReminders
                        .filter(reminder => reminder.reminder_type === 'submission_countdown')
                        .map(reminder => [
                            `${reminder.gathering_type}:${dateKeyInLocalTime(reminder.gathering_date)}`,
                            reminder.due_date,
                        ])
                )
                const programHeadSubmissions = programHeadSubmissionData?.submissions ?? []
                const nextAttendanceTasks = role === 'program_head' || role === 'program_secretary'
                    ? programHeadSubmissions.map(record => ({
                        id: `submission-${record.id}`,
                        reminderId: record.id,
                        type: 'attendance',
                        sourceRecord: record,
                        title: 'Attendance record awaiting review',
                        description: [record.gathering, record.program, record.year_label].filter(Boolean).join(' · '),
                        recordDate: record.date_iso || record.date,
                        status: 'needs_action',
                        statusLabel: 'Pending review',
                        tone: 'needs-action',
                        action: 'Review',
                        createdAt: record.date_iso,
                    }))
                    : attendanceRows
                        .filter(record => ['validating', 'pending', 'approved'].includes(record.status))
                        .map(record => {
                            const meta = ATTENDANCE_TASK_STATUS[record.status]
                            const deadlineKey = `${record.gathering_type}:${record.day_iso}`
                            const isSubmittedRecord = record.source === 'record'
                                || ['pending', 'approved'].includes(record.status)

                            return {
                                id: `attendance-${record.id}`,
                                reminderId: record.id,
                                type: 'attendance',
                                sourceRecord: record,
                                title: isSubmittedRecord
                                    ? 'Attendance record submitted'
                                    : meta.label,
                                description: buildDateTime({
                                    date: record.gathering,
                                    time: record.time,
                                }),
                                recordDate: record.day_iso,
                                status: isSubmittedRecord ? 'completed' : 'needs_action',
                                statusLabel: meta.label,
                                deadline: record.status !== 'validating'
                                    ? null
                                    : submissionDeadlines.get(deadlineKey) || fallbackSubmissionDeadline(record.day_iso),
                                tone: isSubmittedRecord ? 'completed' : meta.tone,
                                action: meta.action,
                                createdAt: record.day_iso,
                            }
                        })

                setReminders(nextReminders)
                setEscalations(nextEscalations)
                setAttendanceEntries(attendanceRows)
                setAttendanceTasks(nextAttendanceTasks)
                if (roleSummaryData || ['secretary', 'program_head', 'cm_coordinator'].includes(role)) {
                    setRoleSummary({
                        attendance_count: roleSummaryData?.attendance_count ?? 0,
                        escalation_count: ['secretary', 'program_head', 'cm_coordinator'].includes(role)
                            ? remindersData.summary?.escalation_count ?? roleSummaryData?.escalation_count ?? 0
                            : roleSummaryData?.escalation_count ?? 0,
                    })
                }
                const reminderTasksNeedingAction = nextReminders.filter(reminder =>
                    ['custom', 'monthly_export'].includes(reminder.reminder_type) && reminder.status === 'needs_action'
                ).length
                setSummary({
                    upcoming: remindersData.summary?.upcoming ?? nextReminders.filter(item => item.status === 'upcoming').length,
                    needs_action: role === 'program_head'
                        ? (programHeadSubmissionData?.pendingCount ?? nextAttendanceTasks.length) + reminderTasksNeedingAction
                        : role === 'cm_coordinator'
                            ? remindersData.summary?.needs_action ?? nextReminders.filter(item => item.status === 'needs_action').length
                        : nextAttendanceTasks.filter(item => item.status === 'needs_action').length + reminderTasksNeedingAction,
                    completed: nextReminders.filter(item => ['custom', 'monthly_export'].includes(item.reminder_type)
                        && item.status === 'completed').length
                        + nextAttendanceTasks.filter(item => item.status === 'completed').length,
                })
            } catch (err) {
                if (err.name !== 'AbortError') {
                    setError(err.message || 'Unable to load reminders.')
                    setReminders([])
                    setEscalations([])
                    setAttendanceTasks([])
                    setAttendanceEntries([])
                    setSummary({
                        upcoming: 0,
                        needs_action: 0,
                        completed: 0,
                    })
                }
            } finally {
                if (!controller.signal.aborted) {
                    setLoading(false)
                }
            }
        }

        fetchReminderData()

        return () => controller.abort()
    }, [role, attendanceRefreshTrigger, attendanceDrawerOpen, isAddOpen])

    useEffect(() => {
        const targetReminderId = location.state?.highlightReminderId
            ?? new URLSearchParams(location.search).get('highlightReminderId')
        const targetEscalationId = location.state?.highlightEscalationId
        const targetAttendanceId = location.state?.highlightAttendanceId
        const targetDeadlineId = location.state?.highlightDeadlineId
        if ((targetReminderId == null && targetEscalationId == null && targetAttendanceId == null && targetDeadlineId == null) || loading) return

        const targetReminder = reminders.find(reminder => String(reminder.id) === String(targetReminderId))
        const targetReminderParent = targetReminder?.reminder_type === 'submission_countdown'
            ? reminders.find(reminder => String(reminder.id) === String(targetReminder.parent_reminder_id))
            : targetReminder
        const targetDeadline = reminders.find(reminder => reminder.reminder_type === 'submission_countdown'
            && String(reminder.id) === String(targetDeadlineId))
        const targetEscalation = escalations.find(escalation => String(escalation.id) === String(targetEscalationId))
        const targetAttendance = attendanceTasks.find(item => (
            String(item.sourceRecord?.id) === String(targetAttendanceId)
            || String(item.sourceRecord?.ref_id) === String(targetAttendanceId)
        ))
        // Keep the navigation target until its data arrives. Dashboard polling
        // and the reminders request can finish in either order; consuming the
        // target while the corresponding list is stale makes highlighting fail.
        if (targetReminderId != null && !targetReminder) return
        if (targetEscalationId != null && !targetEscalation) return
        if (targetAttendanceId != null && !targetAttendance) return
        if (targetDeadlineId != null && !targetDeadline) return

        const targetReminderHighlightId = targetReminderParent
            ? String(targetReminderParent.id)
            : targetReminder?.reminder_type === 'submission_countdown'
                ? `deadline-${targetReminder.parent_reminder_id ?? targetReminder.id}`
                : targetReminder
                    ? String(targetReminder.id)
                    : null
        const highlightId = targetEscalation
                ? `escalation-${targetEscalation.id}`
                : targetAttendance
                    ? String(targetAttendance.reminderId)
                    : targetDeadline
                        ? `deadline-${targetDeadline.parent_reminder_id ?? targetDeadline.id}`
                        : targetReminderHighlightId

        if (!highlightId) return

        setHighlightedReminderId(highlightId)
        setActiveFilter('all')

        navigate(location.pathname, { replace: true, state: null })
    }, [location.pathname, location.search, location.state, loading, reminders, escalations, attendanceTasks, navigate])

    useEffect(() => {
        if (!highlightedReminderId || loading) return

        document.getElementById(`reminder-item-${highlightedReminderId}`)?.scrollIntoView({
            behavior: 'smooth',
            block: 'center',
        })

        const timeoutId = window.setTimeout(() => setHighlightedReminderId(null), 3500)
        return () => window.clearTimeout(timeoutId)
    }, [highlightedReminderId, loading, activeFilter])

    const upcomingCard = { title: 'Upcoming', value: summary.upcoming, description: 'Scheduled activities', icon: RemindersIcon, className: 'upcoming' }
    const escalationCard = { title: 'Escalations', value: roleSummary.escalation_count, description: 'Students requiring intervention', icon: EscalationsIcon, className: 'escalation' }
    const needsActionCard = { title: 'Needs Action', value: summary.needs_action, description: 'Tasks requiring attention', icon: NeedsActionIcon, className: 'needs-action' }
    const roleEscalationCard = { ...escalationCard, title: 'Escalation', description: 'Students that need attention', icon: EscalationsIcon, className: 'escalation' }
    const coordinatorNeedsActionCard = { ...needsActionCard, description: 'Tasks requiring attention' }
    const completedCard = { title: 'Completed', value: summary.completed, description: 'Completed reminders', icon: CompletedIcon, className: 'completed' }
    const reminderCards = role === 'chancellor'
        ? [upcomingCard, escalationCard, completedCard]
        : role === 'cm_coordinator'
            ? [upcomingCard, roleEscalationCard, coordinatorNeedsActionCard, completedCard]
        : role === 'program_head'
            ? [upcomingCard, escalationCard, needsActionCard, completedCard]
            : role === 'secretary'
                ? [upcomingCard, roleEscalationCard, needsActionCard, completedCard]
            : [upcomingCard, needsActionCard, completedCa