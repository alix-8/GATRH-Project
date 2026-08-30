import { useNavigate } from "react-router-dom"
// import './Dashboard.css'


function Dashboard() {
    const navigate = useNavigate()

    // Get logged-in user information
    const user = JSON.parse(localStorage.getItem('user'))

    const handleLogout = () => {
        // Remove authentication information 
        localStorage.removeItem('auth_token')
        localStorage.removeItem('user')

        // Return to login page
        navigate('/')
    }

    return (
        <main> 
            <h1>Dashboard</h1> 
            <h2> Welcome, {user?.name || 'User'}! </h2> 
            <button onClick={handleLogout}> 
                Log Out 
            </button> 
        </main>)
}

export default Dashboard