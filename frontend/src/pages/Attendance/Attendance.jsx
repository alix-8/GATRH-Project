import Sidebar from '../../components/Sidebar/Sidebar'

function Attendance() {
    return (
        <div className="dashboard">
            <Sidebar />
            <main className="dashboard-main">
                <header className="dashboard-header">
                    <h3>Attendance</h3>
                    <p>Take and submit student attendance</p>
                </header>
            </main>
        </div>
    )
}

export default Attendance