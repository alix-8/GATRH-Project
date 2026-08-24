import LVCCLogo from '../../assets/lvcc-logo.png'
import './Login.css'

function Login() {
    return (
        <main className="login-page">
            <img
                className="lvcc-logo"
                src={LVCCLogo}
                alt="LVCC Logo"
            />

            <h4 className="page-title">
                Gathering Attendance
                <br />
                Tracking and Reporting Hub
            </h4>
        </main>
    )
}

export default Login