import Sidebar from '../../components/Sidebar/Sidebar'
import './Reminders.css'

function Reminders() {
    return (
        <div className="reminders">
            <Sidebar />

            <main className="reminders-main">
                <header className="reminders-header">
                    <h3>Reminders</h3>
                    <p>Track upcoming gatherings and tasks that need attention.</p>
                </header>
            </main>
        </div>
    )
}

export default Reminders