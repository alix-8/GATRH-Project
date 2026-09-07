import Sidebar from '../../components/Sidebar/Sidebar'
import './Profile.css'

function Reminders() {
    return (
        <div className="profile">
            <Sidebar />

            <main className="profile-main">
                <header className="profile-header">
                    <h3>Profile</h3>
                    <p>Manage your account information and preferences.</p>
                </header>
            </main>
        </div>
    )
}

export default Reminders