import { useEffect, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { API_BASE_URL } from '../../api/request'
import Sidebar from '../../components/layout/Sidebar'
import Header from '../../components/layout/Header'
import UserAvatar from '../../components/identity/UserAvatar'
import PasswordRequirements from '../../components/forms/PasswordRequirements'
import ShowPasswordIcon from '../../assets/icons/show-password-icon.svg'
import './Profile.css'

function formatRole(role = '') {
    return role.replaceAll('_', ' ').replace(/\b\w/g, letter => letter.toUpperCase())
}

function formatProfileRole(user) {
    const role = formatRole(user.role)
    const details = []
    if (user.program) details.push(String(user.program).toUpperCase())

    if (user.year_level) {
        const yearLevel = Number(user.year_level)
        const suffix = yearLevel % 100 >= 11 && yearLevel % 100 <= 13
            ? 'th'
            : ({ 1: 'st', 2: 'nd', 3: 'rd' }[yearLevel % 10] || 'th')
        const year = Number.isFinite(yearLevel) ? `${yearLevel}${suffix} Year` : `${user.year_level} Year`
        details.push(year)
    }

    return [...details, role].join(' · ')
}

function Profile() {
    const navigate = useNavigate()
    const [user, setUser] = useState(() => {
        try {
            return JSON.parse(localStorage.getItem('user')) || {}
        } catch {
            return {}
        }
    })
    const [passwordForm, setPasswordForm] = useState({
        current_password: '',
        password: '',
        password_confirmation: '',
    })
    const [showPasswords, setShowPasswords] = useState({
        current: false,
        new: false,
        confirm: false,
    })
    const [passwordMessage, setPasswordMessage] = useState('')
    const [passwordMessageType, setPasswordMessageType] = useState('success')
    const [passwordErrors, setPasswordErrors] = useState({
        current_password: '',
        password: '',
        password_confirmation: '',
    })
    const [showPasswordRequirements, setShowPasswordRequirements] = useState(false)

    function updatePasswordField(name, value) {
        setPasswordForm(current => ({ ...current, [name]: value }))
        setPasswordErrors(current => ({ ...current, [name]: '' }))
        setPasswordMessage('')
    }

    useEffect(() => {
        let passwordFlashMessage = ''
        try {
            passwordFlashMessage = sessionStorage.getItem('profile-password-success') || ''
            sessionStorage.removeItem('profile-password-success')
        } catch {
            // Continue without a post-refresh message if session storage is unavailable.
        }

        if (passwordFlashMessage) {
            setPasswordMessageType('success')
            setPasswordMessage(passwordFlashMessage)
            window.setTimeout(() => setPasswordMessage(''), 2000)
        }

        if (!localStorage.getItem('user')) {
            navigate('/')
            return
        }

        // Keep only the small identity fields needed for client-side routing.
        // Older builds may have cached the full /me response here.
        try {
            const cachedUser = JSON.parse(localStorage.getItem('user') || 'null')
            if (cachedUser) {
                localStorage.setItem('user', JSON.stringify({
                    id: cachedUser.id,
                    name: cachedUser.name,
                    role: cachedUser.role,
                }))
            }
        } catch {
            localStorage.removeItem('user')
            navigate('/', { replace: true })
            return
        }

        fetch(`${API_BASE_URL}/me`, {
            credentials: 'include',
            headers: {
                Accept: 'application/json',
            },
        })
            .then(response => response.ok ? response.json() : null)
            .then(data => {
                if (data?.user) {
                    setUser(data.user)
                }
            })
            .catch(() => { })
    }, [navigate])

    const handlePasswordSubmit = () => {
        fetch(`${API_BASE_URL}/change-password`, {
            method: 'POST',
            credentials: 'include',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-XSRF-TOKEN': decodeURIComponent(document.cookie.split('; ').find(value => value.startsWith('XSRF-TOKEN='))?.slice('XSRF-TOKEN='.length) || ''),
            },
            body: JSON.stringify(passwordForm),
        })
            .then(async response => {
                const data = await response.json().catch(() => null)

                if (!response.ok) {
                    const errors = data?.errors ?? {}
                    const fieldErrors = Object.fromEntries(Object.entries(errors).map(([field, messages]) => [
                        field,
                        messages?.[0] || 'Invalid value.',
                    ]))
                    setPasswordErrors(fieldErrors)
                    if (Object.keys(errors).length) {
                        setPasswordMessage('')
                    }
                    if (errors.password || errors.password_confirmation) {
                        setShowPasswordRequirements(true)
                        return
                    }
                    if (Object.keys(errors).length) return
                    setPasswordMessageType('error')
                    setPasswordMessage(data?.message || 'Unable to change password.')
                    return
                }

                try {
                    sessionStorage.setItem('profile-password-success', data?.message || 'Password changed successfully.')
                } catch {
                    setPasswordMessageType('success')
                    setPasswordMessage(data?.message || 'Password changed successfully.')
                    window.setTimeout(() => setPasswordMessage(''), 2000)
                    return
                }

                setShowPasswordRequirements(false)
                window.location.reload()
            })
            .catch(() => {
                setPasswordMessageType('error')
                setPasswordMessage('Unable to change password.')
            })
    }

    return (
        <div className="profile">
            <Sidebar />

            <main className="profile-main">
                <Header
                    title="Profile"
                    subtitle="Manage your account information and preferences."
                    className="profile-header"
                />

                <section className="profile-content">
                    <div className="profile-banner">
                        <UserAvatar as="div" className="profile-avatar" name={user.name} program={user.program} />
                        <div className="profile-identity">
                            <h1>{user.name || 'User'}</h1>
                            <p>{formatProfileRole(user)}</p>
                            <span>{user.email || ''}</span>
                        </div>
                    </div>

                    <div className="profile-details-layer">
                        <section className="profile-details" aria-labelledby="personal-information-title">
                            <h2 id="personal-information-title">PERSONAL INFORMATION</h2>
                            <div className="profile-fields">
                                <label>
                                    Full Name
                                    <input value={user.name || ''} readOnly />
                                </label>
                                <label>
                                    Email
                                    <input type="email" value={user.email || ''} readOnly />
                                </label>
                                <label>
                                    Position
                                    <input value={formatRole(user.role)} readOnly />
                                </label>
                            </div>

                            <section className="profile-change-password" aria-labelledby="change-password-title">
                                <h2 id="change-password-title">CHANGE PASSWORD</h2>
                                <div className="profile-password-fields">
                                    <label className="password-field-label">
                                        <span>Current Password</span>
                                        <span className="profile-password-input-wrap">
                                            <input
                                                type={showPasswords.current ? 'text' : 'password'}
                                                value={passwordForm.current_password}
                                                onChange={event => updatePasswordField('current_password', event.target.value)}
                                                placeholder=""
                                            />
                                            {passwordForm.current_password && (
                                                <button
                                                    type="button"
                                                    className="profile-password-toggle"
                                                    onClick={() => setShowPasswords({ ...showPasswords, current: !showPasswords.current })}
                                                    aria-label={showPasswords.current ? 'Hide current password' : 'Show current password'}
                                                >
                                                    <img className="profile-show-password-icon" src={ShowPasswordIcon} alt="" />
                                                    {!showPasswords.current && <span className="profile-password-slash" />}
                                                </button>
                                            )}
                                        </span>
                                        {passwordErrors.current_password && <small className="profile-password-error">{passwordErrors.current_password}</small>}
                                    </label>

                                    <label className="password-field-label">
                                        <span>New Password</span>
                                        <span className="profile-password-input-wrap">
                                            <input
                                                type={showPasswords.new ? 'text' : 'password'}
                                                value={passwordForm.password}
                                                onChange={event => updatePasswordField('password', event.target.value)}
                                                placeholder=""
                                            />
                                            {passwordForm.password && (
                                                <button
                                                    type="button"
                                                    className="profile-password-toggle"
                                                    onClick={() => setShowPasswords({ ...showPasswords, new: !showPasswords.new })}
                                                    aria-label={showPasswords.new ? 'Hide new password' : 'Show new password'}
                                                >
                                                    <img className="profile-show-password-icon" src={ShowPasswordIcon} alt="" />
                                                    {!showPasswords.new && <span className="profile-password-slash" />}
                                                </button>
                                            )}
                                        </span>
                                        <PasswordRequirements
                                            password={passwordForm.password}
                                            currentPassword={passwordForm.current_password}
                                            confirmation={passwordForm.password_confirmation}
                                            visible={showPasswordRequirements}
                                        />
                                    </label>

                                    <label className="password-field-label">
                                        <span>Confirm Password</span>
                                        <span className="profile-password-input-wrap">
                                            <input
                                                type={showPasswords.confirm ? 'text' : 'password'}
                                                value={passwordForm.password_confirmation}
                                                onChange={event => updatePasswordField('password_confirmation', event.target.value)}
                                                placeholder=""
                                            />
                                            {passwordForm.password_confirmation && (
                                                <button
                                                    type="button"
                                                    className="profile-password-toggle"
                                                    onClick={() => setShowPasswords({ ...showPasswords, confirm: !showPasswords.confirm })}
                                                    aria-label={showPasswords.confirm ? 'Hide confirm password' : 'Show confirm password'}
                                                >
                                                    <img className="profile-show-password-icon" src={ShowPasswordIcon} alt="" />
                                                    {!showPasswords.confirm && <span className="profile-password-slash" />}
                                                </button>
                                            )}
                                        </span>
                                    </label>
                                </div>

                                {passwordMessage && (
                                    <small className={passwordMessageType === 'error' ? 'profile-password-error' : 'profile-password-message'}>
                                        {passwordMessage}
                                    </small>
                                )}

                                <div className="profile-password-action-row">
                                    <button
                                        type="button"
                                        className="profile-password-save"
                                        onClick={handlePasswordSubmit}
                                    >
                                        Save Changes
                                    </button>
                                </div>
                            </section>
                        </section>
                    </div>
                </section>
            </main>
        </div>
    )
}

export default Profile
