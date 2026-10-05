import { useEffect, useRef, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import Sidebar from '../../../components/Sidebar/Sidebar'
import Header from '../../../components/Header/Header'

import PencilIcon from '../../../assets/pencil-icon.svg'
import EraserIcon from '../../../assets/eraser-icon.svg'
import DropdownIcon from '../../../assets/drop-down-icon.svg'
import DashboardIllustration from '../../../assets/dashboard-illustration.svg'
import TotalStudentsKpiIcon from '../../../assets/total-students-kpi-icon.svg'
import ResponseKpiIcon from '../../../assets/response-kpi-icon.svg'
import NoResponseKpiIcon from '../../../assets/no-response-kpi-icon.svg'
import PendingSubmissionKpiIcon from '../../../assets/pending-submission-kpi-icon.svg'
import DashboardSkeleton from '../../../skeletons/Dashboard/DashboardSkeleton'
import './SecDashboard.css'

const gatheringLabels = {
    PM: 'Prayer Meeting',
    WS: 'Worship Service',
    TG: 'Thanksgiving',
}

function Dashboard() {
    const [isLoading, setIsLoading] = useState(true)
    const [overview, setOverview] = useState([])
    const [overviewError, setOverviewError] = useState('')
    const [kpis, setKpis] = useState(null)
    const [recentRecords, setRecentRecords] = useState([])
    const [monthlyGathering, setMonthlyGathering] = useState([])
    const [tasksAndReminders, setTasksAndReminders] = useState([])
    const [attendancePeriod, setAttendancePeriod] = useState('week')
    const [isAttendancePeriodOpen, setIsAttendancePeriodOpen] = useState(false)
    const attendancePeriodDropdownRef = useRef(null)
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
            setIsLoading(false)
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
                setKpis(data?.kpis ?? null)
                setRecentRecords(data?.recent_records ?? [])
                setTasksAndReminders(data?.tasks_and_reminders ?? [])
                setMonthlyGathering(data?.monthly_gathering ?? [])
                setCalendarMarks(data?.calendar_marks ?? defaultCalendar)
            })
            .catch(error => {
                setOverview([])
                setOverviewError(error.message || 'Unable to load attendance data.')
                setKpis(null)
                setRecentRecords([])
                setTasksAndReminders([])
                setCalendarMarks(defaultCalendar)
            })
            .finally(() => setIsLoading(false))
    }, [calendarMonth, calendarYear, attendancePeriod])

    useEffect(() => {
        if (!isAttendancePeriodOpen) return undefined

        const closeWhenOutside = event => {
            if (!attendancePeriodDropdownRef.current?.contains(event.target)) {
                setIsAttendancePeriodOpen(false)
            }
        }
        const closeOnEscape = event => {
            if (event.key === 'Escape') setIsAttendancePeriodOpen(false)
        }

        document.addEventListener('mousedown', closeWhenOutside)
        document.addEventListener('keydown', closeOnEscape)
        return () => {
            document.removeEventListener('mousedown', closeWhenOutside)
            document.removeEventListener('keydown', closeOnEscape)
        }
    }, [isAttendancePeriodOpen])

    const monthlyDays = Array.from({ length: calendarMarks.days_in_month }, (_, index) => index + 1)
    const monthStart = new Date(calendarYear, calendarMonth - 1, 1)
    const monthStartOffset = (monthStart.getDay() + 6) % 7
    const selectedMonthLabel = monthStart.toLocaleString('en-US', { month: 'long', year: 'numeric' })
    const chartTicks = [30, 25, 20, 15, 10, 5, 0]
    const dayTypeMap = new Map((calendarMarks.highlight_days ?? []).map(day => [day.day, day.type]))
    const reminderCategories = ['My Reminder', 'System Reminder', 'Needs Action', 'Submission']
    const categorizedReminders = reminderCategories.flatMap(category =>
        tasksAndReminders
            .filter(item => item.category === category)
            .map(item => ({ ...item, category }))
    )

    const kpiCards = [
        {
            key: 'total_students',
            title: 'Total Students',
            icon: TotalStudentsKpiIcon,
            value: kpis?.total_students,
        },
        {
            key: 'week_respondents',
            title: "This Week's Respondents",
            icon: ResponseKpiIcon,
            value: kpis?.week_respondents,
        },
        {
            key: 'week_no_response',
            title: "This Week's No Response",
            icon: NoResponseKpiIcon,
            value: kpis?.week_no_response,
        },
        {
            key: 'pending_submission',
            title: 'Pending Submission',
            icon: PendingSubmissionKpiIcon,
            value: kpis?.pending_submission,
        },
    ]

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

    if (isLoading) return <DashboardSkeleton />

    return (
        <div className="dashboard">
            <Sidebar />

            <main className="dashboard-main">
                <Header
                    title="Dashboard"
                    subtitle="Monitor student attendance."
                    className="dashboard-header"
                />

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
                            <p className="month-attendance">This Month's Attendance <br/> {kpis?.week_respondents}</p>

                            <div className="dashboard-eraser-container">
                                <img
                                    className="dashboard-eraser-icon"
                                    src={EraserIcon}
                                    alt="Eraser Icon"
                                />
                            </div>
                            <p className="month-absences">This Month's No Response <br/> {kpis?.week_no_response}</p>
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


                <section className="dashboard-kpis" aria-label="Attendance key metrics">
                    {kpiCards.map(card => (
                        <article className="kpi-card" key={card.key}>
                            <span className={`kpi-icon kpi-icon-${card.key}`}>
                                <img src={card.icon} alt="" aria-hidden="true" />
                            </span>
                            <div className="kpi-copy">
                                <h4>{card.title}</h4>
                                <strong>{card.value ?? '—'}</strong>
                                <p>Awaiting your review</p>
                            </div>
                        </article>
                    ))}
                </section>

                <div className="dashboard-grid">
                    <div className="dashboard-left-column">
                        <section className="attendance-overview">
                            <div className="overview-heading">
                                <div>
                                    <h2>Attendance Overview</h2>
                                    <div className="overview-legend">
                                        <span><i className="overview-present" />Responses</span>
                                        <span><i className="overview-absent" />No Response</span>
                                    </div>
                                </div>

                                <div className="dashboard-period-dropdown" ref={attendancePeriodDropdownRef}>
                                    <button
                                        type="button"
                                        className="dashboard-period-trigger"
                                        aria-label="Attendance graph date range"
                                        aria-haspopup="listbox"
                                        aria-expanded={isAttendancePeriodOpen}
                                        onClick={() => setIsAttendancePeriodOpen(open => !open)}
                                    >
                                        {attendancePeriod === 'week' ? 'This Week' : 'Last Week'}
                                        <img
                                            className={`dashboard-drop-down${isAttendancePeriodOpen ? ' is-open' : ''}`}
                                            src={DropdownIcon}
                                            alt=""
                                            aria-hidden="true"
                                        />
                                    </button>
                                    {isAttendancePeriodOpen && (
                                        <ul className="dashboard-period-menu" role="listbox" aria-label="Attendance date range">
                                            {[
                                                { value: 'week', label: 'This Week' },
                                                { value: 'last_week', label: 'Last Week' },
                                            ].map(option => (
                                                <button
                                                    type="button"
                                                    role="option"
                                                    aria-selected={attendancePeriod === option.value}
                                                    key={option.value}
                                                    className={`dashboard-period-option${attendancePeriod === option.value ? ' selected' : ''}`}
                                                    onClick={() => {
                                                        setAttendancePeriod(option.value)
                                                        setIsAttendancePeriodOpen(false)
                                                    }}
                                                >
                                                    {option.label}
                                                </button>
                                            ))}
                                        </ul>
                                    )}
                                </div>
                            </div>
                            <div className="overview-chart">
                                {overviewError || overview.length === 0 ? (
                                    <span className="overview-empty">{overviewError ? `${overviewError} Check that the API is running and you are signed in.` : 'No attendance data returned for this period.'}</span>
                                ) : (
                                    <>
                                        <div className="overview-y-axis" aria-hidden="true">
                                            {chartTicks.map(tick => <span key={tick}>{tick}</span>)}
                                        </div>
                                        <div className="overview-plot">
                                            <div className="overview-gridlines" aria-hidden="true">
                                                {chartTicks.map(tick => <span key={tick} />)}
                                            </div>
                                            <div className="overview-bars-row">
                                                {overview.map(group => (
                                                    <div className="overview-group" key={group.category}>
                                                        <div className="overview-bars">
                                                            <span
                                                                className="overview-bar overview-present-bar"
                                                                style={{ height: `${Math.min(100, Math.max(0, (Number(group.present) || 0) / 30 * 100))}%` }}
                                                                title={`Responses: ${group.present}`}
                                                            />
                                                            <span
                                                                className="overview-bar overview-absent-bar"
                                                                style={{ height: `${Math.min(100, Math.max(0, (Number(group.absent) || 0) / 30 * 100))}%` }}
                                                                title={`No Response: ${group.absent}`}
                                                            />
                                                        </div>
                                                        <small>{gatheringLabels[group.category] ?? group.category}</small>
                                                    </div>
                                                ))}
                                            </div>
                                        </div>
                                    </>
                                )}
                            </div>
                        </section>

                        <section className="attendance-records">
                            <div className="records-heading">
                                <h2>Recent Attendance Records</h2>
                                <button type="button" onClick={() => navigate('/attendance')}>View All Records <span>→</span></button>
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
                                                    <button
                                                        type="button"
                                                        className="action-btn"
                                                        onClick={() => navigate('/attendance', { state: { openAttendanceRecord: record } })}
                                                    >
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
                                            <span className="dashboard-reminder-description">{item.description || item.gathering || item.title}</span>
                                            <time>{item.date || ''}</time>
                                            <button
                                                type="button"
                                                aria-label={`View reminder: ${item.title}`}
                                                onClick={() => navigate('/reminders', { state: { highlightReminderId: item.id } })}
                                            >
                                                View <span>›</span>
                                            </button>
                                        </div>
                                    ))}
                                </div>
                            )}
                        </section>
                        <section className="monthly-gathering-card">
                            <div className="monthly-gathering-header">
                                <h3>Monthly Attendance by Gathering</h3>
                                <p>Overall student response across the month of {selectedMonthLabel.split(' ')[0]}.</p>
                            </div>

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
