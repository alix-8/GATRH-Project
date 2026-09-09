import { useEffect, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import Sidebar from '../../components/Sidebar/Sidebar'
import PageDate from '../../components/PageDate/PageDate'
import Notification from '../../components/Notification/Notification'
import './Profile.css'

const API_URL = 'http://127.0.0.1:8000/api'

function getInitials(name = '') {
    return name
        .trim()
        .split(/\s+/)
        .filter(Boolean)
        .slice(0, 2)
        .map(part => part[0].toUpperCase())
        .join('')
}

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

    useEffect(() => {
        const token = localStorage.getItem('auth_token')

        if (!token) {
            navigate('/')
            return
        }

        fetch(`${API_URL}/me`, {
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
            .catch(() => {})
    }, [navigate])

    const handleContactSave = () => {
        const token = localStorage.getItem('auth_token')
        const contactNumber = contactDraft.trim()

        setSavingContact(true)

        fetch(`${API_URL}/profile/contact`, {
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
            .catch(() => {})
            .finally(() => {
                setSavingContact(false)
            })
    }

    return (
        <div className="profile">
            <Sidebar />

            <main className="profile-main">
                <header className="profile-header page-header">
                    <h3>Profile</h3>
                    <p>Manage your account information and preferences.</p>
                    <PageDate />
                    <Notification />
                </header>

                <section className="profile-content">
                    <div className="profile-banner">
                        <div className="profile-avatar" aria-hidden="true">{getInitials(user.name)}</div>
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
                        </section>
                    </div>
                </section>
            </main>
        </div>
    )
}

export default Profile