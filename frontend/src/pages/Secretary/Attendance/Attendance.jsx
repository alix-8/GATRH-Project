import Sidebar from '../../../components/Sidebar/Sidebar'
import Header from '../../../components/Header/Header'
import AttendanceTable from './AttendanceComponents/AttendanceTable'
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