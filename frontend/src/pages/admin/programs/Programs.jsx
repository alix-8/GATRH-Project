import { useEffect, useState } from 'react'
import Header from '../../../components/layout/Header'
import Sidebar from '../../../components/layout/Sidebar'
import { request } from '../../../api/request'
import TotalProgramsIcon from '../../../assets/icons/active-programs-icon.svg'
import ProgramHeadsIcon from '../../../assets/icons/active-users-icon.svg'
import ActiveProgramIcon from '../../../assets/icons/active-program-icon.svg'
import InactiveProgramIcon from '../../../assets/icons/inactive-program-icon.svg'
import './Programs.css'

function Programs() {
    const [summary, setSummary] = useState(null)
    const [error, setError] = useState('')

    useEffect(() => {
        let active = true

        request('/admin/programs')
            .then(data => {
                if (active) setSummary(data.summary)
            })
            .catch(error => {
                if (active) setError(error.message || 'Unable to load program counts.')
            })

        return () => { active = false }
    }, [])

    const cards = [
        { label: 'Total Programs', value: summary?.total, detail: 'Across all programs', tone: 'purple', icon: TotalProgramsIcon },
        { label: 'Program Heads', value: summary?.program_heads, detail: 'Assigned Program Heads', tone: 'blue', icon: ProgramHeadsIcon },
        { label: 'Active Programs', value: summary?.active, detail: summary && summary.total === summary.active ? 'All programs are active' : 'Currently active programs', tone: 'green', icon: ActiveProgramIcon },
        { label: 'Inactive Programs', value: summary?.inactive, detail: summary && summary.inactive === 0 ? 'No inactive programs' : 'Currently inactive programs', tone: 'yellow', icon: InactiveProgramIcon },
    ]

    return (
        <div className="admin-programs">
            <Sidebar />
            <main className="admin-programs-main">
                <Header
                    title="Programs"
                    subtitle="Manage student records, program assignments, and account status."
                    showNotification={false}
                    className="admin-programs-header"
                />
                <div className="admin-programs-content">
                    {error && <p className="admin-programs-error" role="alert">{error}</p>}
                    <section className="admin-programs-cards" aria-label="Program overview" aria-busy={!summary && !error}>
                        {cards.map(card => (
                            <article className={`admin-programs-card ${card.tone}`} key={card.label}>
                                <span className="admin-programs-card-icon" aria-hidden="true"><img src={card.icon} alt="" /></span>
                                <div>
                                    <h2>{card.label}</h2>
                                    <strong>{summary ? card.value : '—'}</strong>
                                    <p>{card.detail}</p>
                                </div>
                            </article>
                        ))}
                    </section>
                </div>
            </main>
        </div>
    )
}

export default Programs
