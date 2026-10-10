import { useEffect, useState } from 'react'
import { useLocation, useNavigate, useSearchParams } from 'react-router-dom'
import { API_BASE_URL } from '../../api/request'
import Login from './Login'
import './PasswordReset.css'

function MailMark() {
    return <div className="password-mail-mark" aria-hidden="true">✉</div>
}

function PasswordDialog({ children, variant = '' }) {
    return (
        <>
            <Login />
            <div className="password-modal-overlay">
                <section className={`password-modal ${variant}`} role="dialog" aria-modal="true">
                    {children}
                </section>
            </div>
        </>
    )
}

function HelpFooter() {
    return <p className="password-help">Need help? <span>Contact your administrator</span></p>
}

export function ForgotPassword() {
    const navigate = useNavigate()
    const location = useLocation()
    const [email, setEmail] = useState(location.state?.email || '')
    const [sent, setSent] = useState(false)
    const [emailNotFound, setEmailNotFound] = useState(false)
    const [expiresIn, setExpiresIn] = useState(15 * 60)
    const [loading, setLoading] = useState(false)
    const [error, setError] = useState('')

    const backToLogin = () => navigate('/', { replace: true })

    useEffect(() => {
        if (!sent) return undefined
        const timer = window.setInterval(() => {
            setExpiresIn((remaining) => {
                if (remaining <= 1) {
                    window.clearInterval(timer)
                    return 0
                }
                return remaining - 1
            })
        }, 1000)
        return () => window.clearInterval(timer)
    }, [sent])

    const formattedCountdown = `${String(Math.floor(expiresIn / 60)).padStart(2, '0')}:${String(expiresIn % 60).padStart(2, '0')}`

    const sendLink = async (event) => {
        event.preventDefault()
        setError('')
        setLoading(true)
        try {
            const response = await fetch(`${API_BASE_URL}/forgot-password`, {
                method: 'POST',
                headers: { Accept: 'application/json', 'Content-Type': 'application/json' },
                body: JSON.stringify({ email }),
            })
            const data = await response.json().catch(() => ({}))
            if (response.status === 404) {
                setEmailNotFound(true)
                return
            }
            if (!response.ok) throw new Error(data.message || Object.values(data.errors || {}).flat()[0] || 'Could not send the reset link. Please try again.')
            setSent(true)
        } catch (requestError) {
            setError(requestError instanceof TypeError ? 'Unable to connect to the server. Please try again.' : requestError.message)
        } finally {
            setLoading(false)
        }
    }

    if (sent) {
        return (
            <PasswordDialog variant="password-modal-success">
                <MailMark />
                <h2>Check your Email</h2>
                <p className="password-sent-to">We sent a verification link to<br /><strong>{email}</strong></p>
                <p className="password-success-copy">Click the link in your email to continue resetting your password.</p>
                {expiresIn > 0 ? (
                    <p className="password-countdown">Link expires in <strong>{formattedCountdown}</strong></p>
                ) : (
                    <div className="password-expired-message">
                        <p>This reset link has expired.</p>
                        <button type="button" onClick={() => { setExpiresIn(15 * 60); setSent(false) }}>Request a new link</button>
                    </div>
                )}
                <div className="password-tip"><span aria-hidden="true">●</span><div><strong>Didn’t receive the email?</strong><ul><li>Check your spam or junk folder.</li><li>Make sure you entered the correct email address.</li></ul></div></div>
                <button className="password-primary" type="button" onClick={backToLogin}>Back to Login</button>
            </PasswordDialog>
        )
    }

    if (emailNotFound) {
        return (
            <PasswordDialog variant="password-modal-error">
                <div className="password-error-mark" aria-hidden="true">!</div>
                <h2>Email not found</h2>
                <p className="password-success-copy">We couldn’t find an account with that email address. Check the spelling or try another email.</p>
                <button className="password-primary" type="button" onClick={() => { setEmailNotFound(false); setEmail('') }}>Try Another Email</button>
                <button className="password-back password-error-back" type="button" onClick={backToLogin}><span aria-hidden="true">↶</span> Back to Log In</button>
            </PasswordDialog>
        )
    }

    return (
        <PasswordDialog variant="password-modal-forgot">
            <h2>Forgot Password?</h2>
            <p className="password-intro">No worries! Enter your email address and we’ll<br className="desktop-break" /> send you a verification link to reset your password.</p>
            <form onSubmit={sendLink}>
                <label htmlFor="reset-email">Email Address</label>
                <div className="password-input-wrap"><span aria-hidden="true">✉</span><input id="reset-email" type="email" autoComplete="email" placeholder="youremail@lvcc.edu.ph" value={email} onChange={(event) => setEmail(event.target.value)} required /></div>
                {error && <p className="password-error" role="alert">{error}</p>}
                <button className="password-primary" type="submit" disabled={loading}>{loading ? 'Sending...' : 'Send Verification Link'}</button>
            </form>
            <button className="password-back" type="button" onClick={backToLogin}><span aria-hidden="true">↶</span> Back to Login</button>
            <HelpFooter />
        </PasswordDialog>
    )
}

const passwordRules = [
    { label: 'At least 8 characters', test: (value) => value.length >= 8 },
    { label: 'One uppercase and lowercase letter', test: (value) => /[A-Z]/.test(value) && /[a-z]/.test(value) },
    { label: 'One number', test: (value) => /\d/.test(value) },
    { label: 'One special character', test: (value) => /[^A-Za-z0-9]/.test(value) },
]

export function ResetPassword() {
    const navigate = useNavigate()
    const [params] = useSearchParams()
    const token = params.get('token') || ''
    const email = params.get('email') || ''
    const [password, setPassword] = useState('')
    const [confirmation, setConfirmation] = useState('')
    const [showPassword, setShowPassword] = useState(false)
    const [showConfirmation, setShowConfirmation] = useState(false)
    const [loading, setLoading] = useState(false)
    const [error, setError] = useState('')
    const [complete, setComplete] = useState(false)

    const backToLogin = () => navigate('/', { replace: true })
    const requestAnotherLink = () => navigate('/forgot-password', { replace: true, state: { email } })

    const submitReset = async (event) => {
        event.preventDefault()
        setError('')
        if (!token || !email) {
            setError('This password reset link is incomplete or expired. Request a new link to continue.')
            return
        }
        const failedRule = passwordRules.find((rule) => !rule.test(password))
        if (failedRule) {
            setError('Please meet all password requirements.')
            return
        }
        if (password !== confirmation) {
            setError('The passwords do not match.')
            return
        }

        setLoading(true)
        try {
            const response = await fetch(`${API_BASE_URL}/reset-password`, {
                method: 'POST',
                headers: { Accept: 'application/json', 'Content-Type': 'application/json' },
                body: JSON.stringify({ token, email, password, password_confirmation: confirmation }),
            })
            const data = await response.json().catch(() => ({}))
            if (!response.ok) throw new Error(data.message || Object.values(data.errors || {}).flat()[0] || 'Could not reset your password. The link may have expired.')
            setComplete(true)
        } catch (requestError) {
            setError(requestError instanceof TypeError ? 'Unable to connect to the server. Please try again.' : requestError.message)
        } finally {
            setLoading(false)
        }
    }

    if (complete) {
        return (
            <PasswordDialog variant="password-modal-success">
                <MailMark />
                <h2>Password Updated</h2>
                <p className="password-success-copy">Your password has been changed successfully. You can now sign in with your new password.</p>
                <button className="password-primary" type="button" onClick={backToLogin}>Continue to Log In</button>
            </PasswordDialog>
        )
    }

    return (
        <PasswordDialog variant="password-modal-reset">
            <h2>Reset Your Password</h2>
            <p className="password-intro">Enter your new password below.</p>
            {!token || !email ? (
                <>
                    <p className="password-error" role="alert">This password reset link is incomplete or expired.</p>
                    <button className="password-primary" type="button" onClick={requestAnotherLink}>Request a New Link</button>
                </>
            ) : (
                <form onSubmit={submitReset}>
                    <label htmlFor="new-password">New Password</label>
                    <div className="password-input-wrap"><input id="new-password" type={showPassword ? 'text' : 'password'} autoComplete="new-password" value={password} onChange={(event) => setPassword(event.target.value)} required /><button type="button" aria-label={showPassword ? 'Hide password' : 'Show password'} onClick={() => setShowPassword(!showPassword)}>◉</button></div>
                    <ul className="password-rules">{passwordRules.map((rule) => <li className={rule.test(password) ? 'is-met' : ''} key={rule.label}><span aria-hidden="true">✓</span>{rule.label}</li>)}</ul>
                    <label htmlFor="confirm-password">Confirm New Password</label>
                    <div className="password-input-wrap"><input id="confirm-password" type={showConfirmation ? 'text' : 'password'} autoComplete="new-password" value={confirmation} onChange={(event) => setConfirmation(event.target.value)} required /><button type="button" aria-label={showConfirmation ? 'Hide password' : 'Show password'} onClick={() => setShowConfirmation(!showConfirmation)}>◉</button></div>
                    {error && <p className="password-error" role="alert">{error}</p>}
                    <button className="password-primary" type="submit" disabled={loading}>{loading ? 'Updating...' : 'Continue to Log In'}</button>
                </form>
            )}
            <button className="password-back" type="button" onClick={backToLogin}><span aria-hidden="true">↶</span> Back to Login</button>
            <HelpFooter />
        </PasswordDialog>
    )
}
