import Sidebar from '../../../components/layout/Sidebar'
import Header from '../../../components/layout/Header'
import RecordsTable from './table/RecordsTable'
import './Records.css'

export default function ProgramHeadRecords() {
    return (
        <div className="ph-records-layout">
            <Sidebar />
            <main className="ph-records-main">
                <Header
                    title="Records"
                    subtitle="View and manage finalized attendance records."
                    className="ph-records-header"
                />
                <RecordsTable />
            </main>
        </div>
    )
}
