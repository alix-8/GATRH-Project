import Sidebar from '../../components/Sidebar/Sidebar'

function Import() {
    return (
        <div className="dashboard">
            <Sidebar />
            <main className="dashboard-main">
                <header className="dashboard-header">
                    <h3>Import</h3>
                    <p>Upload and process attendance files.</p>
                </header>
            </main>
        </div>
    )
}

export default Import
