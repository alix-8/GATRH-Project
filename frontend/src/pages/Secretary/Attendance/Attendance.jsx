import Sidebar from '../../../components/Sidebar/Sidebar'
import PageDate from '../../../components/PageDate/PageDate'
import Notification from '../../../components/Notification/Notification'
import AttendanceTable from './AttendanceComponents/AttendanceTable'
import './Attendance.css'

function Attendance() {
    return (
        <div className="attendance">
            <Sidebar />
            <main className="attendance-main">
                <header className="attendance-header page-header">
                    <div className="header-title">
                        <h3>Attendance</h3>
                        <p>Take and submit student attendance.</p>
                    </div>
                    <div className="header-meta">
                        <PageDate />
                        <Notification />
                    </div>
                </header>
                <AttendanceTable/>
            </main>
        </div>
    )
}

export default Attendance