import Sidebar from '../../components/Sidebar/Sidebar'

function Import() {
    return (
        <div className="dashboard">
            <Sidebar />
            <main className="dashboard-main">
                <h1>Import</h1>
                <p>Upload and process attendance files.</p>
            </main>
        </div>
    )
}

export default Import
