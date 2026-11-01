import Sidebar from '../../../components/layout/Sidebar'
import Header from '../../../components/layout/Header'
import SubmissionTable from './SubmissionTable'
import './Submissions.css'

export default function ProgramHeadSubmissions() {
    return (
        <div className="attendance ph-submissions-page">
            <Sidebar />
            <main className="attendance-main ph-submissions-main">
                <Header
                    title="Submissions"
                    subtitle="Review and manage submitted attendance records."
                    className="attendance-header ph-submissions-header"
                />
                <SubmissionTable />
            </main>
        </div>
    )
}
