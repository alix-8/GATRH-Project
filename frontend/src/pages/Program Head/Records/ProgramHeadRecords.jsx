import Sidebar from '../../../components/Sidebar/Sidebar'
import Header from '../../../components/Header/Header'
import RecordsTable from './RecordsComponents/RecordsTable'
import './ProgramHeadRecords.css'

export default function ProgramHeadRecords() {
    return (
        <div className="attendance ph-records-layout">
            <Sidebar />
            <main className="attendance-main ph-records-main">
                <Header
                    title="Records"
                    subtitle="View and manage finalized attendance records."
                    className="attendance-header ph-records-header"
                />
                <RecordsTable />
            </main>
        </div>
    )
}
