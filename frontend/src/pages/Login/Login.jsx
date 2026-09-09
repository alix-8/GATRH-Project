import { useState } from 'react'
import { useNavigate } from 'react-router-dom'
import LVCCLogo from '../../assets/lvcc-logo.png'
import Illustration from '../../assets/login-illustration.png'
import ShowPasswordIcon from '../../assets/show-password-icon.png'
import AttendanceTrackingIcon from '../../assets/attendance-tracking-icon.png'
import AttendanceConverterIcon from '../../assets/attendance-converter-icon.png'
import RecordManagementIcon from '../../assets/record-management.png'
import ReportSubmissionIcon from '../../assets/report-submission-icon.png'
import './Login.css'


function Login() {
    const navigate = useNavigate()

    const [activeNav, setActiveNav] = useState('home')
    const [email, setEmail] = useState('')
    const [password, setPassword] = useState('')
    const [showPassword, setShowPassword] = useState(false)
    const [loading, setLoading] = useState(false)
    const [error, setError] = useState('')

    const handleSubmit = async (event) => {
        event.preventDefault()

        setError('')
        setLoading(true)

        try {
            const response = await fetch('http://127.0.0.1:8000/api/login', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                },
                body: JSON.stringify({
                    email,
                    password,
                }),
            })

            const data = await response.json()

            if (!response.ok) {
                if (response.status === 401) {
                    throw new Error('Invalid email or password.')
                }

                if (response.status === 422) {
                    throw new Error(
                        data.errors?.email?.[0] ||
                        data.errors?.password?.[0] ||
                        'Please check your email and password.'
                    )
                }

                throw new Error(data.message || 'Something went wrong.')
            }

            // Store the token for authenticated API requests
            localStorage.setItem('auth_token', data.access_token)

            // Store the user information
            localStorage.setItem('user', JSON.stringify(data.user))

            // Redirect to Dashboard
            navigate('/dashboard')

        } catch (error) {
            setError(error.message)
        } finally {
            setLoading(false)
        }
    }

    return (
        <main className="login-page">

            {/* LEFT SIDE */}
            <section className="login-content">
                <img
                    className="login-lvcc-logo"
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


            {/* LOGIN FORM */}
            <section className="login-form">
                <form onSubmit={handleSubmit} autoComplete="on">
                    <div className="form-group">
                        <label htmlFor="email">Email Address</label>
                        <input
                            type="email"
                            id="email"
                            name="email"
                            placeholder="rimer@gmail.com"
                            value={email}
                            onChange={(event) => setEmail(event.target.value)}
                            autoComplete="email"
                            required
                        />
                    </div>

                    <div className="form-group password-group">
                        <label htmlFor="password">Password</label>
                        <input
                            type={showPassword ? 'text' : 'password'}
                            id="password"
                            name="password"
                            placeholder="************************"
                            value={password}
                            onChange={(event) => setPassword(event.target.value)}
                            autoComplete="current-password"
                            required
                        />
                        <button
                            type="button"
                            className="show-password-toggle"
                            onClick={() => setShowPassword(!showPassword)}
                            aria-label={showPassword ? 'Hide password' : 'Show password'}
                        >
                            <img
                                className="show-password-icon"
                                src={ShowPasswordIcon}
                                alt={showPassword ? 'Hide Password Icon' : 'Show Password Icon'}
                            />
                        </button>
                    </div>

                    <div className="form-options">
                        <label className="remember-option">
                            <input type="checkbox" name="remember" />
                            <span>Remember Me</span>
                        </label>

                        <button
                            className="forgot-password"
                            type="button">
                            Forgot Password?
                        </button>
                    </div>

                    <p
                        className={`login-error ${error ? 'visible' : ''}`}
                        role={error ? 'alert' : undefined}
                        aria-live={error ? 'polite' : undefined}
                    >
                        {error || ' '}
                    </p>

                    <div className="form-actions">
                        <button
                            className="button login-button"
                            type="submit"
                            disabled={loading}
                        >
                            {loading ? 'Logging in...' : 'Log In'}
                        </button>
                    </div>
                </form>
            </section>

            {/* ACCOUNT HELP */}
            <p className="account-help">
                <span>Need help accessing your account?</span>
                <a className="account-help-link" href="">
                    Contact your administrator
                </a>
            </p>

            {/* RIGHT SIDE */}
            <section className="login-right">

                <nav className="login-nav">
                    <a
                        className={activeNav === 'home' ? 'active' : ''}
                        href="/"
                        onClick={(event) => {
                            event.preventDefault()
                            setActiveNav('home')
                        }}
                    >
                        Home
                    </a>
                    <a
                        className={activeNav === 'features' ? 'active' : ''}
                        href="/features"
                        onClick={(event) => {
                            event.preventDefault()
                            setActiveNav('features')
                        }}
                    >
                        Features
                    </a>
                    <a
                        className={activeNav === 'how-it-works' ? 'active' : ''}
                        href="/how-it-works"
                        onClick={(event) => {
                            event.preventDefault()
                            setActiveNav('how-it-works')
                        }}
                    >
                        How it works
                    </a>
                    <a
                        className={activeNav === 'faqs' ? 'active' : ''}
                        href="/faqs"
                        onClick={(event) => {
                            event.preventDefault()
                            setActiveNav('faqs')
                        }}
                    >
                        FAQs
                    </a>
                </nav>

                <div className="login-panel-content" 
                    aria-live="polite"
                    >

                    {activeNav === 'home' && (
                        <div 
                            className="panel-content" 
                            key={activeNav}
                        >
                            <img
                                className="login-illustration"
                                src={Illustration}
                                alt="Students attending class"
                            />
                        </div>
                    )}

                    {activeNav === 'features' && (
                        <section
                            className="panel-content features-panel"
                            aria-labelledby="features-title"
                        >
                            <div className="feature-grid">
                                <article className="feature-card">
                                    <div className="feature-icon-wrap">
                                        <img
                                            className="feature-icon"
                                            src={AttendanceTrackingIcon}
                                            alt="Attendance Tracking"
                                        />
                                    </div>

                                    <h3>Attendance Tracking</h3>

                                    <p>
                                        Easily record and monitor student attendance for every gathering.
                                        Track responses and keep attendance information organized in one place.
                                    </p>

                                    <div className="feature-footer">
                                        <span className="feature-index">01</span>
                                    </div>
                                </article>

                                <article className="feature-card feature-card-alt">
                                    <div className="feature-icon-wrap feature-icon-wrap-alt">
                                        <img
                                            className="feature-icon"
                                            src={AttendanceConverterIcon}
                                            alt="Attendance Converter"
                                        />
                                    </div>

                                    <h3>Attendance Converter</h3>

                                    <p>
                                        Easily record and monitor student attendance for every gathering.
                                        Track responses and keep attendance information organized in one place.
                                    </p>

                                    <div className="feature-footer">
                                        <span className="feature-index">02</span>
                                    </div>
                                </article>

                                <article className="feature-card">
                                    <div className="feature-icon-wrap">
                                        <img
                                            className="feature-icon"
                                            src={RecordManagementIcon}
                                            alt="Record Management"
                                        />
                                    </div>

                                    <h3>Record Management</h3>

                                    <p>
                                        Review validated attendance records, organize gathering data,
                                        and prepare reports for submission.
                                    </p>

                                    <div className="feature-footer">
                                        <span className="feature-index">03</span>
                                    </div>
                                </article>

                                <article className="feature-card feature-card-alt">
                                    <div className="feature-icon-wrap feature-icon-wrap-alt">
                                        <img
                                            className="feature-icon"
                                            src={ReportSubmissionIcon}
                                            alt="Report Submission"
                                        />
                                    </div>

                                    <h3>Report Submission</h3>

                                    <p>
                                        Prepare completed attendance reports and submit them to higher
                                        administration for review and recording.
                                    </p>

                                    <div className="feature-footer">
                                        <span className="feature-index">04</span>
                                    </div>
                                </article>
                            </div>
                        </section>
                    )}

                    {activeNav === 'how-it-works' && (
                        <section 
                            className="panel-content info-panel process-panel" 
                            aria-labelledby="how-title"
                            >
                            <h2 id="how-title">
                                How it works
                            </h2>

                            <div className="process-list">
                                <article><span>01</span><div><h3>Collect</h3><p>Collect attendance through Messenger. Students submit their PM, WS, and TG attendance through the existing Messenger channel.</p></div></article>
                                <article><span>02</span><div><h3>Standardize</h3><p>Organize using the standard format. The Spiritual Secretary prepares the attendance using the required template.</p></div></article>
                                <article><span>03</span><div><h3>Paste &amp; Convert</h3><p>Paste the attendance report into GATRH. The secretary copies the formatted report and pastes it into the system.</p></div></article>
                                <article><span>04</span><div><h3>Review</h3><p>Validate the converted records. GATRH organizes the information and identifies incomplete or missing entries.</p></div></article>
                                <article><span>05</span><div><h3>Monitor &amp; Report</h3><p>Save, monitor, and generate reports. Attendance data is centralized and used for dashboards, monitoring, and consolidated reports.</p></div></article>
                            </div>
                        </section>
                    )}

                    {activeNav === 'faqs' && (
                        <section className="panel-content info-panel faq-panel" 
                        aria-labelledby="faq-title"
                        >
                            <h2 id="faq-title">
                                FAQs
                            </h2>

                            <div className="faq-list">
                                <details>
                                    <summary>What if PM, WS, or TG is blank?</summary>
                                    <p>The system will identify the record as incomplete and mark it for review.</p>
                                </details>

                                <details>
                                    <summary>Can I edit the converted attendance?</summary>
                                    <p>Yes. Review and correct the information before submitting.</p>
                                </details>

                                <details>
                                    <summary>What if the converter cannot read my input?</summary>
                                    <p>Check if the attendance follows the required standardized format.</p>
                                </details>

                                <details>
                                    <summary>Do we still use Messenger?</summary>
                                    <p>Yes. Messenger remains the attendance collection channel. GATRH processes and organizes the collected attendance data.</p>
                                </details>
                            </div>
                        </section>
                    )}
                </div>

            </section>

        </main>
    )
}

export default Login