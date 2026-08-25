import LVCCLogo from '../../assets/lvcc-logo.png'
// import Illustration from '../../assets/login-illustration.png'
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

            <section className="login-form">
                <form>
                    <div className="form-group">
                        <label htmlFor="email">Email Address</label>
                        <input
                            type="text"
                            id="email"
                            name="email"
                            placeholder="rimer@gmail.com"
                            required
                        />
                    </div>

                    <div className="form-group">
                        <label htmlFor="password">Password</label>
                        <input
                            type="password"
                            id="password"
                            name="password"
                            placeholder="************************"
                        />
                    </div>

                    <div className="form-options">
                        <label className="remember-option">
                            <input type="checkbox" name="remember" />
                            <span>Remember Me</span>
                        </label>
                        <button className="forgot-password" type="button">
                            Forgot Password?
                        </button>
                    </div>

                    <div className="form-actions">
                        <button className="button login-button" type="submit">
                            Log In
                        </button>
                        <button className="button learn-button" type="button">
                            Learn more
                        </button>
                    </div>
                </form>

            </section>

            <p className="account-help">Need help accessing your account? Contact your administrator</p>

        </main>
    )
}

export default Login