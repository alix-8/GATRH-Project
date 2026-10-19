import Header from '../../../components/layout/Header'
import Sidebar from '../../../components/layout/Sidebar'
import './Programs.css'

function Students() {
    return (
        <div className="admin-programs">
            <Sidebar />
            <main className="admin-programs-main">
                <Header
                    title="Programs"
                    subtitle="Manage student records, program assignments, and account status."
                    className="admin-programs-header"
                />
            </main>
        </div>
    )
}

export default Students