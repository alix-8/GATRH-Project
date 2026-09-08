import Sidebar from '../../components/Sidebar/Sidebar'
import PageDate from '../../components/PageDate/PageDate'
import './Records.css'

function Records() {
    return (
        <div className="records">
            <Sidebar />
            <main className="records-main">
                <header className="records-header page-header">
                    <h3>Records</h3>
                    <p>Review and manage recorded attendance.</p>
                    <PageDate />
                </header>
            </main>
        </div>
    )
}

export default Records