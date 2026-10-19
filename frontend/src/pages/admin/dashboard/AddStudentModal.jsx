import { useEffect, useRef, useState } from 'react'
import { request } from '../../../api/request'
import ModalSelect from './ModalSelect'
import './AddUserModal.css'
import './AddStudentModal.css'

const programOrder = ['ACT', 'BSIS', 'BSSW', 'BSA', 'BSAIS', 'BAB']
const programOptions = programOrder.map(code => ({ value: code, label: code }))
const yearOptions = [
    { value: '1', label: '1st Year' },
    { value: '2', label: '2nd Year' },
    { value: '3', label: '3rd Year' },
    { value: '4', label: '4th Year' },
]

function AddStudentModal({ onClose, onCreated }) {
    const dialogRef = useRef(null)
    const [form, setForm] = useState({ name: '', program: '', year_level: '', is_active: true })
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
        setForm(current => ({ ...current, [field]: value, ...(field === 'program' && value === 'ACT' && Number(current.year_level) > 2 ? { year_level: '' } : {}) }))
        setFieldErrors(current => ({ ...current, [field]: undefined }))
        setSubmitError('')
    }

    async function handleSubmit(event) {
        event.preventDefault()
        const errors = {}
        if (!form.program) errors.program = ['Select a program.']
        if (!form.year_level) errors.year_level = ['Select a year level.']
        if (Object.keys(errors).length) {
            setFieldErrors(errors)
            return
        }
        setIsSubmitting(true)
        setSubmitError('')
        setFieldErrors({})
        try {
            const result = await request('/admin/students', {
                method: 'POST',
                body: JSON.stringify({
                    ...form,
                    name: form.name.trim(),
                }),
            })
            onCreated(result.message)
        } catch (error) {
            setSubmitError(error.message || 'Unable to add the student. Please try again.')
            setFieldErrors(error.details?.errors ?? {})
            setIsSubmitting(false)
        }
    }

    return <dialog ref={dialogRef} className="admin-add-user-modal admin-add-student-modal" aria-labelledby="admin-add-student-title" aria-describedby="admin-add-student-description" onCancel={event => {
        event.preventDefault()
        if (!isSubmitting) onClose()
    }} onClick={event => {
        const bounds = event.currentTarget.getBoundingClientRect()
        const outside = event.clientX < bounds.left || event.clientX > bounds.right || event.clientY < bounds.top || event.clientY > bounds.bottom
        if (outside && !isSubmitting) onClose()
    }}>
        <button className="admin-add-user-close" type="button" onClick={onClose} disabled={isSubmitting} aria-label="Close Add New Student">×</button>
        <h2 id="admin-add-student-title">Add New Student</h2>
        <p id="admin-add-student-description" className="admin-add-user-description">Create a new student record and assign a program.</p>
        <form onSubmit={handleSubmit}>
            <div className="admin-add-user-field">
                <label htmlFor="admin-add-student-name">Full Name<span>*</span></label>
                <input id="admin-add-student-name" name="name" autoFocus required maxLength={255} placeholder="Enter full name" value={form.name} onChange={event => updateField('name', event.target.value)} aria-invalid={Boolean(fieldErrors.name)} />
                {fieldErrors.name && <small className="admin-add-user-field-error">{fieldErrors.name[0]}</small>}
            </div>
            <div className="admin-add-user-field">
                <label htmlFor="admin-add-student-program">Program<span>*</span></label>
                <ModalSelect id="admin-add-student-program" name="program" required value={form.program} onChange={event => updateField('program', event.target.value)} aria-invalid={Boolean(fieldErrors.program)}>
                    <option value="">Select Program</option>
                    {programOptions.map(program => <option key={program.value} value={program.value}>{program.label}</option>)}
                </ModalSelect>
                {fieldErrors.program && <small className="admin-add-user-field-error">{fieldErrors.program[0]}</small>}
            </div>
            <div className="admin-add-user-field">
                <label htmlFor="admin-add-student-year">Year Level<span>*</span></label>
                <ModalSelect id="admin-add-student-year" name="year_level" required value={form.year_level} onChange={event => updateField('year_level', event.target.value)} aria-invalid={Boolean(fieldErrors.year_level)}>
                    <option value="">Select Year Level</option>
                    {(form.program === 'ACT' ? yearOptions.slice(0, 2) : yearOptions).map(year => <option key={year.value} value={year.value}>{year.label}</option>)}
                </ModalSelect>
                {fieldErrors.year_level && <small className="admin-add-user-field-error">{fieldErrors.year_level[0]}</small>}
            </div>
            <div className="admin-add-user-status">
                <span>Student Status</span>
                <div><button type="button" role="switch" aria-checked={form.is_active} aria-label="Student active" className={`admin-add-user-switch${form.is_active ? ' active' : ''}`} onClick={() => updateField('is_active', !form.is_active)}><span /></button><span className={form.is_active ? 'active' : 'inactive'}>{form.is_active ? 'Active' : 'Inactive'}</span></div>
            </div>
            {submitError && <p className="admin-add-user-submit-error" role="alert">{submitError}</p>}
            <button className="admin-add-user-submit" type="submit" disabled={isSubmitting}>{isSubmitting ? 'Adding Student…' : 'Add Student'}</button>
            <div className="admin-add-user-note permissions"><span aria-hidden="true">i</span><p><strong>Student Records</strong><br />Review and update student details from the Students page.</p></div>
        </form>
    </dialog>
}

export default AddStudentModal
