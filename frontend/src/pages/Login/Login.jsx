import LVCCLogo from '../../assets/lvcc-logo.png'
import Illustration from '../../assets/login-illustration.png'
import './Login.css'

function Login() {
    return (
        <main className="login-page">
            <section className="login-content">
                <img
                    className="lvcc-logo"
                    src={LVCCLogo}
                    alt="LVCC Logo"
                />

                <h4 className="login-title">
                    Gathering Attendance
                    <br />
                    Tracking and Reporting Hub
                </h4>

                <h2 className="welcome-title">
                    Welcome
                    <br />
                    Back!
                </h2>

                <p className="welcome-message">
                    Sign in to monitor and manage student
                    <br />
                    attendance.
                </p>

            </section>

        </main>
    )
}

export default Login