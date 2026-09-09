import Sidebar from '../../components/Sidebar/Sidebar'
import PageDate from '../../components/PageDate/PageDate'
import Notification from '../../components/Notification/Notification'
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
            </main>
        </div>
    )
}

export default Reminders