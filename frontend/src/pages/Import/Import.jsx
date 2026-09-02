import Sidebar from '../../components/Sidebar/Sidebar'
import './Import.css'

function Import() {
    return (
        <div className="import">
            <Sidebar />
            <main className="import-main">
                <header className="import-header">
                    <h3>Import</h3>
                    <p>Paste raw attendance logs to get a formatted spreadsheet.</p>
                </header>
            </main>
        </div>
    )
}

export default Import
