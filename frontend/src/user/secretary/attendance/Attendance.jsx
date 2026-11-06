import Sidebar from '../../../components/layout/Sidebar'
import Header from '../../../components/layout/Header'
import AttendanceTable from './table/AttendanceTable'
import './Attendance.css'

function Attendance() {
    return (
        <div className="attendance">
            <Sidebar />
            <main className="attendance-main">
                <Header
                    title="Attendance"
                    subtitle="Take and submit student attendance."
                    className="attendance-header"
                />
                <AttendanceTable/>
            </main>
        </div>
    )
}

export default Attendance
