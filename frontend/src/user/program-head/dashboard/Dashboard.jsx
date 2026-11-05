import { useCallback, useEffect, useRef, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import Sidebar from '../../../components/layout/Sidebar'
import Header from '../../../components/layout/Header'
import { request } from '../../../api/request'
import PendingReviewIcon from '../../../assets/icons/PendingReviewIcon.svg'
import ApprovedIcon from '../../../assets/icons/ApprovedIcon.svg'
import EscalationsIcon from '../../../assets/icons/escalations-icon.svg'
import TotalSubmissionsIcon from '../../../assets/icons/TotalSubmissionsIcon.svg'
import SubmittedIcon from '../../../assets/icons/SubmittedIcon.svg'
import UserAvatar from '../../../components/identity/UserAvatar'
import RecordDate from '../../../components/RecordDate'
import useLiveRefresh from '../../../hooks/useLiveRefresh'
import useLoginSkeleton from '../../../hooks/useLoginSkeleton'
import DashboardSkeleton from './DashboardSkeleton'
import ProgramTableSkeleton from '../shared/ProgramTableSkeleton'
import './Dashboard.css'

const metrics = [
    { key: 'pending_review', label: 'Pending Review', detail: 'Awaiting your review', icon: PendingReviewIcon },
    { key: 'approved', label: 'Approved', detail: 'Sucessfully reviewed', icon: ApprovedIcon },
    { key: 'escalation_count', label: 'Escalations', detail: 'Students reminders', icon: EscalationsIcon },
    { key: 'total_submissions', label: 'Total Submissions', detail: 'All submitted records', icon: TotalSubmissionsIcon },
]

const activityIcons = {
    submitted: SubmittedIcon,
    approved: ApprovedIcon,
    withdrawn: PendingReviewIcon,
}

function compactActivityTime(value = '') {
    return value
        .replace(/^(\d+) minutes? ago$/i, '$1m ago')
        .replace(/^(\d+) hours? ago$/i, '$1h ago')
        .replace(/^(\d+) days? ago$/i, '$1d ago')
}

function ProgramHeadDashboard() {
    const showLoginSkeleton = useLoginSkeleton()
    const loginSkeletonStartedAt = useRef(null)
    const [showLoginOverlay, setShowLoginOverlay] = useState(showLoginSkeleton)
    const [isLoginOverlayFading, setIsLoginOverlayFading] = useState(false)
    const [summary, setSummary] = useState({})
    const [pending, setPending] = useState([])
    const [activities, setActivities] = useState([])
    const [loading, setLoading] = useState(true)
    const [error, setError] = useState('')
    const navigate = useNavigate()
    const role = JSON.parse(localStorage.getItem('user') || 'null')?.role
    const submissionPath = role === 'program_secretary' ? '/program-secretary/submission' : '/program-head/submission'
    const visibleMetrics = role === 'program_secretary'
        ? metrics.filter(metric => metric.key !== 'escalation_count')
        : metrics

    useEffect(() => {
        loginSkeletonStartedAt.current = Date.now()
    }, [])

    const loadDashboard = useCallback(async (signal, silent = false) => {
        if (!silent) {
            setLoading(true)
            setError('')
        }
        try {
            const data = await request('/dashboard', { signal })
            setSummary(data.summary ?? {})
            setPending(Array.isArray(data.pending_list) ? data.pending_list : [])
            setActivities(Array.isArray(data.recent_records) ? data.recent_records : [])
            setError('')
        } catch (loadError) {
            if (loadError.name !== 'AbortError') {
                if (!silent) {
                    setPending([])
                    setActivities([])
                }
                setError(loadError.message || 'Unable to load dashboard data.')
            }
        } finally {
            if (!signal?.aborted && !silent) setLoading(false)
        }
    }, [])

    useEffect(() => {
        const controller = new AbortController()
        loadDashboard(controller.signal)
        return () => controller.abort()
    }, [loadDashboard])

    useEffect(() => {
        if (!showLoginOverlay || loading) return undefined

        const minimumVisibleMs = error ? 0 : 700
        const elapsedMs = loginSkeletonStartedAt.current === null ? 0 : Date.now() - loginSkeletonStartedAt.current
        const remainingMs = Math.max(0, minimumVisibleMs - elapsedMs)
        const timer = window.setTimeout(() => setIsLoginOverlayFading(true), remainingMs)
        return () => window.clearTimeout(timer)
    }, [showLoginOverlay, loading, error])

    useEffect(() => {
        if (!isLoginOverlayFading) return undefined

        const timer = window.setTimeout(() => setShowLoginOverlay(false), 250)
        return () => window.clearTimeout(timer)
    }, [isLoginOverlayFading])

    const refreshDashboard = useCallback((signal) => loadDashboard(signal, true), [loadDashboard])
    useLiveRefresh(refreshDashboard)

    const openReview = () => navigate(submissionPath)

    return (
        <div className="ph-dashboard">
            <Sidebar />
            <main className="ph-dashboard-main">
                <Header title="Dashboard" subtitle="Review attendance submissions for your program." className="ph-dashboard-header" />
                <div className="ph-dashboard-content">
                    <section className={`ph-metrics${role === 'program_secretary' ? ' ph-metrics-program-secretary' : ''}`} aria-label="Submission summary">
                        {visibleMetrics.map((metric) => (
                            <article className="ph-metric-card" key={metric.key}>
                                <img className={`ph-metric-icon${metric.key === 'escalation_count' ? ' ph-metric-escalation-icon' : ''}`} src={metric.icon} alt="" aria-hidden="true" />
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
                            <table className="ph-submission-table" aria-busy={loading}>
                                <thead><tr><th>Secretary</th><th>Gathering</th><th>Program</th><th>Year</th><th>Date</th><th>Status</th><th>Action</th></tr></thead>
                                <tbody>
                                    {loading ? <ProgramTableSkeleton count={3} /> : pending.map((submission) => (
                                        <tr key={submission.id}>
                                            <td><UserAvatar className="ph-secretary-avatar" name={submission.secretary} program={submission.program} />{submission.secretary}</td>
                                            <td>{submission.gathering}</td><td>{submission.program}</td><td>{submission.year_level}{/^\d+$/.test(String(submission.year_level)) ? ' Year' : ''}</td><td><RecordDate value={submission.date_iso || submission.date} /></td>
                                            <td><span className="ph-status"><i />{submission.status_label || 'Pending'}</span></td>
                                            <td><button className="ph-review-button" type="button" onClick={() => navigate(submissionPath, { state: { openSubmission: { ...submission, status: 'pending', year_label: submission.year_label || `${submission.year_level}${/^\d+$/.test(String(submission.year_level)) ? ' Year' : ''}` } } })}>View</button></td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                            {!loading && pending.length === 0 && <div className="ph-pending-empty">No pending submissions yet.</div>}
                        </div>
                    </section>

                    <section className="ph-panel ph-activity-panel" aria-labelledby="ph-activity-title">
                        <div className="ph-panel-heading"><div><h2 id="ph-activity-title">Recent Activities</h2><p>Latest updates from attendance submissions and reviews.</p></div></div>
                        <div className="ph-activity-scroll">
                            {loading ? <div className="ph-activity-skeleton" role="status" aria-label="Loading recent activities" aria-busy="true">{[0, 1, 2, 3].map(index => <div className="ph-activity-skeleton-row" key={index}><i /><span><b /><b /></span><em /></div>)}</div> : activities.length === 0 ? <p className="ph-empty">Recent submission activity will appear here.</p> : (
                                <ul className="ph-activity-list">
                                    {activities.map((activity, index) => <li key={activity.id ?? index}>
                                        <img className="ph-activity-icon" src={activityIcons[activity.icon_type] || SubmittedIcon} alt="" />
                                        <div className="ph-activity-copy"><strong>{activity.title}</strong><p>{activity.description}</p></div>
                                        <time>{compactActivityTime(activity.time_ago)}</time>
                                    </li>)}
                                </ul>
                            )}
                        </div>
                    </section>
                </div>
            </main>
            {showLoginOverlay && (
                <div className={`ph-login-skeleton-overlay${isLoginOverlayFading ? ' is-fading' : ''}`}>
                    <DashboardSkeleton programSecretary={role === 'program_secretary'} />
                </div>
            )}
        </div>
    )
}

export default ProgramHeadDashboard
