import { useEffect, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { API_BASE_URL } from '../../api/request'
import Sidebar from '../../components/layout/Sidebar'
import Header from '../../components/layout/Header'
import UserAvatar from '../../components/identity/UserAvatar'
import ShowPasswordIcon from '../../assets/icons/show-password-icon.svg'
import './Profile.css'

function formatRole(role = '') {
    return role.replaceAll('_', ' ').replace(/\b\w/g, letter => letter.toUpperCase())
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
    const [isEditingContact, setIsEditingContact] = useState(false)
    const [contactDraft, setContactDraft] = useState(user.contact_number || '')
    const [savingContact, setSavingContact] = useState(false)
    const [contactMessage, setContactMessage] = useState('')

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

    useEffect(() => {
        const token = localStorage.getItem('auth_token')

        if (!token) {
            navigate('/')
            return
        }

        fetch(`${API_BASE_URL}/me`, {
            headers: {
                Accept: 'application/json',
                Authorization: `Bearer ${token}`,
            },
        })
            .then(response => response.ok ? response.json() : null)
            .then(data => {
                if (data?.user) {
                    setUser(data.user)
                    setContactDraft(data.user.contact_number || '')
                    localStorage.setItem('user', JSON.stringify(data.user))
                }
            })
            .catch(() => { })
    }, [navigate])

    const handleContactSave = () => {
        const token = localStorage.getItem('auth_token')
        const contactNumber = contactDraft.trim()

        setSavingContact(true)

        fetch(`${API_BASE_URL}/profile/contact`, {
            method: 'PATCH',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                Authorization: `Bearer ${token}`,
            },
            body: JSON.stringify({ contact_number: contactNumber }),
        })
            .then(response => response.ok ? response.json() : null)
            .then(data => {
                if (data?.message) {
                    setContactMessage(data.message)

                    window.setTimeout(() => {
                        setContactMessage('')
                    }, 2000)
                }

                if (data?.user) {
                    setUser(data.user)
                    setContactDraft(data.user.contact_number || '')
                    localStorage.setItem('user', JSON.stringify(data.user))
                }

                setIsEditingContact(false)
            })
            .catch(() => { })
            .finally(() => {
                setSavingContact(false)
            })
    }

    const handlePasswordSubmit = () => {
        const token = localStorage.getItem('auth_token')

        fetch(`${API_BASE_URL}/change-password`, {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                Authorization: `Bearer ${token}`,
            },
            body: JSON.stringify(passwordForm),
        })
            .then(async response => {
                const data = await response.json().catch(() => null)

                if (!response.ok) {
                    setPasswordMessageType('error')
                    setPasswordMessage(data?.message || 'Unable to change password.')
                    return
                }

                setPasswordMessageType('success')
                setPasswordMessage(data?.message || 'Password changed successfully.')
                setPasswordForm({
                    current_password: '',
                    password: '',
                    password_confirmation: '',
                })

                window.setTimeout(() => {
                    setPasswordMessage('')
                }, 2000)
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
                            <p>{formatRole(user.role)}</p>
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
                                    <span className="profile-field-title">Contact Number</span>
                                    <div className="profile-contact-row">
                                        <input
                                            type="text"
                                            value={contactDraft}
                                            readOnly={!isEditingContact}
                                            maxLength={11}
                                            inputMode="numeric"
                                            onChange={event => {
                                                const onlyDigits = event.target.value.replace(/\D/g, '')
                                                setContactDraft(onlyDigits)
                                            }}
                                            className={isEditingContact ? 'profile-contact-edit-input' : ''}
                                        />
                                        <button
                                            type="button"
                                            className="profile-contact-action"
                                            disabled={savingContact}
                                            onClick={() => {
                                                if (isEditingContact) {
                                                    handleContactSave()
                                                    return
                                                }

                                                setContactDraft(user.contact_number || '')
                                                setIsEditingContact(true)
                                            }}
                                        >
                                            {savingContact ? 'Saving...' : isEditingContact ? 'Save' : 'Edit'}
                                        </button>
                                    </div>
                                    {contactMessage && <small className="profile-contact-message">{contactMessage}</small>}
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
                                                onChange={event => setPasswordForm({ ...passwordForm, current_password: event.target.value })}
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
                                                onChange={event => setPasswordForm({ ...passwordForm, password: event.target.value })}
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
                                        {passwordErrors.password && <small className="profile-password-error">{passwordErrors.password}</small>}
                                    </label>

                                    <label className="password-field-label">
                                        <span>Confirm Password</span>
                                        <span className="profile-password-input-wrap">
                                            <input
                                                type={showPasswords.confirm ? 'text' : 'password'}
                                                value={passwordForm.password_confirmation}
                                                onChange={event => setPasswordForm({ ...passwordForm, password_confirmation: event.target.value })}
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
                                        {passwordErrors.password_confirmation && <small className="profile-password-error">{passwordErrors.password_confirmation}</small>}
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
