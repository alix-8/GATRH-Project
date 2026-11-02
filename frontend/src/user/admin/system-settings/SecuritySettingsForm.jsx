import { useEffect, useState } from 'react'
import { request } from '../../../api/request'
import ShowPasswordIcon from '../../../assets/icons/show-password-icon.svg'
import SaveChangesIcon from '../../../assets/icons/save-changes-icon.svg'
import PasswordRequirements from '../../../components/forms/PasswordRequirements'

const emptyPasswords = { current_password: '', password: '', password_confirmation: '' }
const passwordFields = [
    { name: 'current_password', label: 'Current Password', placeholder: 'Enter your current password.', autoComplete: 'current-password' },
    { name: 'password', label: 'New Password', placeholder: 'Enter your new password.', autoComplete: 'new-password' },
    { name: 'password_confirmation', label: 'Confirm Password', placeholder: 'Confirm your new password.', autoComplete: 'new-password' },
]

function SecuritySettingsForm() {
    const [passwords, setPasswords] = useState(emptyPasswords)
    const [visible, setVisible] = useState({})
    const [saving, setSaving] = useState(false)
    const [error, setError] = useState('')
    const [fieldErrors, setFieldErrors] = useState({})
    const [notice, setNotice] = useState('')
    const [showPasswordRequirements, setShowPasswordRequirements] = useState(false)

    useEffect(() => {
        if (!notice) return undefined
        const timeout = window.setTimeout(() => setNotice(''), 3000)
        return () => window.clearTimeout(timeout)
    }, [notice])

    function updatePassword(name, value) {
        setPasswords(current => ({ ...current, [name]: value }))
        if (!value) setVisible(current => ({ ...current, [name]: false }))
        setFieldErrors(current => ({ ...current, [name]: '' }))
        setError('')
        setNotice('')
    }

    async function submitPassword(event) {
        event.preventDefault()
        if (saving) return
        if (passwords.password !== passwords.password_confirmation) {
            setFieldErrors({ password_confirmation: 'Passwords do not match.' })
            setShowPasswordRequirements(true)
            return
        }

        setSaving(true)
        setError('')
        setFieldErrors({})
        setNotice('')
        try {
            const data = await request('/change-password', {
                method: 'POST',
                body: JSON.stringify(passwords),
            })
            setPasswords({ ...emptyPasswords })
            setVisible({})
            setShowPasswordRequirements(false)
            setNotice(data.message || 'Password changed successfully.')
        } catch (requestError) {
            const errors = requestError.details?.errors ?? {}
            setFieldErrors(Object.fromEntries(Object.entries(errors).map(([field, messages]) => [field, messages?.[0] || 'Invalid value.'])))
            if (errors.password || errors.password_confirmation) setShowPasswordRequirements(true)
            if (!Object.keys(errors).length) setError(requestError.message || 'Unable to update password.')
        } finally {
            setSaving(false)
        }
    }

    return <>
        <div className="admin-settings-panel-heading">
            <h2 id="admin-settings-security-title">Security</h2>
            <p>Keep your account safe and secure.</p>
        </div>
        <form className="admin-settings-form admin-settings-security-form" onSubmit={submitPassword}>
            <div className="admin-settings-security-content">
                <h3>Change Password</h3>
                <p>update your password to keep account secure.</p>
                <div className="admin-settings-password-fields">
                    {passwordFields.map(field => <div className="admin-settings-password-field" key={field.name}>
                        <label htmlFor={`admin-settings-${field.name}`}>{field.label}</label>
                        <div className="admin-settings-password-input">
                            <input
                                id={`admin-settings-${field.name}`}
                                type={visible[field.name] ? 'text' : 'password'}
                                value={passwords[field.name]}
                                onChange={event => updatePassword(field.name, event.target.value)}
                                placeholder={field.placeholder}
                                autoComplete={field.autoComplete}
                                aria-invalid={Boolean(fieldErrors[field.name])}
                                aria-describedby={field.name === 'current_password' && fieldErrors[field.name] ? `admin-settings-${field.name}-error` : undefined}
                                required
                                disabled={saving}
                            />
                            {passwords[field.name] && <button
                                type="button"
                                onClick={() => setVisible(current => ({ ...current, [field.name]: !current[field.name] }))}
                                aria-label={`${visible[field.name] ? 'Hide' : 'Show'} ${field.label.toLowerCase()}`}
                                disabled={saving}
                            ><img src={ShowPasswordIcon} alt="" />{!visible[field.name] && <span className="admin-settings-password-slash" aria-hidden="true" />}</button>}
                        </div>
                        {field.name === 'current_password' && fieldErrors[field.name] && <small id={`admin-settings-${field.name}-error`} className="admin-settings-field-error">{fieldErrors[field.name]}</small>}
                    </div>)}
                </div>
                <PasswordRequirements
                    password={passwords.password}
                    currentPassword={passwords.current_password}
                    confirmation={passwords.password_confirmation}
                    visible={showPasswordRequirements}
                />
            </div>
            <div className="admin-settings-form-footer">
                <div className="admin-settings-message" role="status" aria-live="polite">
                    {error && <p className="error">{error}</p>}
                    {notice && <p className="success">{notice}</p>}
                </div>
                <button className="admin-settings-save admin-settings-update-password" type="submit" disabled={saving}>
                    <img src={SaveChangesIcon} alt="" />{saving ? 'Updating…' : 'Update Password'}
                </button>
            </div>
        </form>
    </>
}

export default SecuritySettingsForm
