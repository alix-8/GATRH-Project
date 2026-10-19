import { useEffect, useState } from 'react'
import Header from '../../../components/layout/Header'
import Sidebar from '../../../components/layout/Sidebar'
import { request } from '../../../api/request'
import ActiveUsersIcon from '../../../assets/icons/active-users-icon.svg'
import InactiveClockIcon from '../../../assets/icons/in-progress-programs-icon.svg'
import ProgramsIcon from '../../../assets/icons/active-programs-icon.svg'
import './Students.css'

function Students() {
    const [summary, setSummary] = useState(null)
    const [error, setError] = useState('')

    useEffect(() => {
        let active = true
        request('/admin/students?per_page=1')
            .then(data => {
                if (active) setSummary(data.summary)
            })
            .catch(error => {
                if (active) setError(error.message || 'Unable to load student counts.')
            })
        return () => { active = false }
    }, [])

    const percentage = count => `${summary?.total ? ((count / summary.total) * 100).toFixed(1) : '0.0'}% of total`
    const cards = [
        { label: 'Total Students', value: summary?.total, detail: 'Across all programs', tone: 'blue', icon: ActiveUsersIcon },
        { label: 'Active Students', value: summary?.active, detail: percentage(summary?.active ?? 0), tone: 'green', icon: ActiveUsersIcon },
        { label: 'Inactive Students', value: summary?.inactive, detail: percentage(summary?.inactive ?? 0), tone: 'yellow', icon: InactiveClockIcon },
        { label: 'Programs', value: summary?.programs, detail: 'Assigned Programs', tone: 'purple', icon: ProgramsIcon },
    ]

    return (
        <div className="admin-students">
            <Sidebar />
            <main className="admin-students-main">
                <Header
                    title="Students"
                    subtitle="Manage student records, program assignments, and account status."
                    className="admin-students-header"
                />
                <div className="admin-students-content">
                    {error && <p className="admin-students-error" role="alert">{error}</p>}
                    <section className="admin-student-cards" aria-label="Student overview" aria-busy={!summary && !error}>
                        {cards.map(card => <article className={`admin-student-card ${card.tone}`} key={card.label}>
                            <span className="admin-student-card-icon" aria-hidden="true"><img src={card.icon} alt="" /></span>
                            <div>
                                <h2>{card.label}</h2>
                                <strong>{summary ? card.value : '—'}</strong>
                                <p>{card.detail}</p>
                            </div>
                        </article>)}
                    </section>
                </div>
            </main>
        </div>
    )
}

export default Students
