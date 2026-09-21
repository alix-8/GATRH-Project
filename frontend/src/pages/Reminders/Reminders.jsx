import { useEffect, useState } from 'react'
import Sidebar from '../../components/Sidebar/Sidebar'
import PageDate from '../../components/PageDate/PageDate'
import Notification from '../../components/Notification/Notification'
import RemindersIcon from '../../assets/reminders-icon.png'
import NeedsActionIcon from '../../assets/needs-action-icon.png'
import CompletedIcon from '../../assets/completed-icon.png'
import './Reminders.css'

function Reminders() {
    const [summary, setSummary] = useState({
        upcoming: 0,
        needs_action: 0,
        completed: 0,
    })

    useEffect(() => {
        const token = localStorage.getItem('auth_token')

        if (!token) {
            return
        }

        fetch('http://127.0.0.1:8000/api/reminders', {
            headers: {
                Accept: 'application/json',
                Authorization: `Bearer ${token}`,
            },
        })
            .then(response => response.ok ? response.json() : null)
            .then(data => {
                setSummary({
                    upcoming: data?.summary?.upcoming ?? 0,
                    needs_action: data?.summary?.needs_action ?? 0,
                    completed: data?.summary?.completed ?? 0,
                })
            })
            .catch(() => {
                setSummary({
                    upcoming: 0,
                    needs_action: 0,
                    completed: 0,
                })
            })
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
            </main>
        </div>
    )
}

export default Reminders