import Sidebar from '../../components/Sidebar/Sidebar'
import './Attendance.css'

function Attendance() {
    return (
        <div className="attendance">
            <Sidebar />
            <main className="attendance-main">
                <header className="attendance-header">
                    <h3>Attendance</h3>
                    <p>Take and submit student attendance.</p>
                </header>
            </main>
        </div>
    )
}

export default Attendance