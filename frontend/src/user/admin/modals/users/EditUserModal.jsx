import { useEffect, useRef, useState } from 'react'
import { request } from '../../../../api/request'
import ModalSelect from '../../shared/ModalSelect'
import { handleModalFormEnter, isBackdropClick } from '../../../../utils/modalDialogInteractions'
import { sanitizePersonName } from '../../shared/adminTextValidation'
import './AddUserModal.css'
import './EditUserModal.css'

function EditUserModal({ user, roles, programs, canMarkGraduated, onClose, onUpdated }) {
    const dialogRef = useRef(null)
    const isProgramHead = user.role === 'program_head'
    const assignedProgram = user.programs?.length
        ? user.programs.map(program => program.code).join(', ')
        : user.program || ''
    const initialProgram = user.program || user.programs?.[0]?.code || ''
    const initialYearLevel = String(user.year_level || '').match(/^[1-4]/)?.[0] || String(user.year_level || '')
    const [form, setForm] = useState({
        name: user.name,
        email: user.email,
        role: user.role,
        program: initialProgram,
        year_level: initialYearLevel,
        status: user.inactive_reason === 'graduated' ? 'graduated' : user.is_active ? 'active' : 'inactive',
    })
    const [saving, setSaving] = useState(false)
    const [confirmSave, setConfirmSave] = useState(false)
    const [error, setError] = useState('')
    const [fieldErrors, setFieldErrors] = useState({})

    useEffect(() => {
        const dialog = dialogRef.current
        const previousOverflow = document.body.style.overflow
        document.body.style.overflow = 'hidden'
        dialog.showModal()
        return () => {
            document.body.style.overflow = previousOverflow
            if (dialog.open) dialog.close()
        }
    }, [])

    const needsProgram = ['secretary', 'program_secretary', 'program_head'].includes(form.role)
    const needsYearLevel = form.role === 'secretary'
    const canGraduate = form.role === 'program_secretary'
    const roleLabel = role => roles.find(option => option.value === role)?.label || role
    const currentProgram = isProgramHead ? assignedProgram : initialProgram
    const updatedProgram = isProgramHead ? assignedProgram : (needsProgram ? form.program : '')
    const isInitialInactive = !user.is_active
    const isGraduated = user.inactive_reason === 'graduated'
    const formIsActive = form.status === 'active'
    const areFieldsDisabled = isInitialInactive || !formIsActive
    const initialStatus = isGraduated ? 'graduated' : user.is_active ? 'active' : 'inactive'
    const isStatusChanged = form.status !== initialStatus
    const isSaveDisabled = saving || isGraduated || (isInitialInactive && !isStatusChanged)

    function updateField(field, value) {
        setForm(current => ({
            ...current,
            [field]: value,
            ...(field === 'role' && value !== current.role
                ? {
                    ...(!isProgramHead ? { program: '' } : {}),
                    year_level: '',
                    ...(current.status === 'graduated' && !['secretary', 'program_secretary'].includes(value) ? { status: 'inactive' } : {}),
                }
                : {}),
        }))
        setError('')
        setFieldErrors(current => ({ ...current, [field]: undefined }))
    }

    async function saveChanges() {
        setSaving(true)
        setError('')
        setFieldErrors({})

        const payload = {
            name: form.name.trim(),
            email: form.email.trim(),
            role: form.role,
            is_active: formIsActive,
            status: form.status,
        }

        if (!isProgramHead && (form.role !== user.role || form.program !== initialProgram)) {
            payload.program = needsProgram ? form.program : null
        }
        if (form.role !== user.role || form.year_level !== initialYearLevel) {
            payload.year_level = needsYearLevel ? form.year_level : null
        }

        try {
            const result = await request(`/admin/users/${user.id}`, {
                method: 'PATCH',
                body: JSON.stringify(payload),
            })
            onUpdated(result.message)
        } catch (submitError) {
            setError(submitError.message || 'Unable to update this user.')
            setFieldErrors(submitError.details?.errors ?? {})
            setSaving(false)
        }
    }

    function handleSubmit(event) {
        event.preventDefault()
        setConfirmSave(true)
    }

    return <dialog
        ref={dialogRef}
        className="admin-add-user-modal user-roles-edit-modal"
        aria-labelledby="user-roles-edit-title"
        aria-describedby="user-roles-edit-description"
        onCancel={event => { event.preventDefault(); if (!saving) onClose() }}
        onClick={event => {
            if (!isBackdropClick(event)) return
            const bounds = event.currentTarget.getBoundingClientRect()
            const outside = event.clientX < bounds.left || event.clientX > bounds.right
                || event.clientY < bounds.top || event.clientY > bounds.bottom
            if (outside && !saving) onClose()
        }}
    >
        <button className="admin-add-user-close" type="button" onClick={onClose} disabled={saving} aria-label="Close Edit User">×</button>
        <h2 id="user-roles-edit-title">Edit User</h2>
        <p id="user-roles-edit-description" className="admin-add-user-description">Update this account and its role assignment.</p>

        <form onSubmit={handleSubmit} onKeyDown={handleModalFormEnter}>
            <div className="admin-add-user-field">
                <label htmlFor="user-roles-edit-name">Full Name<span>*</span></label>
                <input id="user-roles-edit-name" autoFocus={!areFieldsDisabled} required maxLength={255} value={form.name} disabled={areFieldsDisabled} onChange={event => updateField('name', sanitizePersonName(event.target.value))} aria-invalid={Boolean(fieldErrors.name)} />
                {fieldErrors.name && <small className="admin-add-user-field-error">{fieldErrors.name[0]}</small>}
            </div>
            <div className="admin-add-user-field">
                <label htmlFor="user-roles-edit-email">Email Address<span>*</span></label>
                <input id="user-roles-edit-email" type="email" required maxLength={255} value={form.email} disabled={areFieldsDisabled} onChange={event => updateField('email', event.target.value)} aria-invalid={Boolean(fieldErrors.email)} />
                {fieldErrors.email && <small className="admin-add-user-field-error">{fieldErrors.email[0]}</small>}
            </div>
            <div className="admin-add-user-field">
                <label htmlFor="user-roles-edit-role">Role<span>*</span></label>
                <ModalSelect id="user-roles-edit-role" required value={form.role} disabled={areFieldsDisabled} onChange={event => updateField('role', event.target.value)} aria-invalid={Boolean(fieldErrors.role)}>
                    {roles.map(role => <option key={role.value} value={role.value}>{role.label}</option>)}
                </ModalSelect>
                {fieldErrors.role && <small className="admin-add-user-field-error">{fieldErrors.role[0]}</small>}
            </div>
            {isProgramHead ? <div className="admin-add-user-field">
                <label htmlFor="user-roles-edit-program">Program</label>
                <input id="user-roles-edit-program" value={assignedProgram || 'No program assigned'} disabled />
            </div> : needsProgram && <div className="admin-add-user-field">
                <label htmlFor="user-roles-edit-program">Program<span>*</span></label>
                <ModalSelect id="user-roles-edit-program" required value={form.program} disabled={areFieldsDisabled} onChange={event => updateField('program', event.target.value)} aria-invalid={Boolean(fieldErrors.program)}>
                    <option value="">Select Program</option>
                    {initialProgram && !programs.some(program => program.code === initialProgram) && <option value={initialProgram} disabled>{initialProgram} (inactive)</option>}
                    {programs.map(program => <option key={program.id} value={program.code}>{program.code}</option>)}
                </ModalSelect>
                {fieldErrors.program && <small className="admin-add-user-field-error">{fieldErrors.program[0]}</small>}
            </div>}
            {needsYearLevel && <div className="admin-add-user-field">
                <label htmlFor="user-roles-edit-year">Year Level<span>*</span></label>
                <ModalSelect id="user-roles-edit-year" required value={form.year_level} disabled={areFieldsDisabled} onChange={event => updateField('year_level', event.target.value)} aria-invalid={Boolean(fieldErrors.year_level)}>
                    <option value="">Select Year Level</option>
                    {[1, 2, 3, 4].map(year => <option key={year} value={String(year)}>{year}{['st', 'nd', 'rd', 'th'][year - 1]} Year</option>)}
                </ModalSelect>
                {fieldErrors.year_level && <small className="admin-add-user-field-error">{fieldErrors.year_level[0]}</small>}
            </div>}
            <div className="admin-add-user-status">
                <span>Account Status</span>
                <div>
                    {canGraduate ? <ModalSelect aria-label="Account status" value={form.status} disabled={isGraduated} onChange={event => updateField('status', event.target.value)}>
                        <option value="active">Active</option>
                        <option value="inactive">Inactive</option>
                        <option value="graduated" disabled={!canMarkGraduated}>Graduated{!canMarkGraduated ? ' (after rollover)' : ''}</option>
                    </ModalSelect> : isGraduated ? <span className="graduated">Graduated</span> : <>
                        <button type="button" role="switch" aria-checked={formIsActive} aria-label="Account active" className={`admin-add-user-switch${formIsActive ? ' active' : ''}`} onClick={() => updateField('status', formIsActive ? 'inactive' : 'active')}>
                            <span />
                        </button>
                        <span className={formIsActive ? 'active' : 'inactive'}>{formIsActive ? 'Active' : 'Inactive'}</span>
                    </>}
                </div>
            </div>
            {isGraduated ? (
                <aside className="admin-add-user-helper-note" role="note">
                    <strong>Graduated account guide</strong><br />
                    Graduated accounts cannot be reactivated. Program Secretaries can be marked Graduated after a school-year rollover has been applied.
                </aside>
            ) : isInitialInactive && (
                <p className="admin-add-user-helper-note">
                    {formIsActive
                        ? 'Save changes to activate this user account. Details can be edited once active.'
                        : 'This user account is currently inactive. Turn on Account Status and save to activate before editing.'}
                </p>
            )}
            {canGraduate && !canMarkGraduated && !isGraduated && (
                <p className="admin-add-user-helper-note">Graduated status will be available after the school-year rollover is applied.</p>
            )}
            {error && <p className="admin-add-user-submit-error" role="alert">{error}</p>}
            <button className="admin-add-user-submit" type="submit" disabled={isSaveDisabled}>{saving ? 'Saving…' : 'Save Changes'}</button>
        </form>
        {confirmSave && (
            <div
                className="admin-change-confirm-backdrop"
                onMouseDown={event => event.target === event.currentTarget && !saving && setConfirmSave(false)}
            >
                <section className="admin-change-confirm" role="alertdialog" aria-modal="true" aria-labelledby="user-edit-confirm-title">
                    <h3 id="user-edit-confirm-title">Confirm user changes</h3>
                    <p>Save these changes for <strong>{user.name}</strong>?</p>
                    <div className="admin-change-confirm-summary">
                        <p>Role: <strong>{roleLabel(user.role)} → {roleLabel(form.role)}</strong></p>
                        <p>Program: <strong>{currentProgram || 'Unassigned'} → {updatedProgram || 'Unassigned'}</strong></p>
                        <p>Status: <strong>{initialStatus === 'graduated' ? 'Graduated' : initialStatus === 'active' ? 'Active' : 'Inactive'} → {form.status === 'graduated' ? 'Graduated' : form.status === 'active' ? 'Active' : 'Inactive'}</strong></p>
                    </div>
                    <div className="admin-change-confirm-actions">
                        <button type="button" className="admin-add-user-cancel" onClick={() => setConfirmSave(false)} disabled={saving}>Cancel</button>
                        <button type="button" className="admin-add-user-submit" onClick={saveChanges} disabled={saving}>{saving ? 'Saving…' : 'Confirm Change'}</button>
                    </div>
                </section>
            </div>
        )}
    </dialog>
}

export default EditUserModal
