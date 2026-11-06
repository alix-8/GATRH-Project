import { useEffect, useRef, useState } from 'react'
import { request } from '../../../api/request'
import ModalSelect from '../dashboard/ModalSelect'
import { handleModalFormEnter, isBackdropClick } from '../../../utils/modalDialogInteractions'
import { sanitizePersonName } from '../adminTextValidation'
import '../dashboard/AddUserModal.css'
import '../dashboard/AddStudentModal.css'

const years = [
    { value: '1', label: '1st Year' },
    { value: '2', label: '2nd Year' },
    { value: '3', label: '3rd Year' },
    { value: '4', label: '4th Year' },
]

function EditStudentModal({ student, programs, onClose, onUpdated }) {
    const dialogRef = useRef(null)
    const savedName = student.name ?? ''
    const separator = savedName.indexOf(',')
    const [form, setForm] = useState({
        lname: (separator === -1 ? savedName : savedName.slice(0, separator)).trim(),
        fname: separator === -1 ? '' : savedName.slice(separator + 1).trim(),
        program: student.program,
        year_level: String(student.year_level),
        is_active: Boolean(student.is_active),
    })
    const [isSubmitting, setIsSubmitting] = useState(false)
    const [submitError, setSubmitError] = useState('')
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

    function updateField(field, value) {
        setForm(current => ({
            ...current,
            [field]: value,
            ...(field === 'program' && value === 'ACT' && Number(current.year_level) > 2 ? { year_level: '' } : {}),
        }))
        setFieldErrors(current => ({
            ...current,
            [field]: undefined,
            ...(['lname', 'fname'].includes(field) ? { name: undefined } : {}),
        }))
        setSubmitError('')
    }

    async function handleSubmit(event) {
        event.preventDefault()
        if (isSubmitting) return
        const lastName = form.lname.trim().replace(/\s+/g, ' ')
        const firstName = form.fname.trim().replace(/\s+/g, ' ')
        const name = `${lastName}, ${firstName}`
        const errors = {}
        if (!lastName) errors.lname = ['Enter the last name.']
        if (!firstName) errors.fname = ['Enter the first name.']
        if (name.length > 255) errors.name = ['The combined name must be 255 characters or fewer.']
        if (Object.keys(errors).length) {
            setFieldErrors(errors)
            return
        }
        setIsSubmitting(true)
        setSubmitError('')
        setFieldErrors({})
        try {
            const result = await request(`/admin/students/${student.id}`, {
                method: 'PATCH',
                body: JSON.stringify({
                    name,
                    program: form.program,
                    year_level: form.year_level,
                    is_active: form.is_active,
                }),
            })
            onUpdated(result.message)
        } catch (error) {
            setSubmitError(error.message || 'Unable to update the student.')
            setFieldErrors(error.details?.errors ?? {})
            setIsSubmitting(false)
        }
    }

    const isInitialInactive = !student.is_active
    const isGraduated = student.inactive_reason === 'graduated'
    const areFieldsDisabled = isInitialInactive || !form.is_active
    const isStatusChanged = form.is_active !== Boolean(student.is_active)
    const isSaveDisabled = isSubmitting || isGraduated || (isInitialInactive && !isStatusChanged)

    return <dialog ref={dialogRef} className="admin-add-user-modal admin-add-student-modal" aria-labelledby="admin-edit-student-title" onCancel={event => {
        event.preventDefault()
        if (!isSubmitting) onClose()
    }} onClick={event => {
        if (!isBackdropClick(event)) return
        const bounds = event.currentTarget.getBoundingClientRect()
        if (!isSubmitting && (event.clientX < bounds.left || event.clientX > bounds.right || event.clientY < bounds.top || event.clientY > bounds.bottom)) onClose()
    }}>
        <button className="admin-add-user-close" type="button" onClick={onClose} disabled={isSubmitting} aria-label="Close Edit Student">×</button>
        <h2 id="admin-edit-student-title">Edit Student</h2>
        <p className="admin-add-user-description">Update the student record.</p>
        <form onSubmit={handleSubmit} onKeyDown={handleModalFormEnter}>
            <div className="admin-add-user-field">
                <label htmlFor="admin-edit-student-lname">Last Name<span>*</span></label>
                <input id="admin-edit-student-lname" name="lname" autoFocus={!areFieldsDisabled} required maxLength={255} placeholder="Enter last name" value={form.lname} disabled={areFieldsDisabled} onChange={event => updateField('lname', sanitizePersonName(event.target.value))} aria-invalid={Boolean(fieldErrors.lname || fieldErrors.name)} />
                {fieldErrors.lname && <small className="admin-add-user-field-error">{fieldErrors.lname[0]}</small>}
            </div>
            <div className="admin-add-user-field">
                <label htmlFor="admin-edit-student-fname">First Name<span>*</span></label>
                <input id="admin-edit-student-fname" name="fname" required maxLength={255} placeholder="Enter first name" value={form.fname} disabled={areFieldsDisabled} onChange={event => updateField('fname', sanitizePersonName(event.target.value))} aria-invalid={Boolean(fieldErrors.fname || fieldErrors.name)} />
                {fieldErrors.fname && <small className="admin-add-user-field-error">{fieldErrors.fname[0]}</small>}
                {fieldErrors.name && <small className="admin-add-user-field-error">{fieldErrors.name[0]}</small>}
            </div>
            <div className="admin-add-user-field">
                <label htmlFor="admin-edit-student-program">Program<span>*</span></label>
                <ModalSelect id="admin-edit-student-program" required value={form.program} disabled={areFieldsDisabled} onChange={event => updateField('program', event.target.value)} aria-invalid={Boolean(fieldErrors.program)}>
                    {!programs.some(program => program.code === student.program) && <option value={student.program} disabled>{student.program} (inactive)</option>}
                    {programs.map(program => <option key={program.id} value={program.code}>{program.code}</option>)}
                </ModalSelect>
                {fieldErrors.program && <small className="admin-add-user-field-error">{fieldErrors.program[0]}</small>}
            </div>
            <div className="admin-add-user-field">
                <label htmlFor="admin-edit-student-year">Year Level<span>*</span></label>
                <ModalSelect id="admin-edit-student-year" required value={form.year_level} disabled={areFieldsDisabled} onChange={event => updateField('year_level', event.target.value)} aria-invalid={Boolean(fieldErrors.year_level)}>
                    <option value="">Select Year Level</option>
                    {(form.program === 'ACT' ? years.slice(0, 2) : years).map(year => <option key={year.value} value={year.value}>{year.label}</option>)}
                </ModalSelect>
                {fieldErrors.year_level && <small className="admin-add-user-field-error">{fieldErrors.year_level[0]}</small>}
            </div>
            <div className="admin-add-user-status">
                <span>Student Status</span>
                <div><button type="button" role="switch" aria-checked={form.is_active} aria-label="Student active" className={`admin-add-user-switch${form.is_active ? ' active' : ''}`} onClick={() => updateField('is_active', !form.is_active)} disabled={isGraduated}><span /></button><span className={form.is_active ? 'active' : 'inactive'}>{isGraduated ? 'Graduated' : form.is_active ? 'Active' : 'Inactive'}</span></div>
            </div>
            {isGraduated ? (
                <aside className="admin-add-user-helper-note" role="note">
                    <strong>Graduated status guide</strong><br />
                    Graduated students remain inactive and cannot be reactivated. If this graduation was recorded by mistake, contact your system administrator.
                </aside>
            ) : isInitialInactive && (
                <p className="admin-add-user-helper-note">
                    {form.is_active
                        ? 'Save changes to activate this student. Details can be edited once active.'
                        : 'This student is currently inactive. Turn on Student Status and save to activate before editing.'}
                </p>
            )}
            {submitError && <p className="admin-add-user-submit-error" role="alert">{submitError}</p>}
            <button className="admin-add-user-submit" type="submit" disabled={isSaveDisabled}>{isSubmitting ? 'Saving…' : 'Save Changes'}</button>
        </form>
    </dialog>
}

export default EditStudentModal
