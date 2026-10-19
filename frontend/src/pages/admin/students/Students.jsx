import Header from '../../../components/layout/Header'
import Sidebar from '../../../components/layout/Sidebar'
import './Students.css'

function Students() {
    return (
        <div className="admin-students">
            <Sidebar />
            <main className="admin-students-main">
                <Header
                    title="Students"
                    subtitle="Manage student records, program assignments, and account status."
                    className="admin-students-header"
                />
            </main>
        </div>
    )
}

export default Students