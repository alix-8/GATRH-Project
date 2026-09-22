import { useEffect, useRef, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import Sidebar from '../../components/Sidebar/Sidebar'
import PageDate from '../../components/PageDate/PageDate'
import Notification from '../../components/Notification/Notification'
import RemindersIcon from '../../assets/reminders-icon.png'
import NeedsActionIcon from '../../assets/needs-action-icon.png'
import CompletedIcon from '../../assets/completed-icon.png'
import DropDownIcon from '../../assets/drop-down-icon.png'
import NotificationIcon from '../../assets/notification-icon.png'
import CalendarIcon from '../../assets/calendar-icon.png'
import GatheringIcon from '../../assets/gathering-icon.png'
import MyReminderIcon from '../../assets/my-reminder-icon.png'
import EditIcon from '../../assets/edit-icon.png'
import DeleteIcon from '../../assets/delete-icon.png'
import { request } from '../../api/request'
import Calendar from '../../components/Calendar/Calendar'
import './Reminders.css'

const FILTERS = [
    { value: 'all', label: 'All Reminders' },
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

const ATTENDANCE_TASK_STATUS = {
    validating: {
        label: 'Attendance record ready for submission',
        tone: 'needs-action',
        action: 'Review',
    },
    pending: {
        label: 'Attendance record ready for submission',
        tone: 'needs-action',
        action: 'Review',
    },
    approved: {
        label: 'Attendance record recorded',
        tone: 'completed',
        action: 'View',
    },
}

function buildDateTime(parts) {
    return [parts.date, parts.time].filter(Boolean).join(' · ')
}

function formatReminderDate(date) {
    if (!date) return ''
    return date.toLocaleDateString('en-US', { month: 'long', day: 'numeric', year: 'numeric' })
}

const GATHERING_LABELS = {
    pm: 'Prayer Meeting',
    ws: 'Worship Service',
    tg: 'Thanksgiving',
    special: 'Special Gathering',
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
    const time = reminder.due_date ? formatSavedReminderDateTime(reminder.due_date).split(' · ')[1] : '4:30 AM'
    const program = [reminder.program, reminder.year_level].filter(Boolean).join('-')

    return [formattedDate, time, program].filter(Boolean).join(' · ')
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

function Reminders() {
    const [summary, setSummary] = useState({
        upcoming: 0,
        needs_action: 0,
        completed: 0,
    })
    const [reminders, setReminders] = useState([])
    const [attendanceTasks, setAttendanceTasks] = useState([])
    const [activeFilter, setActiveFilter] = useState('all')
    const [loading, setLoading] = useState(() => Boolean(localStorage.getItem('auth_token')))
    const [error, setError] = useState('')
    const [isAddOpen, setIsAddOpen] = useState(false)
    const [formData, setFormData] = useState({
        title: '',
        description: '',
        type: '',
        gathering: '',
        date: null,
        time: '',
    })
    const [openMenu, setOpenMenu] = useState('')
    const [saving, setSaving] = useState(false)
    const timeInputRef = useRef(null)
    const navigate = useNavigate()

    useEffect(() => {
        const token = localStorage.getItem('auth_token')

        if (!token) {
            return
        }

        const controller = new AbortController()

        async function fetchReminderData() {
            setError('')

            try {
                const [remindersData, attendanceData] = await Promise.all([
                    request('/reminders', { signal: controller.signal }),
                    request('/attendance?per_page=100&sort_by=day&sort_dir=desc', { signal: controller.signal }),
                ])

                const nextReminders = remindersData.data ?? []
                const nextAttendanceTasks = (attendanceData.data ?? [])
                    .filter(record => ['validating', 'pending', 'approved'].includes(record.status))
                    .map(record => {
                        const meta = ATTENDANCE_TASK_STATUS[record.status]

                        return {
                            id: `attendance-${record.id}`,
                            type: 'attendance',
                            sourceRecord: record,
                            title: meta.label,
                            description: buildDateTime({
                                date: record.gathering,
                                time: record.date,
                            }),
                            status: record.status === 'approved' ? 'completed' : 'needs_action',
                            statusLabel: meta.label,
                            tone: meta.tone,
                            action: meta.action,
                            createdAt: record.day_iso,
                        }
                    })

                setReminders(nextReminders)
                setAttendanceTasks(nextAttendanceTasks)
                setSummary({
                    upcoming: remindersData.summary?.upcoming ?? nextReminders.filter(item => item.status === 'upcoming').length,
                    needs_action: nextAttendanceTasks.filter(item => item.status === 'needs_action').length,
                    completed: nextAttendanceTasks.filter(item => item.status === 'completed').length,
                })
            } catch (err) {
                if (err.name !== 'AbortError') {
                    setError(err.message || 'Unable to load reminders.')
                    setReminders([])
                    setAttendanceTasks([])
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
    }, [])

    const reminderCards = [
        {
            title: 'Upcoming',
            value: summary.upcoming,
            description: 'Scheduled activities',
            icon: RemindersIcon,
            className: 'upcoming',
        },
        {
            title: 'Needs Action',
            value: summary.needs_action,
            description: 'Tasks requiring attention',
            icon: NeedsActionIcon,
            className: 'needs-action',
        },
        {
            title: 'Completed',
            value: summary.completed,
            description: 'Completed reminders',
            icon: CompletedIcon,
            className: 'completed',
        },
    ]

    const reminderItems = reminders
        .filter(reminder => reminder.reminder_type !== 'submission_countdown')
        .map(reminder => {
            const isGathering = reminder.reminder_type === 'gathering'
            const title = isGathering
                ? GATHERING_LABELS[reminder.gathering_type] || reminder.title
                : `My Reminder : ${reminder.title}`

            return {
                id: `reminder-${reminder.id}`,
                reminderId: reminder.id,
                type: isGathering ? 'gathering' : 'custom',
                icon: isGathering ? CalendarIcon : MyReminderIcon,
                title,
                description: isGathering
                    ? formatGatheringReminderDateTime(reminder)
                    : [reminder.description ? `Note : ${reminder.description}` : '', formatSavedReminderDateTime(reminder.due_date)].filter(Boolean).join(' · '),
                status: reminder.status,
                statusLabel: isGathering
                    ? 'Upcoming'
                    : 'Attendance Task',
                tone: reminder.status === 'completed' ? 'completed' : 'upcoming',
                action: null,
            }
        })

    const allItems = [...reminderItems, ...attendanceTasks]
    const visibleItems = allItems.filter(item => {
        if (activeFilter === 'all') return true
        if (activeFilter === 'mine') return item.type === 'custom'
        if (activeFilter === 'needs_action') return item.type === 'attendance' && item.status === 'needs_action'
        if (activeFilter === 'completed') return item.type === 'attendance' && item.status === 'completed'
        return true
    })

    const selectedTypeLabel = REMINDER_TYPES.find(option => option.value === formData.type)?.label ?? 'Attendance Task'
    const selectedGatheringLabel = GATHERING_OPTIONS.find(option => option.value === formData.gathering)?.label ?? 'Gathering'

    const openTimePicker = () => {
        if (timeInputRef.current?.showPicker) {
            timeInputRef.current.showPicker()
            return
        }

        timeInputRef.current?.focus()
    }

    const handleAddReminder = async (event) => {
        event.preventDefault()
        setSaving(true)
        setError('')

        if (!formData.date || !formData.time) {
            setError('Please choose a date and time for the reminder.')
            setSaving(false)
            return
        }

        try {
            const payload = {
                title: formData.title,
                description: formData.description || null,
                due_date: `${toDateValue(formData.date)}T${formData.time}`,
                status: 'upcoming',
            }
            const data = await request('/reminders', {
                method: 'POST',
                body: JSON.stringify(payload),
            })
            const nextReminders = [data.data, ...reminders]
            setReminders(nextReminders)
            setSummary(prev => ({
                ...prev,
                upcoming: prev.upcoming + 1,
            }))
            setFormData({
                title: '',
                description: '',
                type: '',
                gathering: '',
                date: null,
                time: '',
            })
            setIsAddOpen(false)
        } catch (err) {
            setError(err.message || 'Unable to add reminder.')
        } finally {
            setSaving(false)
        }
    }

    const handleDeleteReminder = async (reminderId) => {
        try {
            await request(`/reminders/${reminderId}`, { method: 'DELETE' })
            setReminders(prev => prev.filter(r => r.id !== reminderId))
            setSummary(prev => ({
                ...prev,
                upcoming: Math.max(0, prev.upcoming - 1),
            }))
        } catch (err) {
            setError(err.message || 'Unable to delete reminder.')
        }
    }

    return (
        <div className="reminders">
            <Sidebar />

            <main className="reminders-main">
                <header className="reminders-header page-header">
                    <h3>Reminders</h3>
                    <p>Track upcoming gatherings and tasks that need attention.</p>
                    <PageDate />
                    <Notification />
                </header>

                <section className="reminders-cards" aria-label="Reminder summary">
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
                            <h2>Reminders &amp; Tasks</h2>
                            <p>{visibleItems.length} items requiring attention or scheduled today</p>
                        </div>

                        <button type="button" className="add-reminder-button" onClick={() => setIsAddOpen(true)}>
                            <span aria-hidden="true">+</span>
                            Add Reminder
                        </button>
                    </div>

                    <div className="reminders-filter-row" role="tablist" aria-label="Reminder filters">
                        {FILTERS.map(filter => (
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
                        {loading && <p className="reminders-state">Loading reminders...</p>}
                        {!loading && error && <p className="reminders-state reminders-state-error">{error}</p>}
                        {!loading && !error && visibleItems.length === 0 && (
                            <p className="reminders-state">No reminders found.</p>
                        )}
                        {!loading && !error && visibleItems.map(item => (
                            <article className={`reminders-task-item ${item.tone}`} key={item.id}>
                                <div className="task-main">
                                    <span className={`task-icon-wrap ${item.type === 'custom' ? 'my-reminder' : item.type === 'gathering' ? 'calendar' : 'attendance'}`} aria-hidden="true">
                                        <img
                                            className="task-icon"
                                            src={item.icon ?? NotificationIcon}
                                            alt=""
                                        />
                                    </span>

                                    <div className="task-copy">
                                        <h3>{item.title}</h3>
                                        <p>{item.description || 'No details added'}</p>
                                    </div>
                                </div>

                                <div className="task-status">
                                    <strong>{item.statusLabel}</strong>
                                    {item.type === 'custom' && (
                                        <div className="task-actions">
                                            <button type="button" className="task-action-btn" title="Edit reminder">
                                                <img src={EditIcon} alt="Edit" />
                                            </button>
                                            <button type="button" className="task-action-btn" title="Delete reminder" onClick={() => handleDeleteReminder(item.reminderId)}>
                                                <img src={DeleteIcon} alt="Delete" />
                                            </button>
                                        </div>
                                    )}
                                    {item.action && (
                                        <button type="button" onClick={() => navigate('/attendance')}>
                                            {item.action}
                                            <img src={DropDownIcon} alt="" aria-hidden="true" />
                                        </button>
                                    )}
                                </div>
                            </article>
                        ))}
                    </div>
                </section>
            </main>

            {isAddOpen && (
                <div className="reminder-modal-backdrop">
                    <form className="reminder-modal" onSubmit={handleAddReminder}>
                        <div className="reminder-modal-header">
                            <div>
                                <h2>Add Reminder</h2>
                                <p>Create a reminder for upcoming task.</p>
                            </div>
                            <button type="button" className="reminder-modal-close" onClick={() => setIsAddOpen(false)} aria-label="Close">
                                ×
                            </button>
                        </div>

                        <label className="reminder-field">
                            <span>Reminder Title<b>*</b></span>
                            <input
                                type="text"
                                placeholder="e.g submit Prayer Meeting record"
                                value={formData.title}
                                onChange={event => setFormData(prev => ({ ...prev, title: event.target.value }))}
                                required
                            />
                        </label>

                        <div className="reminder-field reminder-select-field">
                            <span>Type<b>*</b></span>
                            <button type="button" className="reminder-select" onClick={() => setOpenMenu(openMenu === 'type' ? '' : 'type')}>
                                <img src={CalendarIcon} alt="" aria-hidden="true" />
                                {selectedTypeLabel}
                                <img className="select-chevron" src={DropDownIcon} alt="" aria-hidden="true" />
                            </button>
                            {openMenu === 'type' && (
                                <div className="reminder-menu">
                                    {REMINDER_TYPES.map(option => (
                                        <button
                                            type="button"
                                            key={option.value}
                                            className={formData.type === option.value ? 'selected' : ''}
                                            onClick={() => {
                                                setFormData(prev => ({ ...prev, type: option.value }))
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
                            <span>Related Gathering<b>*</b></span>
                            <button type="button" className="reminder-select" onClick={() => setOpenMenu(openMenu === 'gathering' ? '' : 'gathering')}>
                                <img src={GatheringIcon} alt="" aria-hidden="true" />
                                {selectedGatheringLabel}
                                <img className="select-chevron" src={DropDownIcon} alt="" aria-hidden="true" />
                            </button>
                            {openMenu === 'gathering' && (
                                <div className="reminder-menu">
                                    {GATHERING_OPTIONS.map(option => (
                                        <button
                                            type="button"
                                            key={option.value}
                                            className={formData.gathering === option.value ? 'selected' : ''}
                                            onClick={() => {
                                                setFormData(prev => ({ ...prev, gathering: option.value }))
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
                                <button type="button" className="reminder-select" onClick={() => setOpenMenu(openMenu === 'date' ? '' : 'date')}>
                                    <img src={CalendarIcon} alt="" aria-hidden="true" />
                                    {formData.date ? formatReminderDate(formData.date) : 'Select Date'}
                                    <img className="select-chevron" src={DropDownIcon} alt="" aria-hidden="true" />
                                </button>
                                {openMenu === 'date' && (
                                    <div className="reminder-calendar-popover">
                                        <Calendar
                                            value={formData.date}
                                            onChange={date => {
                                                setFormData(prev => ({ ...prev, date }))
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
                                onChange={event => setFormData(prev => ({ ...prev, description: event.target.value }))}
                                placeholder="Add additional details..."
                                rows="3"
                            />
                        </label>
                        <div className="reminder-modal-actions">
                            <button type="button" className="modal-cancel" onClick={() => setIsAddOpen(false)} disabled={saving}>
                                Cancel
                            </button>
                            <button type="submit" className="modal-save" disabled={saving}>
                                {saving ? 'Saving...' : 'Save Reminder'}
                            </button>
                        </div>
                    </form>
                </div>
            )}
        </div>
    )
}

export default Reminders
