import { useEffect, useState } from 'react'
import Header from '../../../components/layout/Header'
import Sidebar from '../../../components/layout/Sidebar'
import LoadingCircle from '../../../components/feedback/LoadingCircle'
import ModalSelect from '../dashboard/ModalSelect'
import { request } from '../../../api/request'
import GeneralIcon from '../../../assets/icons/system-settings-icon.svg'
import SecurityIcon from '../../../assets/icons/system-status-icon.svg'
import SaveChangesIcon from '../../../assets/icons/save-changes-icon.svg'
import SecuritySettingsForm from './SecuritySettingsForm'
import './SystemSettings.css'

const dateFormats = ['MM/DD/YYYY', 'DD/MM/YYYY', 'YYYY-MM-DD']

function SystemSettings() {
    const [activeSection, setActiveSection] = useState('general')
    const [settings, setSettings] = useState(null)
    const [form, setForm] = useState(null)
    const [loading, setLoading] = useState(true)
    const [saving, setSaving] = useState(false)
    const [error, setError] = useState('')
    const [notice, setNotice] = useState('')
    const [loadAttempt, setLoadAttempt] = useState(0)

    useEffect(() => {
        let active = true
        request('/admin/settings/general')
            .then(data => {
                if (!active) return
                setSettings(data.settings)
                setForm({
                    institution_name: data.settings.institution_name ?? '',
                    academic_year: data.settings.academic_year ?? '',
                    date_format: data.settings.date_format ?? 'MM/DD/YYYY',
                })
                setError('')
            })
            .catch(error => { if (active) setError(error.message || 'Unable to load system settings.') })
            .finally(() => { if (active) setLoading(false) })
        return () => { active = false }
    }, [loadAttempt])

    useEffect(() => {
        if (!notice) return undefined
        const timeout = window.setTimeout(() => setNotice(''), 3000)
        return () => window.clearTimeout(timeout)
    }, [notice])

    const currentYear = new Date().getFullYear()
    const academicYears = Array.from({ length: 5 }, (_, index) => {
        const year = currentYear + index
        return `${year}-${year + 1}`
    })
    if (form?.academic_year && Number(form.academic_year.slice(0, 4)) >= currentYear && !academicYears.includes(form.academic_year)) {
        academicYears.push(form.academic_year)
        academicYears.sort()
    }

    function updateField(name, value) {
        setForm(current => ({ ...current, [name]: value }))
        setNotice('')
        setError('')
    }

    async function saveSettings(event) {
        event.preventDefault()
        if (!form || saving) return
        setSaving(true)
        setError('')
        setNotice('')
        try {
            const data = await request('/admin/settings/general', {
                method: 'PATCH',
                body: JSON.stringify({
                    institution_name: form.institution_name.trim(),
                    academic_year: form.academic_year,
                    date_format: form.date_format,
                }),
            })
            setSettings(data.settings)
            setForm({
                institution_name: data.settings.institution_name,
                academic_year: data.settings.academic_year,
                date_format: data.settings.date_format,
            })
            setNotice('General settings saved successfully.')
        } catch (error) {
            setError(error.message || 'Unable to save general settings.')
        } finally {
            setSaving(false)
        }
    }

    const unchanged = settings && form && form.institution_name === settings.institution_name && form.academic_year === settings.academic_year && form.date_format === settings.date_format

    return (
        <div className="admin-system-settings">
            <Sidebar />
            <main className="admin-system-settings-main">
                <Header title="System Settings" subtitle="Set up your system settings and grab a backup." showNotification={false} className="admin-system-settings-header" />
                <div className="admin-system-settings-content">
                    <nav className="admin-settings-nav" aria-label="Settings sections">
                        <button type="button" className={`admin-settings-nav-item${activeSection === 'general' ? ' active' : ''}`} aria-current={activeSection === 'general' ? 'page' : undefined} onClick={() => setActiveSection('general')}><img src={GeneralIcon} alt="" />General</button>
                        <button type="button" className={`admin-settings-nav-item${activeSection === 'security' ? ' active' : ''}`} aria-current={activeSection === 'security' ? 'page' : undefined} onClick={() => setActiveSection('security')}><img src={SecurityIcon} alt="" />Security</button>
                    </nav>
                    <section className="admin-settings-panel" aria-labelledby={activeSection === 'general' ? 'admin-settings-general-title' : 'admin-settings-security-title'}>
                        {activeSection === 'general' ? <>
                            <div className="admin-settings-panel-heading">
                            <h2 id="admin-settings-general-title">General</h2>
                            <p>Update your institution details and default settings.</p>
                            </div>
                        {loading ? <div className="admin-settings-loading"><LoadingCircle label="Loading general settings..." /></div>
                            : !form ? <div className="admin-settings-load-error" role="alert"><p>{error || 'Unable to load system settings.'}</p><button type="button" onClick={() => { setLoading(true); setError(''); setLoadAttempt(current => current + 1) }}>Try again</button></div>
                                : <form className="admin-settings-form" onSubmit={saveSettings}>
                                    <div className="admin-settings-fields">
                                        <label className="admin-settings-field"><span>Institution Name</span><input type="text" name="institution_name" value={form.institution_name} onChange={event => updateField('institution_name', event.target.value)} maxLength={255} required disabled={saving} /></label>
                                        <div className="admin-settings-field-row">
                                            <label className="admin-settings-field"><span>Academic Year</span><ModalSelect name="academic_year" value={form.academic_year} onChange={event => updateField('academic_year', event.target.value)} disabled={saving} required>{academicYears.map(year => <option key={year} value={year}>{year}</option>)}</ModalSelect></label>
                                            <label className="admin-settings-field"><span>Date Format</span><ModalSelect name="date_format" value={form.date_format} onChange={event => updateField('date_format', event.target.value)} disabled={saving} required>{dateFormats.map(format => <option key={format} value={format}>{format}</option>)}</ModalSelect></label>
                                        </div>
                                    </div>
                                    <div className="admin-settings-form-footer">
                                        <div className="admin-settings-message" role="status" aria-live="polite">{error && <p className="error">{error}</p>}{notice && <p className="success">{notice}</p>}</div>
                                        <button className="admin-settings-save" type="submit" disabled={saving || unchanged}><img src={SaveChangesIcon} alt="" />{saving ? 'Saving…' : 'Save Changes'}</button>
                                    </div>
                                </form>}
                        </> : <SecuritySettingsForm />}
                    </section>
                </div>
            </main>
        </div>
    )
}

export default SystemSettings
