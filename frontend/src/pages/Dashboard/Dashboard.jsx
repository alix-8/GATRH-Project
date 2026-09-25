import { useEffect, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import Sidebar from '../../components/Sidebar/Sidebar'
import PageDate from '../../components/PageDate/PageDate'
import Notification from '../../components/Notification/Notification'
import PencilIcon from '../../assets/pencil-icon.png'
import EraserIcon from '../../assets/eraser-icon.png'
import DashboardIllustration from '../../assets/dashboard-illustration.png'
import ArrowIcon from '../../assets/arrow-icon.png'
import './Dashboard.css'

function Dashboard() {
    const [overview, setOverview] = useState([])
    const [overviewError, setOverviewError] = useState('')
    const [recentRecords, setRecentRecords] = useState([])
    const [monthlyGathering, setMonthlyGathering] = useState([])
    const [tasksAndReminders, setTasksAndReminders] = useState([])
    const [attendancePeriod, setAttendancePeriod] = useState('week')
    const [calendarMonth, setCalendarMonth] = useState(new Date().getMonth() + 1)
    const [calendarYear, setCalendarYear] = useState(new Date().getFullYear())
    const [calendarMarks, setCalendarMarks] = useState({
        month: '',
        year: new Date().getFullYear(),
        days_in_month: 31,
        highlight_days: [],
        today: null,
    })
    const navigate = useNavigate()

    const defaultCalendar = {
        month: new Date(calendarYear, calendarMonth - 1, 1).toLocaleString('en-US', {
            month: 'long',
            year: 'numeric',
        }),
        year: calendarYear,
        days_in_month: new Date(calendarYear, calendarMonth, 0).getDate(),
        highlight_days: [],
        today: null,
    }

    useEffect(() => {
        const token = localStorage.getItem('auth_token')

        if (!token) {
            setOverviewError('Please sign in to load attendance data.')
            return
        }

        const params = new URLSearchParams({
            month: String(calendarMonth),
            year: String(calendarYear),
            attendance_period: attendancePeriod,
        })

        fetch(`http://127.0.0.1:8000/api/dashboard?${params.toString()}`, {
            headers: {
                Accept: 'application/json',
                Authorization: `Bearer ${token}`,
            },
        })
            .then(response => {
                if (!response.ok) throw new Error(`Dashboard request failed (${response.status}).`)
                return response.json()
            })
            .then(data => {
                setOverview(data?.attendance_overview ?? data?.chart_data?.series ?? [])
                setOverviewError('')
                setRecentRecords(data?.recent_records ?? [])
                setTasksAndReminders(data?.tasks_and_reminders ?? [])
                setMonthlyGathering(data?.monthly_gathering ?? [])
                setCalendarMarks(data?.calendar_marks ?? defaultCalendar)
            })
            .catch(error => {
                setOverview([])
                setOverviewError(error.message || 'Unable to load attendance data.')
                setRecentRecords([])
                setTasksAndReminders([])
                setCalendarMarks(defaultCalendar)
            })
    }, [calendarMonth, calendarYear, attendancePeriod])

    const monthlyDays = Array.from({ length: calendarMarks.days_in_month }, (_, index) => index + 1)
    const monthStart = new Date(calendarYear, calendarMonth - 1, 1)
    const monthStartOffset = (monthStart.getDay() + 6) % 7
    const selectedMonthLabel = monthStart.toLocaleString('en-US', { month: 'long', year: 'numeric' })
    const dayTypeMap = new Map((calendarMarks.highlight_days ?? []).map(day => [day.day, day.type]))
    const reminderCategories = ['My Reminder', 'System Reminder', 'Needs Action', 'Submission']
    const categorizedReminders = reminderCategories.flatMap(category =>
        tasksAndReminders
            .filter(item => item.category === category)
            .map(item => ({ ...item, category }))
    )

    const changeMonth = amount => {
        const nextDate = new Date(calendarYear, calendarMonth - 1 + amount, 1)
        const nextMonth = nextDate.getMonth() + 1
        const nextYear = nextDate.getFullYear()
        setCalendarMonth(nextMonth)
        setCalendarYear(nextYear)
        setCalendarMarks({
            month: nextDate.toLocaleString('en-US', { month: 'long', year: 'numeric' }),
            year: nextYear,
            days_in_month: new Date(nextYear, nextMonth, 0).getDate(),
            highlight_days: [],
            today: null,
        })
    }

    return (
        <div className="dashboard">
            <Sidebar />

            <main className="dashboard-main">
                <header className="dashboard-header page-header">
                    <h3>Dashboard</h3>
                    <p>Monitor student attendance.</p>
                    <PageDate className="dashboard-date" />
                    <Notification />
                </header>

                <div className="dashboard-content">
                    <div className="dashboard-content-column-left">
                        <div className="dashboard-text-upper">
                            <h2>Attendance at a glance.
                            </h2>
                            <p>Monitor student attendance, absences, and records in one place.
                            </p>
                        </div>

                        <div className="dashboard-text-lower">
                            <div className="dashboard-pencil-container">
                                <img
                                    className="dashboard-pencil-icon"
                                    src={PencilIcon}
                                    alt="Pencil Icon"
                                />
                            </div>
                            <p className="week-attendance">This Week's Attendance</p>

                            <div className="dashboard-eraser-container">
                                <img
                                    className="dashboard-eraser-icon"
                                    src={EraserIcon}
                                    alt="Eraser Icon"
                                />
                            </div>
                            <p className="week-absences">This Week's Absences</p>
                        </div>
                    </div>
                    <div className="dashboard-content-column-right">
                        <img
                            className="dashboard-illustration"
                            src={DashboardIllustration}
                            alt="Dashboard Illustration"
                        />
                    </div>

                </div>


                <div className="dashboard-grid">
                    <div className="dashboard-left-column">
                        <section className="attendance-overview">
                            <div className="overview-heading">
                                <div>
                                    <h2>Attendance Overview</h2>
                                    <div className="overview-legend">
                                        <span><i className="overview-present" />present</span>
                                        <span><i className="overview-absent" />absent</span>
                                    </div>
                                </div>

                                <select
                                    value={attendancePeriod}
                                    onChange={event => setAttendancePeriod(event.target.value)}
                                    aria-label="Attendance graph date range"
                                >
                                    <option value="week">This Week</option>
                                    <option value="month">This Month</option>
                                </select>
                            </div>
                            <div className="overview-chart">
                                {overviewError ? <span className="overview-empty">{overviewError} Check that the API is running and you are signed in.</span> : overview.length === 0 ? (
                                    <span className="overview-empty">No attendance data returned for this month.</span>
                                ) : overview.map(group => (
                                    <div className="overview-group" key={group.category}>
                                        <div className="overview-bars">
                                            <span className="overview-bar overview-present-bar" style={{ height: `${group.present * 12}px` }} />
                                            <span className="overview-bar overview-absent-bar" style={{ height: `${group.absent * 12}px` }} />
                                        </div>
                                        <small>{group.category}</small>
                                    </div>
                                ))}
                            </div>
                        </section>

                        <section className="attendance-records">
                            <div className="records-heading">
                                <h2>Recent Attendance Records</h2>
                                <button type="button" onClick={() => navigate('/attendance')}>View All Records</button>
                            </div>

                            <div className="records-table-wrapper">
                                <table className="records-table">
                                    <thead>
                                        <tr>
                                            <th>Gathering</th>
                                            <th>Date</th>
                                            <th>Students</th>
                                            <th>Status</th>
                                            <th>Action</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {recentRecords.length === 0 ? (
                                            <tr>
                                                <td className="records-empty" colSpan="5">No attendance records yet</td>
                                            </tr>
                                        ) : recentRecords.map(record => (
                                            <tr key={record.id}>
                                                <td>{record.gathering}</td>
                                                <td>{record.date}</td>
                                                <td>{record.students}</td>
                                                <td>{record.status_label || record.status}</td>
                                                <td>
                                                    <button type="button" className="action-btn" onClick={() => navigate('/attendance')}>
                                                        {record.action || 'View'} &gt;
                                                    </button>
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        </section>
                    </div>

                    <div className="right-column">
                        <section className="dashboard-reminders">
                            <div className="dashboard-reminders-heading">
                                <div><h3>Task and Reminders</h3><p>Items that need your attention.</p></div>
                                <button type="button" onClick={() => navigate('/reminders')}>View All Reminders <span>→</span></button>
                            </div>
                            {categorizedReminders.length === 0 ? <p className="dashboard-reminders-empty">No upcoming tasks or reminders.</p> : (
                                <div className="dashboard-reminder-list">
                                    {categorizedReminders.map(item => (
                                        <div className="dashboard-reminder-item" key={`${item.category}-${item.id}`}>
                                            <span className="dashboard-reminder-category">{item.category}</span>
                                            <span className="dashboard-reminder-description">{item.description || item.title}</span>
                                            <time>{item.date || ''}</time>
                                            <button type="button" onClick={() => navigate('/reminders')}>View <span>›</span></button>
                                        </div>
                                    ))}
                                </div>
                            )}
                        </section>
                        {/* <section className="attendance-calendar">
                            <div className="calendar-header">
                                <h3>Attendance Calendar</h3>
                                <button type="button" className="calendar-view-button">View calendar</button>
                            </div>

                            <div className="calendar-body">
                                <div className="calendar-toolbar">
                                    <button type="button" className="calendar-nav" onClick={() => changeMonth(-1)} aria-label="Previous month">‹</button>
                                    <span className="calendar-month-label">{calendarMarks.month || defaultCalendar.month}</span>
                                    <button type="button" className="calendar-nav" onClick={() => changeMonth(1)} aria-label="Next month">›</button>
                                </div>

                                <div className="calendar-weekdays">
                                    {['Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa', 'Su'].map(day => (
                                        <span key={day}>{day}</span>
                                    ))}
                                </div>

                                <div className="calendar-grid">
                                    {Array.from({ length: monthStartOffset }, (_, index) => (
                                        <span key={`blank-${index}`} className="calendar-day empty" aria-hidden="true" />
                                    ))}

                                    {monthlyDays.map(day => {
                                        const entryType = dayTypeMap.get(day)
                                        return (
                                            <span
                                                key={day}
                                                className={[
                                                    'calendar-day',
                                                    entryType === 'pending' ? 'calendar-day-pending' : '',
                                                    entryType === 'has_records' ? 'calendar-day-record' : '',
                                                    day === calendarMarks.today ? 'calendar-day-today' : '',
                                                ].filter(Boolean).join(' ')}
                                            >
                                                {day}
                                            </span>
                                        )
                                    })}
                                </div>
                            </div>
                        </section> */}
                        <section className="monthly-gathering-card">
                            <h3>Monthly Attendance by Gathering</h3>
                            <p>Overall student response across the month of {selectedMonthLabel.split(' ')[0]}.</p>

                            <div className="monthly-gathering-list">
                                {monthlyGathering.map(item => (
                                    <div className="monthly-gathering-item" key={item.gathering}>
                                        <div className="monthly-gathering-label">
                                            <span>{item.gathering}</span>
                                            <span>{item.percentage}%</span>
                                        </div>
                                        <div className="monthly-gathering-track">
                                            <div
                                                className="monthly-gathering-fill"
                                                style={{ width: `${item.percentage}%` }}
                                            />
                                        </div>
                                    </div>
                                ))}
                            </div>
                        </section>
                    </div>
                </div>


            </main>
        </div>
    )
}

export default Dashboard
