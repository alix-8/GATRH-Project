import { useCallback, useEffect, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import Sidebar from '../../../components/Sidebar/Sidebar'
import Header from '../../../components/Header/Header'
import { request } from '../../../api/request'
import PendingReviewIcon from '../../../components/Icons/PendingReviewIcon.svg'
import ApprovedIcon from '../../../components/Icons/ApprovedIcon.svg'
import RejectedIcon from '../../../components/Icons/RejectedIcon.svg'
import TotalSubmissionsIcon from '../../../components/Icons/TotalSubmissionsIcon.svg'
import SubmittedIcon from '../../../components/Icons/SubmittedIcon.svg'
import ResubmittedIcon from '../../../components/Icons/ResubmittedIcon.svg'
import LoadingCircle from '../../../components/Loading/LoadingCircle'
import './ProgramHeadDashboard.css'

const metrics = [
    { key: 'pending_review', label: 'Pending Review', detail: 'Awaiting your review', icon: PendingReviewIcon },
    { key: 'approved', label: 'Approved', detail: 'Successfully reviewed', icon: ApprovedIcon },
    { key: 'rejected', label: 'Rejected', detail: 'Returned for correction', icon: RejectedIcon },
    { key: 'total_submissions', label: 'Total Submissions', detail: 'All submitted records', icon: TotalSubmissionsIcon },
]

const activityIcons = {
    submitted: SubmittedIcon,
    approved: ApprovedIcon,
    rejected: RejectedIcon,
    resubmitted: ResubmittedIcon,
}

function compactActivityTime(value = '') {
    return value
        .replace(/^(\d+) minutes? ago$/i, '$1m ago')
        .replace(/^(\d+) hours? ago$/i, '$1h ago')
        .replace(/^(\d+) days? ago$/i, '$1d ago')
}

function ProgramHeadDashboard() {
    const [summary, setSummary] = useState({})
    const [pending, setPending] = useState([])
    const [activities, setActivities] = useState([])
    const [loading, setLoading] = useState(true)
    const [error, setError] = useState('')
    const navigate = useNavigate()

    const loadDashboard = useCallback(async () => {
        setLoading(true)
        setError('')
        try {
            const data = await request('/dashboard')
            setSummary(data.summary ?? {})
            setPending(Array.isArray(data.pending_list) ? data.pending_list : [])
            setActivities(Array.isArray(data.recent_records) ? data.recent_records : [])
        } catch (loadError) {
            setPending([])
            setActivities([])
            setError(loadError.message || 'Unable to load dashboard data.')
        } finally {
            setLoading(false)
        }
    }, [])

    useEffect(() => { loadDashboard() }, [loadDashboard])

    const openReview = () => navigate('/program-head/submission')

    return (
        <div className="ph-dashboard">
            <Sidebar />
            <main className="ph-dashboard-main">
                <Header title="Dashboard" subtitle="Review attendance submissions for your program." className="ph-dashboard-header" />
                <div className="ph-dashboard-content">
                    <section className="ph-metrics" aria-label="Submission summary">
                        {metrics.map((metric) => (
                            <article className="ph-metric-card" key={metric.key}>
                                <img className="ph-metric-icon" src={metric.icon} alt="" aria-hidden="true" />
                                <div className="ph-metric-copy">
                                    <strong>{loading ? '—' : (summary[metric.key] ?? 0)}</strong>
                                    <span>{metric.label}</span>
                                    <small>{metric.detail}</small>
                                </div>
                            </article>
                        ))}
                    </section>

                    {error && <div className="ph-dashboard-error" role="alert">{error}<button type="button" onClick={loadDashboard}>Try again</button></div>}

                    <section className="ph-panel ph-pending-panel" aria-labelledby="ph-pending-title">
                        <div className="ph-panel-heading">
                            <div><h2 id="ph-pending-title">Pending Submissions</h2><p>Attendance records waiting for your review.</p></div>
                            <button type="button" className="ph-view-all" onClick={openReview}>View all submissions <span aria-hidden="true">→</span></button>
                        </div>
                        <div className="ph-table-scroll">
                            <table className="ph-submission-table">
                                <thead><tr><th>Secretary</th><th>Gathering</th><th>Program</th><th>Year</th><th>Date</th><th>Status</th><th>Action</th></tr></thead>
                                <tbody>
                                    {loading ? <tr><td colSpan="7" className="ph-empty"><LoadingCircle label="Loading submissions…" /></td></tr> : pending.length === 0 ? <tr><td colSpan="7" className="ph-empty">You’re all caught up. No pending submissions.</td></tr> : pending.map((submission) => (
                                        <tr key={submission.id}>
                                            <td><span className="ph-secretary-avatar" aria-hidden="true">{(submission.secretary || '?').charAt(0).toUpperCase()}</span>{submission.secretary}</td>
                                            <td>{submission.gathering}</td><td>{submission.program}</td><td>{submission.year_level}{/^\d+$/.test(String(submission.year_level)) ? ' Year' : ''}</td><td>{submission.date}</td>
                                            <td><span className="ph-status"><i />{submission.status_label || 'Pending'}</span></td>
                                            <td><button className="ph-review-button" type="button" onClick={openReview}>Review</button></td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </section>

                    <section className="ph-panel ph-activity-panel" aria-labelledby="ph-activity-title">
                        <div className="ph-panel-heading"><div><h2 id="ph-activity-title">Recent Activities</h2><p>Latest updates from attendance submissions and reviews.</p></div></div>
                        {loading ? <p className="ph-empty"><LoadingCircle label="Loading recent activity…" /></p> : activities.length === 0 ? <p className="ph-empty">Recent submission activity will appear here.</p> : (
                            <ul className="ph-activity-list">
                                {activities.map((activity, index) => <li key={activity.id ?? index}>
                                    <img className="ph-activity-icon" src={activityIcons[activity.icon_type] || SubmittedIcon} alt="" />
                                    <div className="ph-activity-copy"><strong>{activity.title}</strong><p>{activity.description}</p></div>
                                    <time>{compactActivityTime(activity.time_ago)}</time>
                                </li>)}
                            </ul>
                        )}
                    </section>
                </div>
            </main>
        </div>
    )
}

export default ProgramHeadDashboard
