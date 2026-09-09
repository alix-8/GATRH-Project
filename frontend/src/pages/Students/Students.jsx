import Sidebar from '../../components/Sidebar/Sidebar'
import PageDate from '../../components/PageDate/PageDate'
import Notification from '../../components/Notification/Notification'
import './Students.css'

function Students() {
    return (
        <div className="students">
            <Sidebar />

            <main className="students-main">
                <header className="students-header page-header">
                    <h3>Students</h3>
                    <p>Manage student information and attendance history.</p>
                    <PageDate />
                    <Notification />
                </header>
            </main>
        </div>
    )
}

export default Students
