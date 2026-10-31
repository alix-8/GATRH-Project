import { useEffect, useState } from 'react'
import { Navigate, useLocation, useNavigate, useSearchParams } from 'react-router-dom'
import { API_BASE_URL, initializeCsrf, xsrfHeaders } from '../../api/request'
import Login, { AdminContactLink } from './Login'
import './PasswordReset.css'

const CODE_LIFETIME_SECONDS = 15 * 60

function MailMark() {
    return <div className="password-mail-mark" aria-hidden="true">✉</div>
}

function PasswordDialog({ children, variant = '', email = '' }) {
    return (
        <>
            <Login initialEmail={email} />
            <div className="password-modal-overlay">
                <section className={`password-modal ${variant}`} role="dialog" aria-modal="true">
                    {children}
                </section>
            </div>
        </>
    )
}

function HelpFooter() {
    const [adminEmail, setAdminEmail] = useState('')

    useEffect(() => {
        const controller = new AbortController()
        fetch(`${API_BASE_URL}/branding`, { signal: controller.signal, cache: 'no-store' })
            .then(response => response.ok ? response.json() : Promise.reject())
            .then(data => {
                if (typeof data.admin_email === 'string') setAdminEmail(data.admin_email)
            })
            .catch(() => {})
        return () => controller.abort()
    }, [])

    return <p className="password-help">Need help? <AdminContactLink adminEmail={adminEmail} /></p>
}

function formatCountdown(seconds) {
    return `${String(Math.floor(seconds / 60)).padStart(2, '0')}:${String(seconds % 60).padStart(2, '0')}`
}

const passwordRules = [
    { label: 'At least 8 characters', test: (value) => value.length >= 8 },
    { label: 'One uppercase and lowercase letter', test: (value) => /[A-Z]/.test(value) && /[a-z]/.test(value) },
    { label: 'One number', test: (value) => /\d/.test(value) },
    { label: 'One special character', test: (value) => /[^A-Za-z0-9]/.test(value) },
]

export function ForgotPassword() {
    const navigate = useNavigate()
    const location = useLocation()
    const [params] = useSearchParams()
    const [email, setEmail] = useState(location.state?.email || params.get('email') || '')
    const [stage, setStage] = useState('email')
    const [emailNotFound, setEmailNotFound] = useState(false)
    const [code, setCode] = useState('')
    const [password, setPassword] = useState('')
    const [confirmation, setConfirmation] = useState('')
    const [showPassword, setShowPassword] = useState(false)
    const [showConfirmation, setShowConfirmation] = useState(false)
    const [expiresIn, setExpiresIn] = useState(CODE_LIFETIME_SECONDS)
    const [loading, setLoading] = useState(false)
    const [error, setError] = useState('')

    const backToLogin = () => navigate('/', { replace: true })

    useEffect(() => {
        if (stage !== 'code' && stage !== 'password') return undefined
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
    }, [stage])

    const sendCode = async (event) => {
        event.preventDefault()
        setError('')
        setLoading(true)
        try {
            await initializeCsrf()
            const response = await fetch(`${API_BASE_URL}/forgot-password`, {
                method: 'POST',
                credentials: 'include',
                headers: { Accept: 'application/json', 'Content-Type': 'application/json', ...xsrfHeaders() },
                body: JSON.stringify({ email }),
            })
            const data = await response.json().catch(() => ({}))
            if (response.status === 404) {
                setEmailNotFound(true)
                return
            }
            if (!response.ok) throw new Error(data.message || Object.values(data.errors || {}).flat()[0] || 'Could not send the reset code. Please try again.')
            setCode('')
            setExpiresIn(CODE_LIFETIME_SECONDS)
            setStage('code')
        } catch (requestError) {
            setError(requestError instanceof TypeError ? 'Unable to connect to the server. Please try again.' : requestError.message)
        } finally {
            setLoading(false)
        }
    }

    const verifyCode = async (event) => {
        event.preventDefault()
        setError('')
        setLoading(true)
        try {
            await initializeCsrf()
            const response = await fetch(`${API_BASE_URL}/verify-reset-code`, {
                method: 'POST',
                credentials: 'include',
                headers: { Accept: 'application/json', 'Content-Type': 'application/json', ...xsrfHeaders() },
                body: JSON.stringify({ email, code }),
            })
            const data = await response.json().catch(() => ({}))
            if (!response.ok) throw new Error(data.message || Object.values(data.errors || {}).flat()[0] || 'That code is incorrect or expired.')
            setStage('password')
        } catch (requestError) {
            setError(requestError instanceof TypeError ? 'Unable to connect to the server. Please try again.' : requestError.message)
        } finally {
            setLoading(false)
        }
    }

    const resetPassword = async (event) => {
        event.preventDefault()
        setError('')
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
            await initializeCsrf()
            const response = await fetch(`${API_BASE_URL}/reset-password`, {
                method: 'POST',
                credentials: 'include',
                headers: { Accept: 'application/json', 'Content-Type': 'application/json', ...xsrfHeaders() },
                body: JSON.stringify({ email, code, password, password_confirmation: confirmation }),
            })
            const data = await response.json().catch(() => ({}))
            if (!response.ok) throw new Error(data.message || Object.values(data.errors || {}).flat()[0] || 'Could not reset your password. Request a new code and try again.')
            setStage('complete')
        } catch (requestError) {
            setError(requestError instanceof TypeError ? 'Unable to connect to the server. Please try again.' : requestError.message)
        } finally {
            setLoading(false)
        }
    }

    if (emailNotFound) {
        return (
            <PasswordDialog variant="password-modal-error" email={email}>
                <div className="password-error-mark" aria-hidden="true">!</div>
                <h2>Email not found</h2>
                <p className="password-success-copy">We couldn’t find an account with that email address. Check the spelling or try another email.</p>
                <button className="password-primary" type="button" onClick={() => { setEmailNotFound(false); setEmail('') }}>Try Another Email</button>
                <button className="password-back password-error-back" type="button" onClick={backToLogin}><span aria-hidden="true">↶</span> Back to Log In</button>
            </PasswordDialog>
        )
    }

    if (stage === 'complete') {
        return (
            <PasswordDialog variant="password-modal-success" email={email}>
                <MailMark />
                <h2>Password Updated</h2>
                <p className="password-success-copy">Your password has been changed successfully. You can now sign in with your new password.</p>
                <button className="password-primary" type="button" onClick={backToLogin}>Continue to Log In</button>
            </PasswordDialog>
        )
    }

    if (stage === 'code') {
        return (
            <PasswordDialog variant="password-modal-success password-modal-code" email={email}>
                <MailMark />
                <h2>Check your Email</h2>
                <p className="password-sent-to">We sent a 6-digit reset code to<br /><strong>{email}</strong></p>
                {expiresIn > 0 ? (
                    <p className="password-countdown">Code expires in <strong>{formatCountdown(expiresIn)}</strong></p>
                ) : (
                    <div className="password-expired-message"><p>This code has expired.</p></div>
                )}
                <form onSubmit={verifyCode}>
                    <label htmlFor="reset-code">Verification Code</label>
                    <div className="password-input-wrap password-code-input"><input id="reset-code" type="text" inputMode="numeric" autoComplete="one-time-code" aria-label="6-digit verification code" placeholder="000000" maxLength={6} pattern="[0-9]{6}" value={code} onChange={(event) => setCode(event.target.value.replace(/\D/g, '').slice(0, 6))} required /></div>
                    {error && <p className="password-error" role="alert">{error}</p>}
                    <button className="password-primary" type="submit" disabled={loading || expiresIn === 0}>{loading ? 'Checking...' : 'Verify Code'}</button>
                </form>
                {expiresIn === 0 && <button className="password-resend" type="button" onClick={() => { setStage('email'); setError('') }}>Request a new code</button>}
                <button className="password-back" type="button" onClick={backToLogin}><span aria-hidden="true">↶</span> Back to Login</button>
            </PasswordDialog>
        )
    }

    if (stage === 'password') {
        return (
            <PasswordDialog variant="password-modal-reset" email={email}>
                <h2>Reset Your Password</h2>
                <p className="password-intro">Enter your new password below.</p>
                <form onSubmit={resetPassword}>
                    <label htmlFor="new-password">New Password</label>
                    <div className="password-input-wrap"><input id="new-password" type={showPassword ? 'text' : 'password'} autoComplete="new-password" value={password} onChange={(event) => setPassword(event.target.value)} required /><button type="button" aria-label={showPassword ? 'Hide password' : 'Show password'} onClick={() => setShowPassword(!showPassword)}>◉</button></div>
                    <ul className="password-rules">{passwordRules.map((rule) => <li className={rule.test(password) ? 'is-met' : ''} key={rule.label}><span aria-hidden="true">✓</span>{rule.label}</li>)}</ul>
                    <label htmlFor="confirm-password">Confirm New Password</label>
                    <div className="password-input-wrap"><input id="confirm-password" type={showConfirmation ? 'text' : 'password'} autoComplete="new-password" value={confirmation} onChange={(event) => setConfirmation(event.target.value)} required /><button type="button" aria-label={showConfirmation ? 'Hide password' : 'Show password'} onClick={() => setShowConfirmation(!showConfirmation)}>◉</button></div>
                    {error && <p className="password-error" role="alert">{error}</p>}
                    {expiresIn === 0 && <p className="password-error" role="alert">This code has expired. Request a new one.</p>}
                    <button className="password-primary" type="submit" disabled={loading || expiresIn === 0}>{loading ? 'Updating...' : 'Continue to Log In'}</button>
                </form>
                {expiresIn === 0 && <button className="password-resend" type="button" onClick={() => { setStage('email'); setPassword(''); setConfirmation('') }}>Request a new code</button>}
                <button className="password-back" type="button" onClick={backToLogin}><span aria-hidden="true">↶</span> Back to Login</button>
                <HelpFooter />
            </PasswordDialog>
        )
    }

    return (
        <PasswordDialog variant="password-modal-forgot" email={email}>
            <h2>Forgot Password?</h2>
            <p className="password-intro">No worries! Enter your email address and we’ll<br className="desktop-break" /> send you a verification code to reset your password.</p>
            <form onSubmit={sendCode}>
                <label htmlFor="reset-email">Email Address</label>
                <div className="password-input-wrap"><span aria-hidden="true">✉</span><input id="reset-email" type="email" autoComplete="email" placeholder="youremail@lvcc.edu.ph" value={email} onChange={(event) => setEmail(event.target.value)} required /></div>
                {error && <p className="password-error" role="alert">{error}</p>}
                <button className="password-primary" type="submit" disabled={loading}>{loading ? 'Sending...' : 'Send Verification Code'}</button>
            </form>
            <button className="password-back" type="button" onClick={backToLogin}><span aria-hidden="true">↶</span> Back to Login</button>
            <HelpFooter />
        </PasswordDialog>
    )
}

export function ResetPassword() {
    const [params] = useSearchParams()
    return <Navigate to="/forgot-password" replace state={{ email: params.get('email') || '' }} />
}
