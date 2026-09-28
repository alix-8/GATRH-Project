import { useEffect, useState } from 'react'
import { useLocation, useNavigate } from 'react-router-dom'
import LVCCLogo from '../../assets/lvcc-logo.png'
import Illustration from '../../assets/login-illustration.png'
import ShowPasswordIcon from '../../assets/show-password-icon.png'
import AttendanceTrackingIcon from '../../assets/attendance-tracking-icon.png'
import AttendanceConverterIcon from '../../assets/attendance-converter-icon.png'
import RecordManagementIcon from '../../assets/record-management.png'
import ReportSubmissionIcon from '../../assets/report-submission-icon.png'
import DropDownIcon from '../../assets/drop-down-icon.png'
import './Login.css'


function Login() {
    const navigate = useNavigate()
    const location = useLocation()

    const [activeNav, setActiveNav] = useState('home')
    const [email, setEmail] = useState('')
    const [password, setPassword] = useState('')
    const [remember, setRemember] = useState(false)
    const [showPassword, setShowPassword] = useState(false)
    const [loading, setLoading] = useState(false)
    const [error, setError] = useState('')
    const [errorType, setErrorType] = useState('error')

    useEffect(() => {
        if (location.state?.authMessage) {
            window.alert(location.state.authMessage)
            navigate(location.pathname, { replace: true, state: {} })
        }
    }, [location.pathname, location.state, navigate])

    const handleSubmit = async (event) => {
        event.preventDefault()

        setError('')
        setErrorType('error')
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
                    remember,
                }),
            })

            const data = await response.json().catch(() => ({}))

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

                if (response.status === 503) {
                    throw new Error(data.message || 'The database service is currently unavailable. Please try again later.')
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
            setError(
                error instanceof TypeError
                    ? 'Unable to connect to the server. Please try again later.'
                    : error.message
            )
        } finally {
            setLoading(false)
        }
    }

    const handleForgotPassword = async () => {
        if (!email.trim()) {
            setError('Please enter your email address first.')
            return
        }

        try {
            const response = await fetch('http://127.0.0.1:8000/api/forgot-password', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                },
                body: JSON.stringify({ email }),
            })

            const data = await response.json()

            if (!response.ok) {
                throw new Error(data.message || 'Unable to send password reset link.')
            }

            setError(data.message || 'Password reset link sent to your email.')
            setErrorType('success')
        } catch (error) {
            setError(error.message)
            setErrorType('error')
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
                        {password.length > 0 && (
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
                                {!showPassword && <span className="login-password-slash" />}
                            </button>
                        )}
                    </div>

                    <div className="form-options">
                        <label className="remember-option">
                            <input
                                type="checkbox"
                                name="remember"
                                checked={remember}
                                onChange={(event) => setRemember(event.target.checked)}
                            />
                            <span>Remember Me</span>
                        </label>

                        <button
                            className="forgot-password"
                            type="button"
                            onClick={handleForgotPassword}
                        >
                            Forgot Password?
                        </button>
                    </div>

                    <p
                        className={`login-error ${error ? 'visible' : ''} ${errorType === 'success' ? 'login-success' : ''}`}
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
                <span className="account-help-link">Contact your administrator</span>
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
                            <div className="process-flow">
                                <article className="process-card">
                                    <div className="process-card-row">
                                        <h3 className="process-card-title">Collect</h3>
                                        <span className="process-card-number">01</span>
                                    </div>
                                    <p className="process-card-copy">
                                        Students submit their PM, WS, and TG attendance through the existing Messenger channel.
                                    </p>
                                </article>

                                <article className="process-card">
                                    <div className="process-card-row">
                                        <h3 className="process-card-title">Standardize</h3>
                                        <span className="process-card-number">02</span>
                                    </div>
                                    <p className="process-card-copy">
                                        The Spiritual Secretary prepares the attendance using the required template.
                                    </p>
                                </article>

                                <article className="process-card">
                                    <div className="process-card-row">
                                        <h3 className="process-card-title">Paste & Convert</h3>
                                        <span className="process-card-number">03</span>
                                    </div>
                                    <p className="process-card-copy">
                                        The secretary copies the formatted report and pastes it into the system.
                                    </p>
                                </article>

                                <article className="process-card">
                                    <div className="process-card-row">
                                        <h3 className="process-card-title">Review</h3>
                                        <span className="process-card-number">04</span>
                                    </div>
                                    <p className="process-card-copy">
                                        GATRH organizes the information and identifies incomplete or missing entries.
                                    </p>
                                </article>

                                <article className="process-card">
                                    <div className="process-card-row">
                                        <h3 className="process-card-title">Monitor & Report</h3>
                                        <span className="process-card-number">05</span>
                                    </div>
                                    <p className="process-card-copy">
                                        Attendance data is centralized and used for dashboards, monitoring, and consolidated reports
                                    </p>
                                </article>
                            </div>
                        </section>
                    )}

                    {activeNav === 'faqs' && (
                        <section className="panel-content info-panel faq-panel"
                            aria-labelledby="faq-title"
                        >
                            <div className="faq-list">
                                <details>
                                    <summary>
                                        <span>What if PM, WS, or TG is blank?</span>
                                        <img className="faq-dropdown-icon" src={DropDownIcon} alt="" />
                                    </summary>
                                    <p>The system will identify the record as incomplete and mark it for review.</p>
                                </details>

                                <details>
                                    <summary>
                                        <span>Can I edit the converted attendance?</span>
                                        <img className="faq-dropdown-icon" src={DropDownIcon} alt="" />
                                    </summary>
                                    <p>Yes. Review and correct the information before submitting.</p>
                                </details>

                                <details>
                                    <summary>
                                        <span>What if the converter cannot read my input?</span>
                                        <img className="faq-dropdown-icon" src={DropDownIcon} alt="" />
                                    </summary>
                                    <p>Check if the attendance follows the required standardized format.</p>
                                </details>

                                <details>
                                    <summary>
                                        <span>Do we still use Messenger?</span>
                                        <img className="faq-dropdown-icon" src={DropDownIcon} alt="" />
                                    </summary>
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
