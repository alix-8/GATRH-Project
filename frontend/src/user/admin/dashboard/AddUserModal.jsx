import { useEffect, useRef, useState } from 'react'
import { request } from '../../../api/request'
import ModalSelect from './ModalSelect'
import { handleModalFormEnter, isBackdropClick } from '../../../utils/modalDialogInteractions'
import './AddUserModal.css'

const roleOptions = [
    { value: 'secretary', label: 'Spiritual Secretary' },
    { value: 'program_head', label: 'Program Head' },
    { value: 'program_secretary', label: 'Program Spiritual Secretary' },
    { value: 'chancellor', label: 'Chancellor' },
    { value: 'cm_coordinator', label: 'Coordinator' },
]

const programOrder = ['ACT', 'BSIS', 'BSSW', 'BSA', 'BSAIS', 'BAB']

const initialForm = {
    name: '',
    email: '',
    role: '',
    program: '',
    year_level: '',
    is_active: true,
}

function AddUserModal({ onClose, onCreated }) {
    const dialogRef = useRef(null)
    const [form, setForm] = useState(initialForm)
    const [programs, setPrograms] = useState([])
    const [programsLoading, setProgramsLoading] = useState(true)
    const [programsError, setProgramsError] = useState('')
    const [isSubmitting, setIsSubmitting] = useState(false)
    const [submitError, setSubmitError] = useState('')
    const [fieldErrors, setFieldErrors] = useState({})

    useEffect(() => {
        const dialog = dialogRef.current
        const previousOverflow = document.body.style.overflow
        document.body.style.overflow = 'hidden'
        dialog.showModal()

        let active = true
        request('/admin/users?per_page=1')
            .then(data => {
                if (active) {
                    const availablePrograms = [...(data.programs ?? [])].sort((first, second) => {
                        const firstIndex = programOrder.indexOf(first.code)
                        const secondIndex = programOrder.indexOf(second.code)

                        return (firstIndex === -1 ? 99 : firstIndex) - (secondIndex === -1 ? 99 : secondIndex)
                            || first.code.localeCompare(second.code)
                    })
                    setPrograms(availablePrograms)
                    setProgramsLoading(false)
                }
            })
            .catch(() => {
                if (active) {
                    setProgramsError('Unable to load programs. Close and reopen this form to try again.')
                    setProgramsLoading(false)
                }
            })

        return () => {
            active = false
            document.body.style.overflow = previousOverflow
            if (dialog.open) dialog.close()
        }
    }, [])

    const needsProgram = ['secretary', 'program_head', 'program_secretary'].includes(form.role)
    const needsYearLevel = form.role === 'secretary'

    function updateField(field, value) {
        setForm(current => ({
            ...current,
            [field]: value,
            ...(field === 'role' ? { program: '', year_level: '' } : {}),
        }))
        setSubmitError('')
        setFieldErrors(current => field === 'role' ? {} : { ...current, [field]: undefined })
    }

    async function handleSubmit(event) {
        event.preventDefault()
        const email = form.email.trim().toLowerCase()
        const domain = email.slice(email.lastIndexOf('@') + 1)
        if (!/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)*laverdad\.edu\.ph$/.test(domain)) {
            setFieldErrors({ email: ['Use a La Verdad email address, such as name@laverdad.edu.ph or name@student.laverdad.edu.ph.'] })
            setSubmitError('')
            return
        }
        setIsSubmitting(true)
        setSubmitError('')
        setFieldErrors({})

        const payload = {
            name: form.name.trim(),
            email,
            role: form.role,
            is_active: form.is_active,
            ...(needsProgram ? { program: form.program } : {}),
            ...(needsYearLevel ? { year_level: form.year_level } : {}),
        }

        try {
            const result = await request('/admin/users', {
                method: 'POST',
                body: JSON.stringify(payload),
            })
            setIsSubmitting(false)
            onCreated(result.message)
        } catch (error) {
            setSubmitError(error.message || 'Unable to create the user. Please try again.')
            setFieldErrors(error.details?.errors ?? {})
            setIsSubmitting(false)
        }
    }

    return (
        <dialog
            ref={dialogRef}
            className="admin-add-user-modal"
            aria-labelledby="admin-add-user-title"
            aria-describedby="admin-add-user-description"
            onCancel={event => {
                event.preventDefault()
                if (!isSubmitting) onClose()
            }}
            onClick={event => {
                if (!isBackdropClick(event)) return
                const bounds = event.currentTarget.getBoundingClientRect()
                const outside = event.clientX < bounds.left || event.clientX > bounds.right
                    || event.clientY < bounds.top || event.clientY > bounds.bottom
                if (outside && !isSubmitting) onClose()
            }}
        >
            <button className="admin-add-user-close" type="button" onClick={onClose} disabled={isSubmitting} aria-label="Close Add New User">×</button>
            <h2 id="admin-add-user-title">Add New User</h2>
            <p id="admin-add-user-description" className="admin-add-user-description">Create a new account and assign a role.</p>

            <form onSubmit={handleSubmit} onKeyDown={handleModalFormEnter}>
                <div className="admin-add-user-field">
                    <label htmlFor="admin-add-user-name">Full Name<span>*</span></label>
                    <input id="admin-add-user-name" name="name" autoFocus required maxLength={255} placeholder="Enter full name" value={form.name} onChange={event => updateField('name', event.target.value)} aria-invalid={Boolean(fieldErrors.name)} />
                    {fieldErrors.name && <small className="admin-add-user-field-error">{fieldErrors.name[0]}</small>}
                </div>

                <div className="admin-add-user-field">
                    <label htmlFor="admin-add-user-email">Email Address<span>*</span></label>
                    <input id="admin-add-user-email" name="email" type="email" required maxLength={255} placeholder="name@laverdad.edu.ph" value={form.email} onChange={event => updateField('email', event.target.value)} aria-invalid={Boolean(fieldErrors.email)} />
                    {fieldErrors.email && <small className="admin-add-user-field-error">{fieldErrors.email[0]}</small>}
                </div>

                <div className="admin-add-user-field">
                    <label htmlFor="admin-add-user-role">Role<span>*</span></label>
                    <ModalSelect id="admin-add-user-role" name="role" required value={form.role} onChange={event => updateField('role', event.target.value)} aria-invalid={Boolean(fieldErrors.role)}>
                        <option value="">Select Role</option>
                        {roleOptions.map(role => <option key={role.value} value={role.value}>{role.label}</option>)}
                    </ModalSelect>
                    {fieldErrors.role && <small className="admin-add-user-field-error">{fieldErrors.role[0]}</small>}
                </div>

                {needsProgram && <div className="admin-add-user-field">
                    <label htmlFor="admin-add-user-program">{needsYearLevel ? 'Program' : 'Assigned Program'}<span>*</span></label>
                    <ModalSelect id="admin-add-user-program" name="program" required value={form.program} onChange={event => updateField('program', event.target.value)} aria-invalid={Boolean(fieldErrors.program)}>
                        <option value="">{programsLoading ? 'Loading programs…' : 'Select Program'}</option>
                        {programs.map(program => <option key={program.id} value={program.code}>{program.code}</option>)}
                    </ModalSelect>
                    {programsError && <small className="admin-add-user-field-error">{programsError}</small>}
                    {!programsLoading && !programsError && programs.length === 0 && <small className="admin-add-user-field-error">No programs available. Add a program first.</small>}
                    {fieldErrors.program && <small className="admin-add-user-field-error">{fieldErrors.program[0]}</small>}
                </div>}

                {needsYearLevel && <div className="admin-add-user-field">
                    <label htmlFor="admin-add-user-year">Year Level<span>*</span></label>
                    <ModalSelect id="admin-add-user-year" name="year_level" required value={form.year_level} onChange={event => updateField('year_level', event.target.value)} aria-invalid={Boolean(fieldErrors.year_level)}>
                        <option value="">Select Year Level</option>
                        {[1, 2, 3, 4].map(year => <option key={year} value={String(year)}>{year}{['st', 'nd', 'rd', 'th'][year - 1]} Year</option>)}
                    </ModalSelect>
                    {fieldErrors.year_level && <small className="admin-add-user-field-error">{fieldErrors.year_level[0]}</small>}
                </div>}

                <div className="admin-add-user-note warning">
                    <span aria-hidden="true">!</span>
                    <p><strong>Password will be generated automatically</strong><br />A secure random password will be emailed to the user. They can change it after signing in.</p>
                </div>

                <div className="admin-add-user-status">
                    <span>Account Status</span>
                    <div>
                        <button type="button" role="switch" aria-checked={form.is_active} aria-label="Account active" className={`admin-add-user-switch${form.is_active ? ' active' : ''}`} onClick={() => updateField('is_active', !form.is_active)}><span /></button>
                        <span className={form.is_active ? 'active' : 'inactive'}>{form.is_active ? 'Active' : 'Inactive'}</span>
                    </div>
                </div>

                {submitError && <p className="admin-add-user-submit-error" role="alert">{submitError}</p>}
                <button className="admin-add-user-submit" type="submit" disabled={isSubmitting || (needsProgram && (programsLoading || Boolean(programsError) || programs.length === 0))}>{isSubmitting ? 'Creating User…' : 'Add User'}</button>

                <div className="admin-add-user-note permissions">
                    <span aria-hidden="true">i</span>
                    <p><strong>Role Permissions</strong><br />Each role has specific access levels. Administrators can manage all settings while other roles are limited to their assigned functions.</p>
                </div>
            </form>
        </dialog>
    )
}

export default AddUserModal
