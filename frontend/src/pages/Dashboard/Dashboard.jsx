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
    const [pendingAttendance, setPendingAttendance] = useState([])
    const [calendarMonth, setCalendarMonth] = useState(new Date().getMonth() + 1)
    const [calendarYear, setCalendarYear] = useState(new Date().getFullYear())
    const [calendarMarks, setCalendarMarks] = useState({
        month: '',
        year: new Date().getFullYear(),
        days_in_month: 31,
        highlight_days: [],
        today: null,
    })
    const attendanceRecords = []
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
            return
        }

        const params = new URLSearchParams({
            month: String(calendarMonth),
            year: String(calendarYear),
        })

        fetch(`http://127.0.0.1:8000/api/dashboard?${params.toString()}`, {
            headers: {
                Accept: 'application/json',
                Authorization: `Bearer ${token}`,
            },
        })
            .then(response => response.ok ? response.json() : null)
            .then(data => {
                setOverview(data?.chart_data?.series ?? [])
                setPendingAttendance(data?.pending_list ?? [])
                setCalendarMarks(data?.calendar_marks ?? defaultCalendar)
            })
            .catch(() => {
                setOverview([])
                setPendingAttendance([])
                setCalendarMarks(defaultCalendar)
            })
    }, [calendarMonth, calendarYear])

    const monthlyDays = Array.from({ length: calendarMarks.days_in_month }, (_, index) => index + 1)
    const monthStart = new Date(calendarYear, calendarMonth - 1, 1)
    const monthStartOffset = (monthStart.getDay() + 6) % 7
    const dayTypeMap = new Map((calendarMarks.highlight_days ?? []).map(day => [day.day, day.type]))

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

                                <button type="button">
                                    This Week
                                    <img
                                        className="attendance-arrow-icon"
                                        src={ArrowIcon}
                                        alt="Arrow Icon"
                                    />
                                </button>
                            </div>
                            <div className="overview-chart">
                                {overview.length === 0 || overview.every(g => g.present === 0 && g.absent === 0) ? (
                                    <span className="overview-empty">No attendance data yet</span>
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
                                <h2>This Week's Attendance Records</h2>
                                <button type="button" onClick={() => navigate('/records')}>View All Records</button>
                            </div>

                            <div className="records-table-wrapper">
                                <table className="records-table">
                                    <thead>
                                        <tr>
                                            <th>Gathering</th>
                                            <th>Date</th>
                                            <th>Time</th>
                                            <th>Response</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {attendanceRecords.length === 0 ? (
                                            <tr>
                                                <td className="records-empty" colSpan="4">No attendance records yet</td>
                                            </tr>
                                        ) : attendanceRecords.map(record => (
                                            <tr key={record.id}>
                                                <td>{record.gathering_type}</td>
                                                <td>{record.day}</td>
                                                <td>{record.batch || '-'}</td>
                                                <td>{record.status}</td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        </section>
                    </div>

                    <div className="right-column">
                        <section className="pending-attendance">
                            <div className="pending-heading">
                                <h2>Pending Attendance</h2>
                                <button type="button" onClick={() => navigate('/attendance')}>View All Attendance</button>
                            </div>
                            <div className="pending-list">
                                {pendingAttendance.length === 0 ? (
                                    <p className="pending-empty">No pending attendance</p>
                                ) : pendingAttendance.map(item => (
                                    <div className="pending-item" key={item.id}>
                                        <span className="pending-status" aria-hidden="true" />
                                        <strong>{item.program_label || item.program || 'Attendance'}</strong>
                                        <span>{item.year_level ? `${item.year_level} YEAR` : item.submitted_ago || 'Pending'}</span>
                                        <em>{item.gathering || 'Attendance'}</em>
                                    </div>
                                ))}
                            </div>
                        </section>

                        <section className="attendance-calendar">
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
                        </section>
                    </div>
                </div>


            </main>
        </div>
    )
}

export default Dashboard
