import Sidebar from '../../components/Sidebar/Sidebar'

function Attendance() {
    return (
        <div className="dashboard">
            <Sidebar />
            <main className="dashboard-main">
                <h1>Attendance</h1>
                <p>Take and submit student attendance</p>
            </main>
        </div>
    )
}

export default Attendance