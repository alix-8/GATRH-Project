import Sidebar from '../../components/Sidebar/Sidebar'
import PageDate from '../../components/PageDate/PageDate'
import Notification from '../../components/Notification/Notification'
import './Attendance.css'

function Attendance() {
    return (
        <div className="attendance">
            <Sidebar />
            <main className="attendance-main">
                <header className="attendance-header page-header">
                    <h3>Attendance</h3>
                    <p>Take and submit student attendance.</p>
                    <PageDate />
                    <Notification />
                </header>
            </main>
        </div>
    )
}

export default Attendance