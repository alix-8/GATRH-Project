import Sidebar from '../../components/Sidebar/Sidebar'
import PageDate from '../../components/PageDate/PageDate'
import './Import.css'

function Import() {
    return (
        <div className="import">
            <Sidebar />
            <main className="import-main">
                <header className="import-header page-header">
                    <h3>Import</h3>
                    <p>Paste raw attendance logs to get a formatted spreadsheet.</p>
                    <PageDate />
                </header>
            </main>
        </div>
    )
}

export default Import
