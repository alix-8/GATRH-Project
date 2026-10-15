import Sidebar from '../../../components/layout/Sidebar'
import Header from '../../../components/layout/Header'
import RecordsTable from './RecordsTable'
import './Records.css'

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
