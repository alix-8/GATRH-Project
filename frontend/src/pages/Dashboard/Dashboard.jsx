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
    const attendanceRecords = []
    const navigate = useNavigate()

    useEffect(() => {
        const token = localStorage.getItem('auth_token')

        if (!token) {
            return
        }

        fetch('http://127.0.0.1:8000/api/attendance/overview', {
            headers: {
                Accept: 'application/json',
                Authorization: `Bearer ${token}`,
            },
        })
            .then(response => response.ok ? response.json() : null)
            .then(data => setOverview(data?.data ?? []))
            .catch(() => setOverview([]))
    }, [])

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
                    <h2>Attendance at
                        <br />
                        a glance.
                    </h2>
                    <p>Monitor student attendance, absences, and records in one
                        <br />
                        place.
                    </p>

                    <img
                        className="dashboard-illustration"
                        src={DashboardIllustration}
                        alt="Dashboard Illustration"
                    />
                </div>

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
                        {overview.length === 0 ? (
                            <span className="overview-empty">No attendance data yet</span>
                        ) : overview.map(group => (
                            <div className="overview-group" key={group.gathering_type}>
                                <div className="overview-bars">
                                    <span className="overview-bar overview-present-bar" style={{ height: `${Math.max(group.present, 1) * 12}px` }} />
                                    <span className="overview-bar overview-absent-bar" style={{ height: `${Math.max(group.absent, 1) * 12}px` }} />
                                </div>
                                <small>{group.gathering_type.toUpperCase()}</small>
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

                
            </main>
        </div>
    )
}

export default Dashboard
