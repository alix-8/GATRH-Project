import Sidebar from '../../components/Sidebar/Sidebar'
import PageDate from '../../components/PageDate/PageDate'
import Notification from '../../components/Notification/Notification'
import RemindersIcon from '../../assets/reminders-icon.png'
import NeedsActionIcon from '../../assets/needs-action-icon.png'
import CompletedIcon from '../../assets/completed-icon.png'
import './Reminders.css'

function Reminders() {
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
            </main >

            <div className="reminders-cards">
                <div className="upcoming-card">
                    <img
                        className="upcoming-icon"
                        src={RemindersIcon}
                        alt="Reminders"

                    />
                    <RemindersIcon />
                    <h4>Upcoming</h4>
                    <p>Scheduled activities</p>
                </div>

                <div className="needs-action-card">
                    <img
                        className="needs-action-icon"
                        src={NeedsActionIcon}
                        alt="Needs Action"
                    />
                    <h4>Needs Action</h4>
                    <p>Task requiring attention</p>
                </div>

                <div className="completed-card">
                    <img
                        className="completed-icon"
                        src={CompletedIcon}
                        alt="Completed"
                    />
                    <h4>Completed</h4>
                    <p>Completed reminders</p>
                </div>
            </div>
        </div>
    )
}

export default Reminders