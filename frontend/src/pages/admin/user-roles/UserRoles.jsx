import Header from '../../../components/layout/Header'
import Sidebar from '../../../components/layout/Sidebar'
import './UserRoles.css'

function UserRoles() {
    return (
        <div className="user-roles">
            <Sidebar />
            <main className="user-roles-main">
                <Header
                    title="User & Roles"
                    subtitle="Manage system users, assign roles, and control access permissions."
                    className="user-roles-header"
                />
            </main>
        </div>
    )
}

export default UserRoles
