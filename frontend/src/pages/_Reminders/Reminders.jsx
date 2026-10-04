import { useEffect, useRef, useState } from 'react'
import { useLocation, useNavigate } from 'react-router-dom'
import Sidebar from '../../components/Sidebar/Sidebar'
import Header from '../../components/Header/Header'
import RemindersIcon from '../../assets/reminders-icon.svg'
import NeedsActionIcon from '../../assets/needs-action-icon.svg'
import CompletedIcon from '../../assets/completed-icon.svg'
import DropDownIcon from '../../assets/drop-down-icon.svg'
import NotificationIcon from '../../assets/notification-icon.svg'
import CalendarIcon from '../../assets/calendar-icon.svg'
import GatheringIcon from '../../assets/gathering-icon.svg'
import MyReminderIcon from '../../assets/my-reminder-icon.svg'
import EditIcon from '../../assets/edit-icon.svg'
import DeleteIcon from '../../assets/delete-icon.svg'
import { request } from '../../api/request'
import Calendar from '../../components/Calendar/Calendar'
import { TrashIcon } from '../../components/Icons/Reusables'
import LoadingCircle from '../../components/Loading/LoadingCircle'
import './Reminders.css'

const FILTERS = [
    { value: 'all', label: 'All Reminders' },
    { value: 'today', label: 'Today' },
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

const EMPTY_FORM = {
    title: '',
    description: '',
    type: '',
    gathering: '',
    date: null,
    time: '',
}

const truncateReminderNotes = (notes = '') => (
    notes.length > 5 ? `${notes.slice(0, 5)}...` : notes
)

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
    const [attendanceTasks, setAttendanceTasks] = useState([])
    const [attendanceEntries, setAttendanceEntries] = useState([])
    const [activeReminder, setActiveReminder] = useState(null)
    const [highlightedReminderId, setHighlightedReminderId] = useState(null)
    const [activeFilter, setActiveFilter] = useState('all')
    const [loading, setLoading] = useState(() => Boolean(localStorage.getItem('auth_token')))
    const [error, setError] = useState('')
    const [isAddOpen, setIsAddOpen] = useState(false)
    const [editingReminder, setEditingReminder] = useState(null)
    const location = useLocation()
    const [formData, setFormData] = useState(EMPTY_FORM)
    const [openMenu, setOpenMenu] = useState('')
    const [saving, setSaving] = useState(false)
    const [deletingReminder, setDeletingReminder] = useState(null)
    const [deleting, setDeleting] = useState(false)
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
                const [remindersData, attendanceData, roleSummaryData, programHeadSubmissionData] = await Promise.all([
                    request('/reminders', { signal: controller.signal }),
                    role === 'secretary'
                        ? request('/attendance?per_page=100&sort_by=day&sort_dir=desc', { signal: controller.signal })
                        : Promise.resolve(null),
                    ['program_head', 'chancellor'].includes(role)
                        ? request('/reminders/role-summary', { signal: controller.signal }).catch(error => {
                            if (error.name === 'AbortError') throw error
                            return null
                        })
                        : Promise.resolve(null),
                    role === 'program_head'
                        ? fetchProgramHeadSubmissions(controller.signal)
                        : Promise.resolve(null),
                ])

                const nextReminders = remindersData.data ?? []
                const attendanceRows = attendanceData?.data ?? []
                const programHeadSubmissions = programHeadSubmissionData?.submissions ?? []
                const nextAttendanceTasks = role === 'program_head'
                    ? programHeadSubmissions.map(record => ({
                        id: `submission-${record.id}`,
                        type: 'attendance',
                        sourceRecord: record,
                        title: 'Attendance record awaiting review',
                        description: [record.gathering, record.date, record.program, record.year_label].filter(Boolean).join(' · '),
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
                setAttendanceEntries(attendanceRows)
                setAttendanceTasks(nextAttendanceTasks)
                if (roleSummaryData) setRoleSummary(roleSummaryData)
                setSummary({
                    upcoming: remindersData.summary?.upcoming ?? nextReminders.filter(item => item.status === 'upcoming').length,
                    needs_action: role === 'program_head'
                        ? (programHeadSubmissionData?.pendingCount ?? nextAttendanceTasks.length)
                        : nextAttendanceTasks.filter(item => item.status === 'needs_action').length,
                    completed: remindersData.summary?.completed
                        ?? nextReminders.filter(item => item.status === 'completed').length
                        + nextAttendanceTasks.filter(item => item.status === 'completed').length,
                })
            } catch (err) {
                if (err.name !== 'AbortError') {
                    setError(err.message || 'Unable to load reminders.')
                    setReminders([])
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
    }, [role])

    useEffect(() => {
        const targetReminderId = location.state?.highlightReminderId
        if (targetReminderId == null || loading) return

        const targetReminder = reminders.find(reminder => String(reminder.id) === String(targetReminderId))
        if (targetReminder) {
            setActiveReminder(null)
            setHighlightedReminderId(String(targetReminder.id))
            setActiveFilter('all')
        }

        navigate(location.pathname, { replace: true, state: null })
    }, [location.pathname, location.state, loading, reminders, navigate])

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
    const escalationCard = { title: 'Escalations', value: roleSummary.escalation_count, description: 'Students requiring intervention', icon: NotificationIcon, className: 'needs-action' }
    const needsActionCard = { title: 'Needs Action', value: summary.needs_action, description: 'Attendance awaiting your review', icon: NeedsActionIcon, className: 'needs-action' }
    const completedCard = { title: 'Completed', value: summary.completed, description: 'Completed reminders', icon: CompletedIcon, className: 'completed' }
    const reminderCards = role === 'chancellor'
        ? [upcomingCard, escalationCard, completedCard]
        : role === 'program_head'
            ? [upcomingCard, escalationCard, needsActionCard, completedCard]
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
            const gatheringDate = dateKeyInLocalTime(reminder.gathering_date || reminder.due_date)
            const isToday = isGathering && gatheringDate === todayKey
            const isPast = isGathering && gatheringDate !== '' && gatheringDate < todayKey
            const countdown = countdownByParent.get(String(reminder.id)) || reminder.countdown_text

            const gatheringKey = `${reminder.gathering_type}:${gatheringDate}`
            const alreadyRecorded = isGathering && recordedGatheringKeys.has(gatheringKey)

            const title = isGathering
                ? GATHERING_LABELS[reminder.gathering_type] || reminder.title
                : `My Reminder : ${reminder.title}`

            return {
                id: `reminder-${reminder.id}`,
                reminderId: reminder.id,
                sourceReminder: reminder,
                type: isGathering ? 'gathering' : 'custom',
                icon: isGathering ? CalendarIcon : MyReminderIcon,
                title,
                description: isGathering
                    ? formatGatheringReminderDateTime(reminder)
                    : [reminder.description ? `Note : ${truncateReminderNotes(reminder.description)}` : '', formatSavedReminderDateTime(reminder.due_date)].filter(Boolean).join(' · '),
                status: reminder.status,
                statusLabel: isGathering
                    ? alreadyRecorded
                        ? 'Ready for validation'
                        : isPast
                            ? (countdown || 'Attendance deadline passed')
                            : isToday
                                ? 'Today'
                                : 'Upcoming'
                    : 'Attendance Task',
                tone: reminder.status === 'completed' ? 'completed' : 'upcoming',
                    isToday,
                action: isGathering && isToday && !alreadyRecorded ? 'Add attendance' : null,
            }
        })

    const allItems = [...reminderItems, ...attendanceTasks]
    const visibleItems = allItems.filter(item => {
        if (activeFilter === 'all') return true
        if (activeFilter === 'today') return item.type === 'gathering' && item.isToday
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

    const openAddModal = () => {
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

    const handleEditReminder = (reminder) => {
        const { date, time } = splitDueDate(reminder.due_date)

        setEditingReminder(reminder)
        setFormData({
            ...EMPTY_FORM,
            title: reminder.title ?? '',
            description: reminder.description ?? '',
            date,
            time,
        })
        setError('')
        setIsAddOpen(true)
    }

    const handleSubmitReminder = async (event) => {
        event.preventDefault()
        setSaving(true)
        setError('')

        if (!formData.title.trim()) {
            setError('Please enter a reminder title.')
            setSaving(false)
            return
        }

        if (!formData.type || !formData.gathering) {
            setError('Please choose a type and related gathering.')
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

        const payload = {
            title: formData.title,
            description: formData.description || null,
            due_date: `${toDateValue(formData.date)}T${formData.time}`,
        }

        try {
            if (editingReminder) {
                const data = await request(`/reminders/${editingReminder.id}`, {
                    method: 'PUT',
                    body: JSON.stringify(payload),
                })
                const updated = data.data

                setReminders(prev => prev.map(reminder => (reminder.id === editingReminder.id ? { ...reminder, ...updated } : reminder)))
            } else {
                const data = await request('/reminders', {
                    method: 'POST',
                    body: JSON.stringify({ ...payload, status: 'upcoming' }),
                })
                setReminders(prev => [data.data, ...prev])
                setSummary(prev => ({
                    ...prev,
                    upcoming: prev.upcoming + 1,
                }))
            }
            notifyRemindersChanged()
            handleCloseModal()
        } catch (err) {
            setError(err.message || 'Unable to save reminder.')
        } finally {
            setSaving(false)
        }
    }

    const handleDeleteClick = (reminder) => {
        setDeletingReminder(reminder)
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

    return (
        <div className="reminders">
            <Sidebar />

            <main className="reminders-main">
                <Header
                    title="Reminders"
                    subtitle="Track upcoming gatherings and tasks that need attention."
                    className="reminders-header"
                />

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
                        {loading && <p className="reminders-state"><LoadingCircle label="Loading reminders…" /></p>}
                        {!isAddOpen && !loading && error && <p className="reminders-state reminders-state-error">{error}</p>}
                        {!isAddOpen && !loading && !error && visibleItems.length === 0 && (
                            <p className="reminders-state">No reminders found.</p>
                        )}
                        {!isAddOpen && !loading && !error && visibleItems.map(item => (
                            <article
                                id={item.reminderId ? `reminder-item-${item.reminderId}` : undefined}
                                className={`reminders-task-item ${item.tone} ${String(item.reminderId) === highlightedReminderId ? 'reminders-task-item-highlighted' : ''}`}
                                key={item.id}
                            >
                                <div className="task-main">
                                    <span className={`task-icon-wrap ${item.type === 'custom' ? 'my-reminder' : item.type === 'gathering' ? 'calendar' : 'attendance-task'}`} aria-hidden="true">
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
                                            onClick={() => {
                                                if (item.action === 'Add attendance') {
                                                    navigate('/import', {
                                                        state: {
                                                            gathering_type: item.sourceReminder.gathering_type,
                                                            gathering_date: item.sourceReminder.gathering_date,
                                                        },
                                                    })
                                                } else if (role === 'program_head') {
                                                    navigate('/program-head/submission', {
                                                        state: { openSubmission: { ...item.sourceRecord, status: 'pending' } },
                                                    })
                                                } else {
                                                    navigate('/attendance', {
                                                        state: { openAttendanceRecord: item.sourceRecord },
                                                    })
                                                }
                                            }}                                        >
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
                    <form className="reminder-modal" noValidate onSubmit={handleSubmitReminder}>
                        <div className="reminder-modal-header">
                            <div>
                                <h2>{editingReminder ? 'Edit Reminder' : 'Add Reminder'}</h2>
                                <p>{editingReminder ? 'Update your reminder details.' : 'Create a reminder for upcoming task.'}</p>
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

                        <div className="reminder-field reminder-select-field app-dropdown">
                            <span>Type<b>*</b></span>
                            <button type="button" className="reminder-select app-dropdown-trigger" aria-required="true" aria-expanded={openMenu === 'type'} onClick={() => setOpenMenu(openMenu === 'type' ? '' : 'type')}>
                                <img src={CalendarIcon} alt="" aria-hidden="true" />
                                {selectedTypeLabel}
                                <img className="select-chevron" src={DropDownIcon} alt="" aria-hidden="true" />
                            </button>
                            {openMenu === 'type' && (
                                <div className="reminder-menu app-dropdown-menu">
                                    {REMINDER_TYPES.map(option => (
                                        <button
                                            type="button"
                                            key={option.value}
                                            className={`app-dropdown-option ${formData.type === option.value ? 'selected' : ''}`}
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

                        <div className="reminder-field reminder-select-field app-dropdown">
                            <span>Related Gathering<b>*</b></span>
                            <button type="button" className="reminder-select app-dropdown-trigger" aria-required="true" aria-expanded={openMenu === 'gathering'} onClick={() => setOpenMenu(openMenu === 'gathering' ? '' : 'gathering')}>
                                <img src={GatheringIcon} alt="" aria-hidden="true" />
                                {selectedGatheringLabel}
                                <img className="select-chevron" src={DropDownIcon} alt="" aria-hidden="true" />
                            </button>
                            {openMenu === 'gathering' && (
                                <div className="reminder-menu app-dropdown-menu">
                                    {GATHERING_OPTIONS.map(option => (
                                        <button
                                            type="button"
                                            key={option.value}
                                            className={`app-dropdown-option ${formData.gathering === option.value ? 'selected' : ''}`}
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
                            <div className="reminder-field reminder-select-field app-dropdown">
                                <span>Date<b>*</b></span>
                                <button type="button" className="reminder-select app-dropdown-trigger" aria-required="true" aria-expanded={openMenu === 'date'} onClick={() => setOpenMenu(openMenu === 'date' ? '' : 'date')}>
                                    <img src={CalendarIcon} alt="" aria-hidden="true" />
                                    {formData.date ? formatReminderDate(formData.date) : 'Select Date'}
                                    <img className="select-chevron" src={DropDownIcon} alt="" aria-hidden="true" />
                                </button>
                                {openMenu === 'date' && (
                                    <div className="reminder-calendar-popover">
                                        <Calendar
                                            value={formData.date}
                                            minDate={new Date()}
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
                                maxLength={50}
                                onChange={event => setFormData(prev => ({ ...prev, description: event.target.value }))}
                                placeholder="Add additional details..."
                                rows="3"
                            />
                        </label>
                        {error && <p className="reminder-modal-error" role="alert">{error}</p>}
                        <div className="reminder-modal-actions">
                            <button type="button" className="modal-cancel" onClick={handleCloseModal} disabled={saving}>
                                Cancel
                            </button>
                            <button type="submit" className="modal-save" disabled={saving}>
                                {saving ? 'Saving...' : editingReminder ? 'Save Changes' : 'Save Reminder'}
                            </button>
                        </div>
                    </form>
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
        </div>
    )
}

export default Reminders
