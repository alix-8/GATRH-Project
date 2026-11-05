import { useEffect, useState } from 'react'
import { useLocation, useNavigate } from 'react-router-dom'
import { API_BASE_URL } from '../../api/request'
import LVCCLogo from '../../assets/icons/lvcc-logo.svg'
import { initializeCsrf, submitWithCsrf, xsrfHeaders } from '../../api/request'
import { loadBranding } from '../../api/branding'
import ShowPasswordIcon from '../../assets/icons/show-password-icon.svg'
import AttendanceTrackingIcon from '../../assets/icons/attendance-tracking-icon.svg'
import AttendanceConverterIcon from '../../assets/icons/attendance-converter-icon.svg'
import RecordManagementIcon from '../../assets/icons/record-management.svg'
import ReportSubmissionIcon from '../../assets/icons/report-submission-icon.svg'
import DropDownIcon from '../../assets/icons/drop-down-icon.svg'
import HomeAttendanceIcon from '../../assets/icons/home-attendance-icon.svg'
import HomeReportsIcon from '../../assets/icons/home-reports-icon.svg'
import HomeFollowUpsIcon from '../../assets/icons/home-follow-ups-icon.svg'
import './Login.css'
import { prepareLoginSkeleton } from '../../utils/loginSkeletonState'

const DEFAULT_SYSTEM_NAME = 'Gathering Attendance Tracking and Reporting Hub'
const DEFAULT_ADMIN_EMAIL = 'admin@laverdad.edu.ph'

export function AdminContactLink({ adminEmail }) {
    if (!adminEmail) return <span>Contact your administrator</span>

    const subject = 'GATRH Account Access Help'
    const gmailComposeUrl = `https://mail.google.com/mail/?view=cm&fs=1&to=${encodeURIComponent(adminEmail)}&su=${encodeURIComponent(subject)}`

    return (
        <a
            className="admin-contact-link"
            href={gmailComposeUrl}
            target="_blank"
            rel="noopener noreferrer"
            aria-label={`Contact your administrator at ${adminEmail}`}
        >
            <span className="admin-contact-label">Contact your administrator</span>
            <span className="admin-contact-email">{adminEmail}</span>
        </a>
    )
}

function Login({ initialEmail = '' }) {
    const navigate = useNavigate()
    const location = useLocation()

    const [systemName, setSystemName] = useState(DEFAULT_SYSTEM_NAME)
    const [adminEmail, setAdminEmail] = useState(DEFAULT_ADMIN_EMAIL)
    useEffect(() => {
        let cancelled = false
        initializeCsrf().catch(() => {})
        loadBranding()
            .then(data => {
                if (cancelled) return
                if (data.system_name) setSystemName(data.system_name)
                if (typeof data.admin_email === 'string' && data.admin_email.trim()) {
                    setAdminEmail(data.admin_email.trim())
                }
            })
            .catch(() => {})
        return () => { cancelled = true }
    }, [])

    const [activeNav, setActiveNav] = useState('home')
    const [email, setEmail] = useState(() => initialEmail || location.state?.email || '')
    const [password, setPassword] = useState('')
    const [remember, setRemember] = useState(false)
    const [showPassword, setShowPassword] = useState(false)
    const [loading, setLoading] = useState(false)
    const [retryAfter, setRetryAfter] = useState(0)
    const [error, setError] = useState('')
    const [errorType, setErrorType] = useState('error')

    useEffect(() => {
        if (retryAfter <= 0) return

        const timer = window.setTimeout(() => {
            setRetryAfter((seconds) => Math.max(0, seconds - 1))
        }, 1000)

        return () => window.clearTimeout(timer)
    }, [retryAfter])

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
            const response = await submitWithCsrf(() => fetch(`${API_BASE_URL}/login`, {
                method: 'POST',
                credentials: 'include',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    ...xsrfHeaders(),
                },
                body: JSON.stringify({
                    email,
                    password,
                    remember,
                }),
            }))

            const data = await response.json().catch(() => ({}))

            if (!response.ok) {
                if (response.status === 429) {
                    setRetryAfter(Math.max(0, Number(data.retry_after) || 60))
                    throw new Error(data.message || 'Too many failed login attempts. Please try again later.')
                }

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

            // Store the user information
            localStorage.setItem('user', JSON.stringify(data.user))
            prepareLoginSkeleton(data.user.role)

            // Send each role directly to a page it can access.
            const landingByRole = {
                secretary: '/dashboard',
                program_secretary: '/program-secretary/dashboard',
                program_head: '/program-head/dashboard',
                cm_coordinator: '/coordinator/monitoring',
                chancellor: '/chancellor/dashboard',
                admin: '/admin/dashboard',
            }
            navigate(landingByRole[data.user.role] || '/unauthorized', { replace: true })

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

    const handleForgotPassword = () => {
        navigate('/forgot-password', { state: { email } })
    }

    return (
        <main className="login-page">
            <div className="login-left">

                {/* LEFT SIDE */}
                <section className="login-content">
                    <div className="login-brand">
                        <img
                            className="login-lvcc-logo"
                            src={LVCCLogo}
                            alt="LVCC Logo"
                        />

                        <p className="login-title">
                            {systemName === DEFAULT_SYSTEM_NAME ? (
                                <>
                                    <span>Gathering Attendance</span>
                                    <span>Tracking and Reporting Hub</span>
                                </>
                            ) : systemName}
                        </p>
                    </div>
                </section>

                <div className="login-main">
                    <div className="login-intro">
                        <h1 className="welcome-title">
                            Welcome
                            <br />
                            Back!
                        </h1>

                        <p className="welcome-message">
                            Sign in to monitor and manage student attendance.
                        </p>
                    </div>

                    {/* LOGIN FORM */}
                    <section className="login-form">
                        <form onSubmit={handleSubmit} autoComplete="on">
                            <div className="form-group">
                                <label htmlFor="email">Email Address</label>
                                <input
                                    type="email"
                                    id="email"
                                    name="email"
                                    placeholder="example@laverdad.edu.ph"
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
                                    placeholder="****************"
                                    value={password}
                                    onChange={(event) => { setPassword(event.target.value); if (!event.target.value) setShowPassword(false) }}
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
                                            alt=""
                                        />
                                        {showPassword && <span className="login-password-slash" />}
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
                                    type={retryAfter > 0 ? 'button' : 'submit'}
                                    onClick={retryAfter > 0 ? () => navigate(-1) : undefined}
                                    disabled={loading}
                                >
                                    {loading ? 'Logging in...' : retryAfter > 0 ? 'Go Back' : 'Log In'}
                                </button>
                            </div>
                        </form>
                    </section>
                </div>

                {/* ACCOUNT HELP */}
                <p className="account-help">
                    <span>Need help accessing your account?</span>
                    <AdminContactLink adminEmail={adminEmail} />
                </p>
            </div>

            {/* RIGHT SIDE */}
            <section className="login-right">

                <nav className="login-nav" aria-label="Landing page sections">
                    <button type="button"
                        className={activeNav === 'home' ? 'active' : ''}
                        aria-pressed={activeNav === 'home'}
                        onClick={() => setActiveNav('home')}
                    >
                        Home
                    </button>
                    <button type="button"
                        className={activeNav === 'features' ? 'active' : ''}
                        aria-pressed={activeNav === 'features'}
                        onClick={() => setActiveNav('features')}
                    >
                        Features
                    </button>
                    <button type="button"
                        className={activeNav === 'how-it-works' ? 'active' : ''}
                        aria-pressed={activeNav === 'how-it-works'}
                        onClick={() => setActiveNav('how-it-works')}
                    >
                        How it works
                    </button>
                    <button type="button"
                        className={activeNav === 'faqs' ? 'active' : ''}
                        aria-pressed={activeNav === 'faqs'}
                        onClick={() => setActiveNav('faqs')}
                    >
                        FAQs
                    </button>
                </nav>

                <div className="login-panel-content"
                    aria-live="polite"
                >
                    {activeNav === 'home' && (
                        <section className="panel-content home-panel" aria-labelledby="home-title">
                            <div className="home-introduction">
                                <h2 id="home-title" className="home-headline">
                                    <span>Track Attendance.</span>
                                    <span>Stay Informed.</span>
                                </h2>
                                <p className="home-description">
                                    Monitor student participation, organize
                                    <br/>
                                    spiritual reports, and provide timely,
                                    <br/>
                                    compassionate&nbsp;support—all in one place.
                                </p>
                                <button className="home-how-link" type="button" onClick={() => setActiveNav('how-it-works')}>
                                    See how GATRH works <span aria-hidden="true">→</span>
                                </button>
                            </div>
                            <div className="home-feature-divider" aria-hidden="true" />
                            <ul className="home-feature-list" aria-label="GATRH highlights">
                                <li><img src={HomeAttendanceIcon} alt="" /><span>Attendance</span></li>
                                <li><img src={HomeReportsIcon} alt="" /><span>Reports</span></li>
                                <li><img src={HomeFollowUpsIcon} alt="" /><span>Follow-ups</span></li>
                            </ul>
                        </section>
                    )}

                    {activeNav === 'features' && (
                        <section
                            className="panel-content features-panel"
                            aria-labelledby="features-title"
                        >
                            <h2 id="features-title" className="login-visually-hidden">Features</h2>
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
                                        Copy generated attendance text formats and paste it to convert into tables. 
                                        Easily edit records in organized tables.
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
                            <h2 id="how-title" className="login-visually-hidden">How it works</h2>
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
                            <h2 id="faq-title" className="login-visually-hidden">FAQs</h2>
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
