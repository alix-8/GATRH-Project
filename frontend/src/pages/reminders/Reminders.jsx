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
import LoadingCircle from '../../components/feedback/LoadingCircle'
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
        action: 'View',
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
                markNotificationIdsSeen(nextReminders
                    .filter(reminder => reminder.status !== 'completed' && reminder.reminder_type !== 'submission_countdown')
                    .map(reminder => `reminder-${reminder.id}`))
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
            : [upcomingCard, needsActionCard, completedCard]

    const now = new Date()
    const todayKey = [
        now.getFullYear(),
        String(now.getMonth() + 1).padStart(2, '0'),
        String(now.getDate()).padStart(2, '0'),
    ].join('-')

    const recordedGatheringKeys = new Set(
        attendanceEntries
            .filter(entry => entry.gathering_type && entry.day_iso)
            .map(entry => `${entry.gathering_type}:${entry.day_iso}`)
    )

    const countdownByParent = new Map(
        reminders
            .filter(reminder => reminder.reminder_type === 'submission_countdown' && reminder.parent_reminder_id)
            .map(reminder => [String(reminder.parent_reminder_id), reminder.countdown_text])
    )

    const reminderItems = reminders
        .filter(reminder => reminder.reminder_type !== 'submission_countdown')
        .map(reminder => {
            const isGathering = reminder.reminder_type === 'gathering'
            const isMonthlyExport = reminder.reminder_type === 'monthly_export'
            const gatheringDate = dateKeyInLocalTime(reminder.gathering_date || reminder.due_date)
            const dueDateKey = dateKeyInLocalTime(reminder.due_date || reminder.gathering_date)
            const isToday = (isGathering ? gatheringDate : dueDateKey) === todayKey
            const isPast = isGathering && gatheringDate !== '' && gatheringDate < todayKey
            const countdown = countdownByParent.get(String(reminder.id)) || reminder.countdown_text

            const gatheringKey = `${reminder.gathering_type}:${gatheringDate}`
            const alreadyRecorded = isGathering && recordedGatheringKeys.has(gatheringKey)

            const title = isGathering
                ? GATHERING_LABELS[reminder.gathering_type] || reminder.title
                : isMonthlyExport ? reminder.title : `My Reminder : ${reminder.title}`
            const isCompleted = reminder.status === 'completed'

            return {
                id: `reminder-${reminder.id}`,
                reminderId: reminder.id,
                sourceReminder: reminder,
                type: isGathering ? 'gathering' : 'custom',
                icon: isMonthlyExport ? DownloadIcon : isGathering ? CalendarIcon : MyReminderIcon,
                title,
                description: isGathering
                    ? formatGatheringReminderDateTime(reminder)
                    : isMonthlyExport
                        ? `${reminder.program} · Year ${reminder.year_level}`
                        : [reminder.description ? `Note : ${reminder.description}` : '', formatSavedReminderDateTime(reminder.due_date)].filter(Boolean).join(' · '),
                status: reminder.status,
                statusLabel: isGathering
                    ? alreadyRecorded
                        ? 'Ready for validation'
                        : isPast
                            ? (countdown || 'Attendance deadline passed')
                            : isToday
                                ? 'Today'
                                : 'Upcoming'
                    : isCompleted
                        ? (reminder.expired_at ? 'Expired' : 'Completed')
                        : reminder.status === 'needs_action'
                            ? 'Needs Action'
                            : 'Upcoming',
                tone: isCompleted ? 'completed' : reminder.status === 'needs_action' ? 'needs-action' : 'upcoming',
                countdown: !isGathering && !isMonthlyExport && !isCompleted
                    ? formatDeadlineCountdown(reminder.due_date, countdownNow)
                    : '',
                countdownOverdue: !isGathering && !isMonthlyExport && !isCompleted && isDeadlinePassed(reminder.due_date, countdownNow),
                isToday,
                action: isMonthlyExport ? 'Export Monthly Records' : isGathering && isToday && !alreadyRecorded ? 'Add attendance' : null,
            }
        })

    const deadlineItems = reminders
        .filter(reminder => reminder.reminder_type === 'submission_countdown'
            && reminder.status !== 'completed'
            && reminder.due_date
            && new Date(reminder.due_date).getTime() <= countdownNow)
        .map(reminder => ({
            id: `deadline-${reminder.parent_reminder_id ?? reminder.id}`,
            reminderId: `deadline-${reminder.parent_reminder_id ?? reminder.id}`,
            sourceReminder: reminder,
            type: 'deadline',
            icon: NotificationIcon,
            title: 'Attendance submission deadline',
            description: reminder.description || GATHERING_LABELS[reminder.gathering_type] || 'Attendance is due',
            status: 'needs_action',
            statusLabel: 'Deadline reached',
            tone: 'needs-action',
            isToday: dateKeyInLocalTime(reminder.due_date) === todayKey,
            action: 'Add attendance',
        }))

    const upcomingDeadlineFallbackItems = reminders
        .filter(reminder => reminder.reminder_type === 'submission_countdown'
            && reminder.status !== 'completed'
            && reminder.due_date
            && new Date(reminder.due_date).getTime() > countdownNow
            && !reminders.some(parent => String(parent.id) === String(reminder.parent_reminder_id)))
        .map(reminder => ({
            id: `deadline-${reminder.parent_reminder_id ?? reminder.id}`,
            reminderId: `deadline-${reminder.parent_reminder_id ?? reminder.id}`,
            sourceReminder: reminder,
            type: 'deadline',
            icon: NotificationIcon,
            title: 'Attendance submission deadline',
            description: reminder.description || GATHERING_LABELS[reminder.gathering_type] || 'Attendance is due',
            status: 'upcoming',
            statusLabel: 'Upcoming',
            tone: 'upcoming',
            isToday: dateKeyInLocalTime(reminder.due_date) === todayKey,
            action: null,
        }))

    const escalationItems = escalations.map(escalation => ({
        id: `escalation-${escalation.id}`,
        reminderId: `escalation-${escalation.id}`,
        type: 'escalation',
        escalationLevel: escalationLevelNumber(escalation.escalation_level),
        icon: EscalationsIcon,
        title: 'Student Requires Attention',
        description: escalation.description,
        sourceEscalation: escalation,
        status: 'escalation',
        statusLabel: 'Escalation Report',
        tone: 'escalation',
        action: 'View',
    }))

    const allItems = [...reminderItems, ...upcomingDeadlineFallbackItems, ...deadlineItems, ...escalationItems, ...attendanceTasks]
        .map((item, index) => ({ item, index }))
        .sort((left, right) => {
            const getOrder = ({ item }) => {
                if (item.status === 'completed') return 4
                if (item.type === 'escalation') return 2
                if (item.isToday) return 1
                if (item.status === 'needs_action') return 3
                if (item.status === 'upcoming') return 0
                return 5
            }

            return getOrder(left) - getOrder(right) || left.index - right.index
        })
        .map(({ item }) => item)
    const visibleItems = allItems.filter(item => {
        if (item.sourceReminder?.reminder_type === 'monthly_export' && item.status === 'completed') {
            return activeFilter === 'completed'
        }
        if (activeFilter === 'all') return true
        if (activeFilter === 'today') return item.isToday
        if (activeFilter === 'escalation') return item.type === 'escalation'
        if (activeFilter === 'exports') return item.sourceReminder?.reminder_type === 'monthly_export'
        if (activeFilter === 'mine') return item.type === 'custom' && item.sourceReminder?.reminder_type !== 'monthly_export'
        if (activeFilter === 'needs_action') return item.type !== 'escalation' && item.status === 'needs_action'
        if (activeFilter === 'completed') return ['attendance', 'custom'].includes(item.type) && item.status === 'completed'
        return true
    })

    const selectedTypeLabel = REMINDER_TYPES.find(option => option.value === formData.type)?.label ?? 'Select Type'
    const isGeneralReminder = formData.type === 'general'
    const selectedGatheringLabel = GATHERING_OPTIONS.find(option => option.value === formData.gathering)?.label ?? 'Select Gathering'
    const todayDateValue = toDateValue(new Date(countdownNow))
    const minimumReminderTime = getNextAvailableReminderTime(new Date(countdownNow))

    const openTimePicker = () => {
        if (timeInputRef.current?.showPicker) {
            timeInputRef.current.showPicker()
            return
        }

        timeInputRef.current?.focus()
    }

    const openAddModal = () => {
        clearNotice()
        setEditingReminder(null)
        setFormData(EMPTY_FORM)
        setError('')
        setIsAddOpen(true)
    }

    const handleCloseModal = () => {
        setEditingReminder(null)
        setFormData(EMPTY_FORM)
        setError('')
        setIsAddOpen(false)
    }

    const closeEscalationReport = () => {
        escalationReportControllerRef.current?.abort()
        setEscalationReport(null)
    }

    const markDeadlineNotificationsSeenForAttendance = (record) => {
        const recordDay = record?.day_iso || record?.date_iso
        if (!record?.gathering_type || !recordDay) return

        const parentIds = reminders
            .filter(reminder => reminder.reminder_type === 'submission_countdown'
                && reminder.gathering_type === record.gathering_type
                && dateKeyInLocalTime(reminder.gathering_date) === recordDay)
            .map(reminder => reminder.parent_reminder_id)
            .filter(Boolean)

        markNotificationIdsSeen(parentIds.map(id => `deadline-${id}`))
    }

    const openEscalationReport = async (escalation) => {
        escalationReportControllerRef.current?.abort()
        const controller = new AbortController()
        escalationReportControllerRef.current = controller
        setEscalationReport({ escalation, loading: true, details: null, error: '' })

        try {
            const details = await request(`/students/${escalation.student_id}/escalation-evidence`, { signal: controller.signal })
            if (!controller.signal.aborted) {
                setEscalationReport({ escalation, loading: false, details, error: '' })
            }
        } catch (err) {
            if (!controller.signal.aborted) {
                setEscalationReport({ escalation, loading: false, details: null, error: err.message || 'Unable to load student details.' })
            }
        }
    }

    const handleEditReminder = (reminder) => {
        clearNotice()
        const { date, time } = splitDueDate(reminder.due_date)

        setEditingReminder(reminder)
        setFormData({
            ...EMPTY_FORM,
            title: reminder.title ?? '',
            description: reminder.description ?? '',
            type: reminder.custom_type ?? '',
            gathering: reminder.custom_type === 'general' ? '' : reminder.gathering_type ?? '',
            date,
            time,
        })
        setError('')
        setIsAddOpen(true)
    }

    const handleSubmitReminder = async (event) => {
        event.preventDefault()
        clearNotice()
        setSaving(true)
        setError('')

        if (!formData.title.trim()) {
            setError('Please enter a reminder title.')
            setSaving(false)
            return
        }

        if (!formData.type) {
            setError('Please choose a reminder type.')
            setSaving(false)
            return
        }

        if (formData.type !== 'general' && !formData.gathering) {
            setError('Please choose a related gathering.')
            setSaving(false)
            return
        }

        if (!formData.date || !formData.time) {
            setError('Please choose both a date and time for the reminder.')
            setSaving(false)
            return
        }

        const today = new Date()
        today.setHours(0, 0, 0, 0)
        const selectedDate = new Date(formData.date)
        selectedDate.setHours(0, 0, 0, 0)
        if (selectedDate < today) {
            setError('Please choose today or a future date.')
            setSaving(false)
            return
        }

        if (!editingReminder) {
            const [year, month, day] = toDateValue(formData.date).split('-').map(Number)
            const [hour, minute] = formData.time.split(':').map(Number)
            const selectedDateTime = new Date(year, month - 1, day, hour, minute)
            if (selectedDateTime.getTime() <= Date.now()) {
                setError('Choose a time later than now for a new reminder.')
                setSaving(false)
                return
            }
        }

        const payload = {
            title: formData.title,
            description: formData.description || null,
            custom_type: formData.type,
            gathering_type: formData.type === 'general' ? null : formData.gathering,
            due_date: `${toDateValue(formData.date)}T${formData.time}`,
        }

        try {
            let successMessage = ''
            if (editingReminder) {
                const data = await request(`/reminders/${editingReminder.id}`, {
                    method: 'PUT',
                    body: JSON.stringify(payload),
                })
                const updated = data.data

                setReminders(prev => prev.map(reminder => (reminder.id === editingReminder.id ? { ...reminder, ...updated } : reminder)))
                successMessage = data.message || 'Reminder updated successfully.'
            } else {
                const data = await request('/reminders', {
                    method: 'POST',
                    body: JSON.stringify({ ...payload, status: 'upcoming' }),
                })
                setReminders(prev => [data.data, ...prev])
                markNotificationIdsSeen([`reminder-${data.data.id}`])
                setSummary(prev => ({
                    ...prev,
                    upcoming: prev.upcoming + 1,
                }))
            }
            notifyRemindersChanged()
            handleCloseModal()
            if (successMessage) showNotice(successMessage)
        } catch (err) {
            setError(err.message || 'Unable to save reminder.')
        } finally {
            setSaving(false)
        }
    }

    const handleDeleteClick = (reminder) => {
        setDeletingReminder(reminder)
    }

    const openMonthlyExportConfirmation = async (reminder) => {
        setExportConfirmation({ reminder, loading: true, details: null, error: '' })
        try {
            const details = await request(`/reminders/${reminder.id}/monthly-export/preview`)
            setExportConfirmation(current => current?.reminder.id === reminder.id
                ? { ...current, details, loading: false } : current)
        } catch (previewError) {
            setExportConfirmation(current => current?.reminder.id === reminder.id
                ? { ...current, loading: false, error: previewError.message } : current)
        }
    }

    const exportMonthlyReminder = async (reminder) => {
        setExportingReminderId(reminder.id)
        setExportConfirmation(current => current ? { ...current, error: '' } : current)
        setError('')
        try {
            const response = await fetch(`${API_BASE_URL}/reminders/${reminder.id}/monthly-export`, {
                method: 'POST', credentials: 'include', headers: { Accept: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', ...xsrfHeaders() },
            })
            if (!response.ok) {
                const details = await response.json().catch(() => ({}))
                throw new Error(details.message || 'Unable to export monthly records. Your reminder remains pending.')
            }
            const token = response.headers.get('X-Monthly-Export-Token')
            const blob = await response.blob()
            if (!token || !blob.size) throw new Error('The download was incomplete. Your reminder remains pending.')
            const url = URL.createObjectURL(blob)
            const link = document.createElement('a')
            link.href = url
            link.download = response.headers.get('Content-Disposition')?.match(/filename="?([^";]+)"?/i)?.[1]
                || `${reminder.program}-${reminder.year_level}-${reminder.export_month}-monthly.xlsx`
            document.body.appendChild(link)
            link.click()
            link.remove()
            window.setTimeout(() => URL.revokeObjectURL(url), 1000)
            const confirmation = await request(`/reminders/${reminder.id}/monthly-export/complete`, {
                method: 'POST', body: JSON.stringify({ token }),
            })
            setExportConfirmation(null)
            if (!confirmation.current) setError(confirmation.message)
            setAttendanceRefreshTrigger(current => current + 1)
        } catch (exportError) {
            setExportConfirmation(current => current ? { ...current, error: exportError.message || 'Export failed. Your reminder remains pending.' } : current)
        } finally {
            setExportingReminderId(null)
        }
    }

    const handleCompleteReminder = async (reminder) => {
        if (!reminder || completingReminderId) return

        setCompletingReminderId(reminder.id)
        try {
            const response = await request(`/reminders/${reminder.id}`, {
                method: 'PUT',
                body: JSON.stringify({ status: 'completed' }),
            })
            const updated = response.data

            setReminders(prev => prev.map(item => String(item.id) === String(reminder.id) ? { ...item, ...updated } : item))
            markNotificationIdsSeen([`my-reminder-due-${reminder.id}`])
            setSummary(prev => ({
                ...prev,
                upcoming: Math.max(0, prev.upcoming - (reminder.status === 'upcoming' ? 1 : 0)),
                needs_action: Math.max(0, prev.needs_action - (reminder.status === 'needs_action' ? 1 : 0)),
                completed: prev.completed + (reminder.status === 'completed' ? 0 : 1),
            }))
            notifyRemindersChanged()
        } catch (err) {
            setError(err.message || 'Unable to complete reminder.')
        } finally {
            setCompletingReminderId(null)
        }
    }

    const handleUndoReminder = async (reminder) => {
        if (!reminder || completingReminderId) return

        setCompletingReminderId(reminder.id)
        try {
            const response = await request(`/reminders/${reminder.id}/undo`, { method: 'PATCH' })
            const updated = response.data
            setReminders(prev => prev.map(item => String(item.id) === String(reminder.id) ? { ...item, ...updated } : item))
            setSummary(prev => ({
                ...prev,
                completed: Math.max(0, prev.completed - 1),
                upcoming: prev.upcoming + (updated.status === 'upcoming' ? 1 : 0),
                needs_action: prev.needs_action + (updated.status === 'needs_action' ? 1 : 0),
            }))
            notifyRemindersChanged()
            setAttendanceRefreshTrigger(current => current + 1)
        } catch (err) {
            setError(err.message || 'Unable to undo reminder completion.')
        } finally {
            setCompletingReminderId(null)
        }
    }

    const handleCloseDeleteModal = () => {
        if (deleting) return
        setDeletingReminder(null)
    }

    const handleConfirmDelete = async () => {
        if (!deletingReminder || deleting) return

        setDeleting(true)

        try {
            await request(`/reminders/${deletingReminder.id}`, { method: 'DELETE' })
            setReminders(prev => prev.filter(r => r.id !== deletingReminder.id))
            setSummary(prev => ({
                ...prev,
                upcoming: Math.max(0, prev.upcoming - 1),
            }))
            notifyRemindersChanged()
            setDeletingReminder(null)
        } catch (err) {
            setError(err.message || 'Unable to delete reminder.')
        } finally {
            setDeleting(false)
        }
    }

    const showReminderList = !loading && (!error || isAddOpen)
    const reportLevel = escalationLevelNumber(escalationReport?.escalation.escalation_level)
    const reportFor = ['—', 'Spiritual Secretary', 'Program Head', 'CM Coordinator'][reportLevel]
    const reportStudentName = escalationReport?.escalation.student_name ?? ''
    const reportAlertName = reportStudentName.includes(',')
        ? reportStudentName.split(',').reverse().map(part => part.trim()).join(' ')
        : reportStudentName
    const reportNoResponseCount = escalationReport?.escalation.no_response_count ?? reportLevel

    return (
        <div className={`reminders reminders-${role || 'default'}`}>
            <Sidebar />

            <main className="reminders-main">
                <Header
                    title="Reminders"
                    subtitle="Track upcoming gatherings and tasks that need attention."
                    className="reminders-header"
                />

                {notice && <p className="reminders-success-notice" role="status">{notice}</p>}

                <section className={`reminders-cards reminders-cards-${role || 'default'}`} aria-label="Reminder summary">
                    {reminderCards.map(card => (
                        <article className={`reminders-card ${card.className}-card`} key={card.title}>
                            <span className={`reminders-icon-wrap ${card.className}-icon-wrap`}>
                                <img
                                    className="reminders-card-icon"
                                    src={card.icon}
                                    alt=""
                                    aria-hidden="true"
                                />
                            </span>
                            <h4>{card.title}</h4>
                            <strong>{card.value}</strong>
                            <p>{card.description}</p>
                        </article>
                    ))}
                </section>

                <section className="reminders-task-panel">
                    <div className="reminders-task-heading">
                        <div>
                            <h2>Reminders & Tasks</h2>
                            <p>{visibleItems.length} items requiring attention or scheduled today</p>
                        </div>

                        <button type="button" className="add-reminder-button" onClick={openAddModal}>
                            <span aria-hidden="true">+</span>
                            Add Reminder
                        </button>
                    </div>

                    <div className="reminders-filter-row" role="tablist" aria-label="Reminder filters">
                        {FILTERS.filter(filter => role !== 'program_secretary' || filter.value !== 'escalation').map(filter => (
                            <button
                                type="button"
                                role="tab"
                                aria-selected={activeFilter === filter.value}
                                className={`reminders-filter ${activeFilter === filter.value ? 'active' : ''}`}
                                key={filter.value}
                                onClick={() => setActiveFilter(filter.value)}
                            >
                                {filter.label}
                            </button>
                        ))}
                    </div>

                    <div className="reminders-task-list">
                        {loading && <p className="reminders-state"><LoadingCircle label="Loading reminders…" /></p>}
                        {!isAddOpen && !loading && error && <p className="reminders-state reminders-state-error">{error}</p>}
                        {showReminderList && visibleItems.length === 0 && (
                            <p className="reminders-state">No reminders found.</p>
                        )}
                        {showReminderList && visibleItems.map(item => (
                            <article
                                id={item.reminderId ? `reminder-item-${item.reminderId}` : undefined}
                                className={`reminders-task-item ${item.tone} ${item.type === 'escalation' ? `escalation-level-${item.escalationLevel}` : ''} ${String(item.reminderId) === highlightedReminderId ? 'reminders-task-item-highlighted' : ''}`}
                                key={item.id}
                            >
                                <div className="task-main">
                                    <span className={`task-icon-wrap ${item.type === 'custom' ? 'my-reminder' : item.type === 'gathering' ? 'calendar' : item.type === 'escalation' ? 'escalation-icon' : 'attendance-task'}`} aria-hidden="true">
                                        {item.type === 'escalation'
                                            ? <span className="task-icon escalation-task-icon" />
                                            : <img className="task-icon" src={item.icon ?? NotificationIcon} alt="" />}
                                    </span>

                                    <div className="task-copy">
                                        <h3>
                                            {item.type === 'custom' && item.sourceReminder?.reminder_type !== 'monthly_export' ? (
                                                <><span className="my-reminder-title-label">My Reminder : </span><span className="my-reminder-title">{item.sourceReminder.title}</span></>
                                            ) : item.title}
                                        </h3>
                                        {item.type === 'custom' && item.countdown && (
                                            <p className={`attendance-deadline-countdown${item.countdownOverdue ? ' is-overdue' : ''}`}>{item.countdown}</p>
                                        )}
                                        {item.type === 'attendance' && item.deadline && (
                                            <p className={`attendance-deadline-countdown${isDeadlinePassed(item.deadline, countdownNow) ? ' is-overdue' : ''}`}>
                                                {formatDeadlineCountdown(item.deadline, countdownNow)}
                                            </p>
                                        )}
                                        {item.type === 'escalation' ? (
                                            <p>
                                                <span className="escalation-student">
                                                    {item.sourceEscalation.program}-{item.sourceEscalation.year_level} {item.sourceEscalation.student_name}
                                                </span>{' '}
                                                has reached <span className="escalation-level">Escalation {item.sourceEscalation.escalation_level.toLowerCase()}</span> and requires follow up.
                                            </p>
                                        ) : (
                                            <p>
                                                {item.description || 'No details added'}
                                                {item.type === 'attendance' && item.recordDate && (
                                                    <> · <RecordDate value={item.recordDate} /></>
                                                )}
                                            </p>
                                        )}
                                    </div>
                                </div>

                                <div className="task-status">
                                    <strong>{item.statusLabel}</strong>
                                    {item.sourceReminder?.reminder_type === 'custom' && item.status === 'completed' && (
                                        <button
                                            type="button"
                                            className="task-undo-button"
                                            onClick={() => handleUndoReminder(item.sourceReminder)}
                                            disabled={Boolean(completingReminderId)}
                                            aria-label={`Undo completion of ${item.sourceReminder.title}`}
                                        >
                                            {completingReminderId === item.sourceReminder.id ? 'Undoing…' : 'Undo'}
                                        </button>
                                    )}
                                    {item.statusLabel === 'Upcoming' && ['gathering', 'submission_countdown'].includes(item.sourceReminder?.reminder_type) && (
                                        <span className="task-system-pin">
                                            (Pinned by the system)
                                        </span>
                                    )}
                                    {item.type === 'custom' && item.sourceReminder?.reminder_type !== 'monthly_export' && item.status !== 'completed' && (
                                        <div className="task-actions">
                                            <button
                                                type="button"
                                                className="task-action-btn task-complete-btn"
                                                title="Mark reminder as done"
                                                aria-label={`Mark ${item.title} as done`}
                                                onClick={() => handleCompleteReminder(item.sourceReminder)}
                                                disabled={completingReminderId === item.sourceReminder.id}
                                            >
                                                {completingReminderId === item.sourceReminder.id ? '…' : (
                                                    <svg viewBox="0 0 16 16" aria-hidden="true" focusable="false">
                                                        <path d="m3.5 8.2 3 3 6-6.4" />
                                                    </svg>
                                                )}
                                            </button>
                                            <button type="button" className="task-action-btn" title="Edit reminder" onClick={() => handleEditReminder(item.sourceReminder)}>
                                                <img src={EditIcon} alt="Edit" />
                                            </button>
                                            <button type="button" className="task-action-btn" title="Delete reminder" onClick={() => handleDeleteClick(item.sourceReminder)}>
                                                <img src={DeleteIcon} alt="Delete" />
                                            </button>
                                        </div>
                                    )}
                                    {item.action && (
                                        <button
                                            type="button"
                                            disabled={exportingReminderId === item.reminderId}
                                            onClick={() => {
                                                if (item.sourceReminder?.reminder_type === 'monthly_export') {
                                                    openMonthlyExportConfirmation(item.sourceReminder)
                                                } else if (item.type === 'escalation') {
                                                    openEscalationReport(item.sourceEscalation)
                                                } else if (item.action === 'Add attendance') {
                                                    markNotificationIdsSeen([`deadline-${item.sourceReminder.parent_reminder_id ?? item.sourceReminder.id}`])
                                                    navigate('/import', {
                                                        state: {
                                                            gathering_type: item.sourceReminder.gathering_type,
                                                            gathering_date: item.sourceReminder.gathering_date,
                                                        },
                                                    })
                                                } else if (role === 'program_head' || role === 'program_secretary') {
                                                    markDeadlineNotificationsSeenForAttendance(item.sourceRecord)
                                                    navigate(role === 'program_secretary' ? '/program-secretary/submission' : '/program-head/submission', {
                                                        state: { openSubmission: { ...item.sourceRecord, status: 'pending' } },
                                                    })
                                                } else {
                                                    markDeadlineNotificationsSeenForAttendance(item.sourceRecord)
                                                    setActiveAttendanceRecord(item.sourceRecord)
                                                    setAttendanceDrawerOpen(true)
                                                }
                                            }}                                        >
                                            {exportingReminderId === item.reminderId ? 'Exporting…' : item.action}
                                            <svg viewBox="0 0 20 20" fill="none" aria-hidden="true">
                                                <path d={item.sourceReminder?.reminder_type === 'monthly_export'
                                                    ? 'M10 2v10m-4-4 4 4 4-4M3 13v4h14v-4'
                                                    : 'm7 4 6 6-6 6'} stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" />
                                            </svg>
                                        </button>
                                    )}
                                </div>
                            </article>
                        ))}
                    </div>
                </section>
            </main>

            {escalationReport && (
                <div className="escalation-report-backdrop" onMouseDown={event => {
                    if (event.target === event.currentTarget) closeEscalationReport()
                }}>
                    <section className={`escalation-report-modal escalation-level-${reportLevel}`} role="dialog" aria-modal="true" aria-labelledby="escalation-report-title">
                        <div className="escalation-report-header">
                            <div>
                                <h2 id="escalation-report-title">Escalation Report</h2>
                                <p>{escalationReport.escalation.program}-{escalationReport.escalation.year_level} {reportStudentName}</p>
                            </div>
                            <button type="button" onClick={closeEscalationReport} aria-label="Close escalation report">×</button>
                        </div>
                        <div className="escalation-report-message">
                            <span className="escalation-report-alert-icon" aria-hidden="true" />
                            <span><strong>{reportAlertName}</strong> has reached Escalation Level {reportLevel}.</span>
                        </div>
                        {escalationReport.loading && <p className="escalation-report-state"><LoadingCircle label="Loading attendance history…" /></p>}
                        {escalationReport.error && <p className="escalation-report-state escalation-report-error" role="alert">{escalationReport.error}</p>}
                        {escalationReport.details && (
                            <>
                                <div className="escalation-report-summary">
                                    <div className="escalation-report-card">
                                        <strong>Escalation</strong>
                                        <span className="escalation-level-bars" aria-hidden="true">
                                            {[1, 2, 3].map(level => <i className={level <= reportLevel ? 'filled' : ''} key={level} />)}
                                        </span>
                                        <b>{reportLevel}</b>
                                    </div>
                                    <div className="escalation-report-card">
                                        <strong>No Response</strong>
                                        <span className="escalation-no-response-icon" aria-hidden="true">−</span>
                                        <b>{reportNoResponseCount}</b>
                                    </div>
                                    <div className="escalation-report-card">
                                        <strong>Report For</strong>
                                        <span className="escalation-report-for-icon" aria-hidden="true">
                                            <img src={StudentsIcon} alt="" />
                                        </span>
                                        <b>{reportFor}</b>
                                    </div>
                                </div>
                                <div className="escalation-report-history">
                                    <table className="escalation-report-table">
                                        <thead><tr><th>Gathering</th><th>Time</th><th>Date</th><th>Location</th><th>Status</th></tr></thead>
                                        <tbody>
                                            {(escalationReport.details.history ?? []).length === 0 ? (
                                                <tr><td colSpan="5" className="escalation-report-empty">No attendance records are available.</td></tr>
                                            ) : escalationReport.details.history.map(record => (
                                                <tr key={record.id}>
                                                    <td title={record.gathering}>{record.gathering || '—'}</td>
                                                    <td>{formatDisplayTime(record.time, '---')}</td>
                                                    <td><RecordDate value={record.date || '---'} label="Attendance date" /></td>
                                                    <td title={record.location}><VenueLabel value={record.location && record.location !== '—' ? record.location : '---'} /></td>
                                                    <td><span className={`escalation-report-status ${record.status === 'present' ? 'present' : 'no-response'}`}><i />{record.status === 'present' ? 'Present' : 'No Response'}</span></td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>
                            </>
                        )}
                        <button type="button" className="escalation-report-close" onClick={closeEscalationReport}>Close</button>
                    </section>
                </div>
            )}

            {isAddOpen && (
                <div className="reminder-modal-backdrop">
                    <form className="reminder-modal" noValidate onSubmit={handleSubmitReminder}>
                        <div className="reminder-modal-header">
                            <div className="reminder-modal-heading">
                                <img className="reminder-modal-pencil" src={AddReminderIcon} alt="" aria-hidden="true" />
                                <div>
                                    <h2>{editingReminder ? 'Edit Reminder' : 'Add Reminder'}</h2>
                                    <p>{editingReminder ? 'Update your reminder details.' : 'Create a reminder for upcoming task.'}</p>
                                </div>
                            </div>
                            <button type="button" className="reminder-modal-close" onClick={handleCloseModal} aria-label="Close">
                                ×
                            </button>
                        </div>

                        <label className="reminder-field">
                            <span>Reminder Title<b>*</b></span>
                            <input
                                type="text"
                                placeholder="e.g submit Prayer Meeting record"
                                value={formData.title}
                                maxLength={20}
                                onChange={event => setFormData(prev => ({ ...prev, title: event.target.value }))}
                                required
                            />
                        </label>

                        <div className="reminder-field reminder-select-field">
                            <span>Type<b>*</b></span>
                            <button type="button" className="reminder-select" aria-required="true" aria-expanded={openMenu === 'type'} onClick={() => setOpenMenu(openMenu === 'type' ? '' : 'type')}>
                                <img src={CalendarIcon} alt="" aria-hidden="true" />
                                {selectedTypeLabel}
                                <SelectChevron />
                            </button>
                            {openMenu === 'type' && (
                                <div className="reminder-menu">
                                    {REMINDER_TYPES.map(option => (
                                        <button
                                            type="button"
                                            key={option.value}
                                            className={`reminder-option ${formData.type === option.value ? 'selected' : ''}`}
                                            onClick={() => {
                                                setFormData(prev => ({
                                                    ...prev,
                                                    type: option.value,
                                                    gathering: option.value === 'general' ? '' : prev.gathering,
                                                }))
                                                setError('')
                                                setOpenMenu('')
                                            }}
                                        >
                                            {option.label}
                                        </button>
                                    ))}
                                </div>
                            )}
                        </div>

                        <div className="reminder-field reminder-select-field">
                            <span>Related Gathering{!isGeneralReminder && <b>*</b>}</span>
                            <button type="button" className="reminder-select" aria-required={!isGeneralReminder} aria-expanded={!isGeneralReminder && openMenu === 'gathering'} disabled={isGeneralReminder} onClick={() => setOpenMenu(openMenu === 'gathering' ? '' : 'gathering')}>
                                <img src={GatheringIcon} alt="" aria-hidden="true" />
                                {selectedGatheringLabel}
                                <SelectChevron />
                            </button>
                            {!isGeneralReminder && openMenu === 'gathering' && (
                                <div className="reminder-menu">
                                    {GATHERING_OPTIONS.map(option => (
                                        <button
                                            type="button"
                                            key={option.value}
                                            className={`reminder-option ${formData.gathering === option.value ? 'selected' : ''}`}
                                            onClick={() => {
                                                setFormData(prev => ({ ...prev, gathering: option.value }))
                                                setError('')
                                                setOpenMenu('')
                                            }}
                                        >
                                            {option.label}
                                        </button>
                                    ))}
                                </div>
                            )}
                        </div>

                        <div className="reminder-date-row">
                            <div className="reminder-field reminder-select-field">
                                <span>Date<b>*</b></span>
                                <button type="button" className="reminder-select" aria-required="true" aria-expanded={openMenu === 'date'} onClick={() => setOpenMenu(openMenu === 'date' ? '' : 'date')}>
                                    <img src={CalendarIcon} alt="" aria-hidden="true" />
                                    {formData.date ? formatReminderDate(formData.date) : 'Select Date'}
                                    <SelectChevron />
                                </button>
                                {openMenu === 'date' && (
                                    <div className="reminder-calendar-popover">
                                        <Calendar
                                            value={formData.date}
                                            minDate={new Date(countdownNow)}
                                            onChange={date => {
                                                setFormData(prev => {
                                                    const selectedDateIsToday = !editingReminder && toDateValue(date) === todayDateValue
                                                    const selectedTimeIsTooEarly = selectedDateIsToday
                                                        && prev.time
                                                        && prev.time < minimumReminderTime
                                                    return { ...prev, date, time: selectedTimeIsTooEarly ? '' : prev.time }
                                                })
                                                setOpenMenu('')
                                            }}
                                            initialMonth={formData.date ?? new Date()}
                                        />
                                    </div>
                                )}
                            </div>

                            <label className="reminder-field reminder-time-field">
                                <span>Time<b>*</b></span>
                                <div className="reminder-time-control" onClick={openTimePicker}>
                                    <svg className="reminder-time-icon" viewBox="0 0 20 20" aria-hidden="true" focusable="false">
                                        <circle cx="10" cy="10" r="7.25" />
                                        <path d="M10 5.8V10l3 1.85" />
                                    </svg>
                                    <input
                                        ref={timeInputRef}
                                        type="time"
                                        value={formData.time}
                                        min={!editingReminder && formData.date && toDateValue(formData.date) === todayDateValue ? minimumReminderTime : undefined}
                                        step="60"
                                        onChange={event => setFormData(prev => ({ ...prev, time: event.target.value }))}
                                        required
                                    />
                                    <em className={formData.time ? '' : 'placeholder'}>{formData.time ? toTimeLabel(formData.time) : 'Select Time'}</em>
                                </div>
                            </label>
                        </div>

                        <label className="reminder-field">
                            <span>Notes(optional)</span>
                            <textarea
                                value={formData.description}
                                maxLength={NOTES_LIMIT}
                                onChange={event => setFormData(prev => ({ ...prev, description: event.target.value }))}
                                placeholder="Add additional details..."
                                rows="3"
                            />
                            <small className="reminder-note-count" aria-live="polite">{formData.description.length}/{NOTES_LIMIT}</small>
                        </label>
                        {error && <p className="reminder-modal-error" role="alert">{error}</p>}
                        <div className="reminder-modal-actions">
                            <button type="button" className="modal-cancel" onClick={handleCloseModal} disabled={saving}>
                                Cancel
                            </button>
                            <button type="submit" className="modal-save" disabled={saving}>
                                <img src={AddReminderIcon} alt="" aria-hidden="true" />
                                {saving ? 'Saving...' : editingReminder ? 'Save Changes' : 'Save Reminder'}
                            </button>
                        </div>
                    </form>
                </div>
            )}

            {exportConfirmation && (
                <div className="reminder-delete-overlay" role="presentation" onClick={() => !exportingReminderId && setExportConfirmation(null)}>
                    <section className="reminder-delete-modal monthly-export-modal" role="dialog" aria-modal="true"
                        aria-labelledby="monthly-export-confirm-title" onClick={event => event.stopPropagation()}
                        onKeyDown={event => { if (event.key === 'Escape' && !exportingReminderId) setExportConfirmation(null) }}>
                        <h2 id="monthly-export-confirm-title">Export Monthly Records?</h2>
                        <div className="reminder-delete-divider" />
                        {exportConfirmation.loading ? <p role="status">Loading export details…</p> : exportConfirmation.details && (
                            <>
                                <p className="monthly-export-scope"><strong>{exportConfirmation.details.program} · Year {exportConfirmation.details.year_level}</strong><br />{exportConfirmation.details.month}</p>
                                <dl className="monthly-export-counts">
                                    <div><dt>Coverage</dt><dd>{exportConfirmation.details.coverage}</dd></div>
                                    <div><dt>Students included</dt><dd>{exportConfirmation.details.students_included} students</dd></div>
                                </dl>
                                <p>Missing record means no attendance was recorded. No Response is a recorded attendance result.</p>
                            </>
                        )}
                        {exportConfirmation.error && <p className="reminder-modal-error" role="alert">{exportConfirmation.error}</p>}
                        <div className="reminder-delete-actions">
                            <button type="button" className="reminder-delete-cancel" autoFocus disabled={Boolean(exportingReminderId)} onClick={() => setExportConfirmation(null)}>Cancel</button>
                            <button type="button" className="reminder-delete-confirm" disabled={exportConfirmation.loading || !exportConfirmation.details || Boolean(exportingReminderId)}
                                onClick={() => exportMonthlyReminder(exportConfirmation.reminder)}>{exportingReminderId ? 'Exporting…' : 'Confirm Export'}</button>
                        </div>
                    </section>
                </div>
            )}

            {deletingReminder && (
                <div className="reminder-delete-overlay" onClick={handleCloseDeleteModal} role="presentation">
                    <div
                        className="reminder-delete-modal"
                        role="dialog"
                        aria-modal="true"
                        aria-labelledby="reminder-delete-title"
                        onClick={event => event.stopPropagation()}
                    >
                        <div className="reminder-delete-icon">
                            <TrashIcon />
                        </div>
                        <h2 id="reminder-delete-title">Delete Reminder?</h2>
                        <div className="reminder-delete-divider" />
                        <p>
                            Are you sure you want to delete this reminder? You won't be able to recover it once it's deleted.
                        </p>
                        <div className="reminder-delete-actions">
                            <button type="button" className="reminder-delete-cancel" onClick={handleCloseDeleteModal} disabled={deleting}>
                                Cancel
                            </button>
                            <button type="button" className="reminder-delete-confirm" onClick={handleConfirmDelete} disabled={deleting}>
                                {deleting ? 'Deleting…' : 'Confirm Delete'}
                            </button>
                        </div>
                    </div>
                </div>
            )}

            <ReviewDrawer
                isOpen={attendanceDrawerOpen}
                onClose={() => setAttendanceDrawerOpen(false)}
                record={activeAttendanceRecord}
                onSubmitted={() => {
                    setAttendanceDrawerOpen(false)
                    setAttendanceRefreshTrigger(current => current + 1)
                }}
            />
        </div>
    )
}

export default Reminders
