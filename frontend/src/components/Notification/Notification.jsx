import { useCallback, useEffect, useRef, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import CalendarIcon from '../../assets/calendar-icon.svg'
import NotificationIcon from '../../assets/notification-icon.svg'
import MyReminderIcon from '../../assets/my-reminder-icon.svg'
import { request } from '../../api/request'
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

        fetchControllerRef.current?.abort()

        const controller = new AbortController()
        fetchControllerRef.current = controller

        try {
                const [remindersData, attendanceData] = await Promise.all([
                    request('/reminders', { signal: controller.signal }),
                    request('/attendance?per_page=100&sort_by=day&sort_dir=desc', { signal: controller.signal }),
                ])

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
                        }
                    })

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
                                : [record.gathering, record.date].filter(Boolean).join(' · ') || 'Attendance record updated',
                            tone: meta.tone,
                            target: '/attendance',
                        }
                    })

                setItems([...reminderItems, ...attendanceItems].slice(0, 8))
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

    const iconMap = {
        calendar: CalendarIcon,
        attendance: NotificationIcon,
        'my-reminder': MyReminderIcon,
    }

    const openTarget = (target) => {
        setIsOpen(false)
        navigate(target)
    }

    return (
        <div className="notification" ref={panelRef}>
            <button
                type="button"
                className="notification-icon"
                aria-label="Notifications"
                aria-expanded={isOpen}
                onClick={() => setIsOpen(current => !current)}
            >
                <img
                    src={NotificationIcon}
                    alt=""
                    aria-hidden="true"
                />
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
                                className={`notification-item ${item.tone}`}
                                key={item.id}
                                onClick={() => openTarget(item.target)}
                            >
                                <span className={`notification-item-icon ${item.iconType}`} aria-hidden="true">
                                    <img src={iconMap[item.iconType]} alt="" />
                                </span>
                                <span className="notification-item-copy">
                                    <strong>{item.title}</strong>
                                    <small>{item.subtext}</small>
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
