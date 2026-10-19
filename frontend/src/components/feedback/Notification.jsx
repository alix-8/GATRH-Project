import { useCallback, useEffect, useRef, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import CalendarIcon from '../../assets/icons/calendar-icon.svg'
import NotificationIcon from '../../assets/icons/notification-icon.svg'
import MyReminderIcon from '../../assets/icons/my-reminder-icon.svg'
import EscalationsIcon from '../../assets/icons/escalations-icon.svg'
import { request } from '../../api/request'
import RecordDate from '../RecordDate'
import { getSeenNotificationIds, markNotificationIdsSeen } from './notificationReadState'
import './Notification.css'

function formatReminderTime(value) {
    if (!value) return 'No date set'

    const date = new Date(value)
    if (Number.isNaN(date.getTime())) return value

    const today = new Date()
    const tomorrow = new Date()
    tomorrow.setDate(today.getDate() + 1)

    const isSameDay = (left, right) => (
        left.getFullYear() === right.getFullYear()
        && left.getMonth() === right.getMonth()
        && left.getDate() === right.getDate()
    )

    const dayLabel = isSameDay(date, today)
        ? 'Today'
        : isSameDay(date, tomorrow)
            ? 'Tomorrow'
            : date.toLocaleDateString('en-US', { month: 'short', day: 'numeric' })

    const timeLabel = date.toLocaleTimeString('en-US', {
        hour: 'numeric',
        minute: '2-digit',
        hour12: true,
    })

    return `${dayLabel} · ${timeLabel}`
}

const ATTENDANCE_STATUS = {
    validating: {
        title: 'Attendance needs review',
        tone: 'needs-attention',
    },
    pending: {
        title: 'Attendance needs review',
        tone: 'needs-attention',
    },
    approved: {
        title: 'Attendance approved',
        tone: 'read',
    },
}

const GATHERING_LABELS = {
    pm: 'Prayer Meeting',
    ws: 'Worship Service',
    tg: 'Thanksgiving',
}

function Notification() {
    const [isOpen, setIsOpen] = useState(false)
    const [items, setItems] = useState([])
    const [hasUnreadReminder, setHasUnreadReminder] = useState(false)
    const panelRef = useRef(null)
    const navigate = useNavigate()

    useEffect(() => {
        function handleOutsideClick(event) {
            if (panelRef.current && !panelRef.current.contains(event.target)) {
                setIsOpen(false)
            }
        }

        document.addEventListener('mousedown', handleOutsideClick)
        return () => document.removeEventListener('mousedown', handleOutsideClick)
    }, [])

    const fetchControllerRef = useRef(null)

    const fetchNotifications = useCallback(async () => {
        const token = localStorage.getItem('auth_token')

        if (!token) return

        let isCoordinator = false
        try {
            isCoordinator = JSON.parse(localStorage.getItem('user') || 'null')?.role === 'cm_coordinator'
        } catch {
            // A missing or stale cached user should not prevent reminders loading.
        }

        fetchControllerRef.current?.abort()

        const controller = new AbortController()
        fetchControllerRef.current = controller

        try {
                const [remindersData, attendanceData] = await Promise.all([
                    request('/reminders', { signal: controller.signal }),
                    isCoordinator
                        ? Promise.resolve({ data: [] })
                        : request('/attendance?per_page=100&sort_by=day&sort_dir=desc', { signal: controller.signal })
                            .catch(error => {
                                if (error.name === 'AbortError') throw error
                                return { data: [] }
                            }),
                ])

                const escalationItems = (remindersData.escalations ?? []).map(escalation => ({
                    id: `escalation-${escalation.id}`,
                    iconType: 'escalation',
                    title: 'Student Requires Attention',
                    subtext: escalation.description,
                    tone: 'needs-attention',
                    target: '/reminders',
                    targetState: { highlightEscalationId: escalation.id },
                }))

                const reminderItems = (remindersData.data ?? [])
                    .filter(reminder => reminder.status !== 'completed' && reminder.reminder_type !== 'submission_countdown')
                    .map(reminder => {
                        const isGathering = reminder.reminder_type === 'gathering'
                        const isPersonal = !isGathering

                        return {
                            id: `reminder-${reminder.id}`,
                            iconType: isPersonal ? 'my-reminder' : 'calendar',
                            title: isPersonal
                                ? `My Reminder: ${reminder.title}`
                                : GATHERING_LABELS[reminder.gathering_type] || reminder.title,
                            subtext: formatReminderTime(reminder.due_date),
                            tone: reminder.status === 'needs_action' ? 'needs-attention' : 'read',
                            target: '/reminders',
                            targetState: { highlightReminderId: reminder.id },
                            tracksBellDot: true,
                        }
                    })

                const dueMyReminderItems = (remindersData.data ?? [])
                    .filter(reminder => reminder.reminder_type === 'custom'
                        && reminder.status !== 'completed'
                        && reminder.due_date
                        && new Date(reminder.due_date).getTime() <= Date.now())
                    .map(reminder => ({
                        id: `my-reminder-due-${reminder.id}`,
                        iconType: 'my-reminder',
                        title: 'My Reminder due date reached',
                        subtext: reminder.title,
                        tone: 'needs-attention',
                        target: '/reminders',
                        targetState: { highlightReminderId: reminder.id },
                        tracksBellDot: true,
                        clearOnClick: false,
                    }))

                const dueAttendanceItems = (remindersData.data ?? [])
                    .filter(reminder => reminder.reminder_type === 'submission_countdown'
                        && reminder.status !== 'completed'
                        && reminder.due_date
                        && new Date(reminder.due_date).getTime() <= Date.now())
                    .map(reminder => ({
                        id: `deadline-${reminder.parent_reminder_id ?? reminder.id}`,
                        iconType: 'attendance',
                        title: 'Attendance submission deadline reached',
                        subtext: reminder.description || GATHERING_LABELS[reminder.gathering_type] || 'Attendance is due',
                        tone: 'needs-attention',
                        target: '/reminders',
                        targetState: { highlightDeadlineId: reminder.id },
                        tracksBellDot: true,
                        clearOnClick: false,
                    }))

                const attendanceItems = (attendanceData.data ?? [])
                    .filter(record => ['validating', 'pending', 'approved'].includes(record.status))
                    .map(record => {
                        const meta = ATTENDANCE_STATUS[record.status] ?? ATTENDANCE_STATUS.pending

                        return {
                            id: `attendance-${record.id ?? record.ref_id}`,
                            iconType: 'attendance',
                            title: meta.title,
                            subtext: record.responded_count && record.total_students
                                ? `${record.responded_count}/${record.total_students} students responded`
                                : record.gathering || 'Attendance record updated',
                            recordDate: record.day_iso || record.date,
                            tone: meta.tone,
                            target: '/reminders',
                            targetState: { highlightAttendanceId: record.id },
                        }
                    })

                const nextItems = [...escalationItems, ...dueAttendanceItems, ...dueMyReminderItems, ...reminderItems, ...attendanceItems]
                const trackedIds = nextItems.filter(item => item.tracksBellDot).map(item => item.id)
                const seenIds = getSeenNotificationIds()
                setHasUnreadReminder(trackedIds.some(id => !seenIds.has(String(id))))
                setItems(nextItems.slice(0, 8))
        } catch {
            // Aborted or failed refresh: keep whatever items are already shown.
        }
    }, [])

    useEffect(() => {
        fetchNotifications()

        return () => fetchControllerRef.current?.abort()
    }, [fetchNotifications])

    // Refetch whenever the panel opens so reminders deleted, added, or edited
    // on the Reminders page show up without a full page refresh.
    useEffect(() => {
        if (isOpen) {
            fetchNotifications()
        }
    }, [isOpen, fetchNotifications])

    // Refetch immediately when reminders change elsewhere on the page
    // (covers the case where the panel is already open).
    useEffect(() => {
        window.addEventListener('reminders:changed', fetchNotifications)
        return () => window.removeEventListener('reminders:changed', fetchNotifications)
    }, [fetchNotifications])

    useEffect(() => {
        window.addEventListener('notifications:changed', fetchNotifications)
        return () => window.removeEventListener('notifications:changed', fetchNotifications)
    }, [fetchNotifications])

    useEffect(() => {
        const intervalId = window.setInterval(fetchNotifications, 60_000)
        return () => window.clearInterval(intervalId)
    }, [fetchNotifications])

    const iconMap = {
        calendar: CalendarIcon,
        attendance: NotificationIcon,
        'my-reminder': MyReminderIcon,
        escalation: EscalationsIcon,
    }

    const openTarget = (target, state = null, notificationId = null) => {
        if (notificationId) markNotificationIdsSeen([notificationId])
        setIsOpen(false)
        navigate(target, { state })
    }

    return (
        <div className="notification" ref={panelRef}>
            <button
                type="button"
                className="notification-icon"
                aria-label={hasUnreadReminder ? 'Notifications, new reminders' : 'Notifications'}
                aria-expanded={isOpen}
                onClick={() => setIsOpen(current => !current)}
            >
                <img
                    src={NotificationIcon}
                    alt=""
                    aria-hidden="true"
                />
                {hasUnreadReminder && <span className="notification-bell-dot" aria-label="New reminders" />}
            </button>

            {isOpen && (
                <section className="notification-panel" aria-label="Reminder notifications">
                    <h2>Reminders</h2>

                    <div className="notification-list">
                        {items.length === 0 ? (
                            <p className="notification-empty">No reminders yet.</p>
                        ) : items.map(item => (
                            <button
                                type="button"
                                className={`notification-item ${item.tone} ${item.iconType === 'escalation' ? 'escalation' : ''}`}
                                key={item.id}
                                onClick={() => openTarget(
                                    item.target,
                                    item.targetState,
                                    item.clearOnClick === false ? null : item.id
                                )}
                            >
                                <span className={`notification-item-icon ${item.iconType}`} aria-hidden="true">
                                    <img src={iconMap[item.iconType]} alt="" />
                                </span>
                                <span className="notification-item-copy">
                                    <strong>{item.title}</strong>
                                    <small>
                                        {item.subtext}
                                        {item.iconType === 'attendance' && item.recordDate && (
                                            <> · <RecordDate value={item.recordDate} /></>
                                        )}
                                    </small>
                                </span>
                                <span className="notification-item-dot" aria-hidden="true" />
                            </button>
                        ))}
                    </div>

                    <button type="button" className="notification-view-all" onClick={() => openTarget('/reminders')}>
                        View all
                    </button>
                </section>
            )}
        </div>
    )
}

export default Notification
