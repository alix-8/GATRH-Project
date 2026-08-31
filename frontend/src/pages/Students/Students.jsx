import Sidebar from '../../components/Sidebar/Sidebar'

function Students() {
    return (
        <div className="dashboard">
            <Sidebar />
            <main className="dashboard-main">
                <h1>Students</h1>
                <p>Manage student records and attendance.</p>
            </main>
        </div>
    )
}

export default Students
