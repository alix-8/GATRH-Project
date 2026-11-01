import { useEffect, useRef, useState } from 'react'
import Header from '../../../components/layout/Header'
import Sidebar from '../../../components/layout/Sidebar'
import LoadingCircle from '../../../components/feedback/LoadingCircle'
import { request } from '../../../api/request'
import GeneralIcon from '../../../assets/icons/system-settings-icon.svg'
import SecurityIcon from '../../../assets/icons/system-status-icon.svg'
import SaveChangesIcon from '../../../assets/icons/save-changes-icon.svg'
import SecuritySettingsForm from './SecuritySettingsForm'
import '../dashboard/AddUserModal.css'
import './SystemSettings.css'

const normalizeSystemName = value => value.replace(/\s+/g, ' ').trim()

function SystemSettings() {
    const [activeSection, setActiveSection] = useState('general')
    const [settings, setSettings] = useState(null)
    const [form, setForm] = useState(null)
    const [loading, setLoading] = useState(true)
    const [saving, setSaving] = useState(false)
    const [error, setError] = useState('')
    const [notice, setNotice] = useState('')
    const [loadAttempt, setLoadAttempt] = useState(0)
    const [rolloverStatus, setRolloverStatus] = useState(null)
    const [rolloverPreview, setRolloverPreview] = useState(null)
    const [rolloverBusy, setRolloverBusy] = useState(false)
    const [rolloverError, setRolloverError] = useState('')
    const rolloverDialogRef = useRef(null)

    useEffect(() => {
        let active = true
        request('/admin/settings/general')
            .then(data => {
                if (!active) return
                setSettings(data.settings)
                setForm({
                    system_name: data.settings.system_name ?? '',
                })
                setError('')
                request('/admin/students/school-year-rollover')
                    .then(setRolloverStatus)
                    .catch(() => setRolloverStatus(null))
            })
            .catch(error => { if (active) setError(error.message || 'Unable to load system settings.') })
            .finally(() => { if (active) setLoading(false) })
        return () => { active = false }
    }, [loadAttempt])

    useEffect(() => {
        const dialog = rolloverDialogRef.current
        if (!dialog) return
        if (rolloverPreview && !dialog.open) dialog.showModal()
        if (!rolloverPreview && dialog.open) dialog.close()
    }, [rolloverPreview])

    useEffect(() => {
        if (!rolloverStatus?.latest_rollover?.can_undo) return undefined
        const timer = window.setInterval(() => {
            setRolloverStatus(current => {
                const latest = current?.latest_rollover
                if (!latest?.can_undo) return current
                const remaining = latest.undo_expires_at
                    ? Math.max(0, Math.ceil((Date.parse(latest.undo_expires_at) - Date.now()) / 1000))
                    : Math.max(0, latest.remaining_seconds - 1)
                return { ...current, latest_rollover: { ...latest, remaining_seconds: remaining, can_undo: remaining > 0 } }
            })
        }, 1000)
        return () => window.clearInterval(timer)
    }, [rolloverStatus?.latest_rollover?.can_undo])

    useEffect(() => {
        if (!notice) return undefined
        const timeout = window.setTimeout(() => setNotice(''), 3000)
        return () => window.clearTimeout(timeout)
    }, [notice])

    useEffect(() => {
        if (!rolloverError) return undefined
        const timeout = window.setTimeout(() => setRolloverError(''), 5000)
        return () => window.clearTimeout(timeout)
    }, [rolloverError])

    const currentSchoolYear = settings?.academic_year || ''
    function updateField(name, value) {
        setForm(current => ({ ...current, [name]: value }))
        setNotice('')
        setError('')
    }

    async function saveSettings(event) {
        event.preventDefault()
        if (!form || saving) return
        if (!normalizeSystemName(form.system_name)) {
            setError('System name is required.')
            return
        }
        setSaving(true)
        setError('')
        setNotice('')
        try {
            const data = await request('/admin/settings/general', {
                method: 'PATCH',
                body: JSON.stringify({
                    system_name: normalizeSystemName(form.system_name),
                }),
            })
            setSettings(data.settings)
            setForm({
                system_name: data.settings.system_name,
            })
            setNotice('General settings saved successfully.')
        } catch (error) {
            setError(error.message || 'Unable to save general settings.')
        } finally {
            setSaving(false)
        }
    }

    async function previewRollover() {
        if (!currentSchoolYear || rolloverBusy) return
        setRolloverBusy(true)
        setRolloverError('')
        try {
            const data = await request('/admin/students/school-year-rollover/preview', {
                method: 'POST',
                body: JSON.stringify({ school_year: currentSchoolYear }),
            })
            setRolloverPreview(data)
        } catch (error) {
            setRolloverError(error.message || 'Unable to preview this academic-year rollover.')
        } finally {
            setRolloverBusy(false)
        }
    }

    async function confirmRollover() {
        if (!rolloverPreview || rolloverBusy) return
        setRolloverBusy(true)
        setRolloverError('')
        try {
            const result = await request('/admin/students/school-year-rollover', {
                method: 'POST',
                body: JSON.stringify({ school_year: rolloverPreview.school_year, preview_token: rolloverPreview.preview_token }),
            })
            setSettings(current => ({ ...current, academic_year: result.next_school_year }))
            setForm(current => ({ ...current, academic_year: result.next_school_year }))
            setRolloverStatus({
                current_school_year: result.next_school_year,
                latest_rollover: { id: result.rollover_id, school_year: result.school_year, undo_expires_at: result.undo_expires_at, can_undo: true, remaining_seconds: 300 },
            })
            request('/admin/students/school-year-rollover').then(setRolloverStatus).catch(() => {})
            setNotice(result.message)
            setRolloverPreview(null)
        } catch (error) {
            setRolloverError(error.message || 'Unable to complete the school-year rollover.')
        } finally {
            setRolloverBusy(false)
        }
    }

    async function undoRollover() {
        const rollover = rolloverStatus?.latest_rollover
        if (!rollover?.can_undo || rolloverBusy) return
        setRolloverBusy(true)
        setRolloverError('')
        try {
            const result = await request(`/admin/students/school-year-rollover/${rollover.id}/undo`, { method: 'POST' })
            setSettings(current => ({ ...current, academic_year: result.current_school_year }))
            setForm(current => ({ ...current, academic_year: result.current_school_year }))
            setRolloverStatus(current => ({ ...current, current_school_year: result.current_school_year, latest_rollover: { ...current?.latest_rollover, can_undo: false, remaining_seconds: 0 } }))
            request('/admin/students/school-year-rollover').then(setRolloverStatus).catch(() => {})
            setNotice(result.message)
        } catch (error) {
            setRolloverError(error.message || 'Unable to undo this rollover.')
            request('/admin/students/school-year-rollover').then(setRolloverStatus).catch(() => {})
        } finally {
            setRolloverBusy(false)
        }
    }

    function formatUndoCountdown(seconds) {
        const minutes = Math.floor(seconds / 60)
        const remainingSeconds = seconds % 60
        return `${minutes}:${String(remainingSeconds).padStart(2, '0')}`
    }

    const unchanged = settings && form && form.system_name === settings.system_name

    return (
        <div className="admin-system-settings">
            <Sidebar />
            <main className="admin-system-settings-main">
                <Header title="System Settings" subtitle="Set up your system settings" showNotification={false} className="admin-system-settings-header" />
                <div className="admin-system-settings-content">
                    <nav className="admin-settings-nav" aria-label="Settings sections">
                        <button type="button" className={`admin-settings-nav-item${activeSection === 'general' ? ' active' : ''}`} aria-current={activeSection === 'general' ? 'page' : undefined} onClick={() => setActiveSection('general')}><img src={GeneralIcon} alt="" />General</button>
                        <button type="button" className={`admin-settings-nav-item${activeSection === 'security' ? ' active' : ''}`} aria-current={activeSection === 'security' ? 'page' : undefined} onClick={() => setActiveSection('security')}><img src={SecurityIcon} alt="" />Security</button>
                    </nav>
                    <section className="admin-settings-panel" aria-labelledby={activeSection === 'general' ? 'admin-settings-general-title' : 'admin-settings-security-title'}>
                        {activeSection === 'general' ? <>
                            <div className="admin-settings-panel-heading">
                            <h2 id="admin-settings-general-title">General</h2>
                            <p>Update your system name and general settings.</p>
                            </div>
                        {loading ? <div className="admin-settings-loading"><LoadingCircle label="Loading general settings..." /></div>
                            : !form ? <div className="admin-settings-load-error" role="alert"><p>{error || 'Unable to load system settings.'}</p><button type="button" onClick={() => { setLoading(true); setError(''); setLoadAttempt(current => current + 1) }}>Try again</button></div>
                                : <form className="admin-settings-form" onSubmit={saveSettings}>
                                    <div className="admin-settings-fields">
                                        <label className="admin-settings-field"><span>System Name</span><input type="text" name="system_name" value={form.system_name} onChange={event => updateField('system_name', event.target.value)} maxLength={120} required disabled={saving} /></label>
                                        <div className="admin-settings-field-row">
                                            <div className="admin-settings-field"><span>Academic Year</span><p className="admin-settings-year-display">{currentSchoolYear}</p></div>
                                        </div>
                                    </div>
                                    <section className="admin-rollover-panel" aria-labelledby="admin-rollover-title">
                                        <div>
                                            <h3 id="admin-rollover-title">Academic Year Rollover</h3>
                                            <p>Preview student and secretary promotions for the current year before applying them.</p>
                                        </div>
                                        <button className="admin-settings-save admin-rollover-preview-button" type="button" onClick={previewRollover} disabled={saving || rolloverBusy || !currentSchoolYear}>
                                            {rolloverBusy ? 'Please wait…' : 'Preview Rollover'}
                                        </button>
                                        {rolloverError && <p className="admin-rollover-error" role="alert">{rolloverError}</p>}
                                        {rolloverStatus?.latest_rollover?.can_undo && (
                                            <div className="admin-rollover-undo" role="status">
                                                <span>Rollover complete. Undo available for {formatUndoCountdown(rolloverStatus.latest_rollover.remaining_seconds)}.</span>
                                                <button type="button" onClick={undoRollover} disabled={rolloverBusy}>Undo</button>
                                            </div>
                                        )}
                                    </section>
                                    <div className="admin-settings-form-footer">
                                        <div className="admin-settings-message" role="status" aria-live="polite">{error && <p className="error">{error}</p>}{notice && <p className="success">{notice}</p>}</div>
                                        <button className="admin-settings-save" type="submit" disabled={saving || unchanged}><img src={SaveChangesIcon} alt="" />{saving ? 'Saving…' : 'Save Changes'}</button>
                                    </div>
                                </form>}
                        </> : <SecuritySettingsForm />}
                    </section>
                </div>
            </main>
            <dialog
                ref={rolloverDialogRef}
                className="admin-add-user-modal admin-rollover-modal"
                aria-labelledby="admin-rollover-confirm-title"
                onCancel={event => { event.preventDefault(); if (!rolloverBusy) setRolloverPreview(null) }}
            >
                {rolloverPreview && <>
                    <button className="admin-add-user-close" type="button" onClick={() => !rolloverBusy && setRolloverPreview(null)} disabled={rolloverBusy} aria-label="Close rollover preview">×</button>
                    <h2 id="admin-rollover-confirm-title">Confirm Academic Year Rollover</h2>
                    <p className="admin-add-user-description">Review the impact before moving from {rolloverPreview.school_year} to {rolloverPreview.next_school_year}.</p>
                    <div className="admin-rollover-summary">
                        <div><span>Students promoted</span><strong>{rolloverPreview.promoted_students_count}</strong></div>
                        <div><span>Students graduating</span><strong>{rolloverPreview.graduated_students_count}</strong></div>
                        <div><span>Secretaries promoted</span><strong>{rolloverPreview.promoted_secretaries_count}</strong></div>
                        <div><span>Secretaries graduating</span><strong>{rolloverPreview.graduated_secretaries_count}</strong></div>
                    </div>
                    <div className="admin-rollover-breakdown">
                        <h3>Active students by program and year</h3>
                        {rolloverPreview.students_by_program_year.length ? rolloverPreview.students_by_program_year.map(item => (
                            <div key={`${item.program}-${item.year_level}`}>
                                <span>{item.program} · Year {item.year_level}</span>
                                <strong>{item.active_count} {item.will_graduate ? 'will graduate' : 'will be promoted'}</strong>
                            </div>
                        )) : <p>No active students are included.</p>}
                    </div>
                    <p className="admin-rollover-warning">The current school year advances after completion. You can undo this action for five minutes unless affected records change.</p>
                    {rolloverError && <p className="admin-add-user-submit-error" role="alert">{rolloverError}</p>}
                    <div className="admin-rollover-actions">
                        <button type="button" onClick={() => setRolloverPreview(null)} disabled={rolloverBusy}>Cancel</button>
                        <button className="admin-add-user-submit" type="button" onClick={confirmRollover} disabled={rolloverBusy}>{rolloverBusy ? 'Applying…' : 'Confirm & Run Rollover'}</button>
                    </div>
                </>}
            </dialog>
        </div>
    )
}

export default SystemSettings
