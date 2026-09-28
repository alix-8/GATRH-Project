import Sidebar from '../../../components/Sidebar/Sidebar'
import Header from '../../../components/Header/Header'
import SubmissionTable from './SubmissionComponents/SubmissionTable'
import './ProgramHeadSubmissions.css'

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
