import { useEffect, useRef, useState } from 'react'
import { request } from '../../../api/request'
import ModalSelect from '../dashboard/ModalSelect'
import '../dashboard/AddUserModal.css'
import '../dashboard/AddProgramModal.css'

function EditProgramModal({ program, heads, onClose, onUpdated }) {
    const dialogRef = useRef(null)
    const initialHeadId = String(program.program_heads?.[0]?.id ?? '')
    const [form, setForm] = useState({
        name: program.name,
        code: program.code,
        program_head_id: initialHeadId,
        max_year_level: String(program.max_year_level),
        is_active: Boolean(program.is_active),
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
        setForm(current => ({ ...current, [field]: value }))
        setSubmitError('')
        setFieldErrors(current => ({ ...current, [field]: undefined }))
    }

    async function handleSubmit(event) {
        event.preventDefault()
        setIsSubmitting(true)
        setSubmitError('')
        setFieldErrors({})

        const payload = {
            name: form.name.trim(),
            code: form.code.trim().toUpperCase(),
            max_year_level: Number(form.max_year_level),
            is_active: form.is_active,
        }
        if (form.program_head_id !== initialHeadId) {
            payload.program_head_ids = form.program_head_id ? [Number(form.program_head_id)] : []
        }

        try {
            const result = await request(`/admin/programs/${program.id}`, {
                method: 'PATCH',
                body: JSON.stringify(payload),
            })
            onUpdated(result.message, result.program?.code)
        } catch (error) {
            const errors = error.details?.errors ?? {}
            setFieldErrors({ ...errors, program_head_id: errors['program_head_ids.0'] ?? errors.program_head_ids })
            setSubmitError(error.message || 'Unable to update the program.')
            setIsSubmitting(false)
        }
    }

    return <dialog ref={dialogRef} className="admin-add-user-modal admin-add-program-modal" aria-labelledby="admin-edit-program-title" onCancel={event => {
        event.preventDefault()
        if (!isSubmitting) onClose()
    }} onClick={event => {
        const bounds = event.currentTarget.getBoundingClientRect()
        const outside = event.clientX < bounds.left || event.clientX > bounds.right || event.clientY < bounds.top || event.clientY > bounds.bottom
        if (outside && !isSubmitting) onClose()
    }}>
        <button className="admin-add-user-close" type="button" onClick={onClose} disabled={isSubmitting} aria-label="Close Edit Program">×</button>
        <h2 id="admin-edit-program-title">Edit Program</h2>
        <p className="admin-add-user-description">Update {program.code} and its program head.</p>
        <form onSubmit={handleSubmit}>
            <div className="admin-add-user-field">
                <label htmlFor="admin-edit-program-name">Program Name<span>*</span></label>
                <input id="admin-edit-program-name" autoFocus required maxLength={255} value={form.name} onChange={event => updateField('name', event.target.value)} aria-invalid={Boolean(fieldErrors.name)} />
                {fieldErrors.name && <small className="admin-add-user-field-error">{fieldErrors.name[0]}</small>}
            </div>
            <div className="admin-add-user-field">
                <label htmlFor="admin-edit-program-code">Program Code<span>*</span></label>
                <input id="admin-edit-program-code" required maxLength={20} pattern="[A-Za-z0-9_-]+" value={form.code} onChange={event => updateField('code', event.target.value.toUpperCase())} aria-invalid={Boolean(fieldErrors.code)} />
                {fieldErrors.code && <small className="admin-add-user-field-error">{fieldErrors.code[0]}</small>}
            </div>
            <div className="admin-add-user-field">
                <label htmlFor="admin-edit-program-head">Assigned Program Head</label>
                <ModalSelect id="admin-edit-program-head" value={form.program_head_id} onChange={event => updateField('program_head_id', event.target.value)} aria-invalid={Boolean(fieldErrors.program_head_id)}>
                    <option value="">Unassigned</option>
                    {heads.map(head => <option key={head.id} value={head.id}>{head.name}</option>)}
                </ModalSelect>
                {fieldErrors.program_head_id && <small className="admin-add-user-field-error">{fieldErrors.program_head_id[0]}</small>}
            </div>
            <div className="admin-add-user-field">
                <label htmlFor="admin-edit-program-years">Year Levels<span>*</span></label>
                <ModalSelect id="admin-edit-program-years" required value={form.max_year_level} onChange={event => updateField('max_year_level', event.target.value)} aria-invalid={Boolean(fieldErrors.max_year_level)}>
                    <option value="4">1st Year - 4th Year</option>
                    <option value="2">1st Year - 2nd Year</option>
                </ModalSelect>
                {fieldErrors.max_year_level && <small className="admin-add-user-field-error">{fieldErrors.max_year_level[0]}</small>}
            </div>
            <div className="admin-add-user-status">
                <span>Program Status</span>
                <div><button type="button" role="switch" aria-checked={form.is_active} aria-label="Program active" className={`admin-add-user-switch${form.is_active ? ' active' : ''}`} onClick={() => updateField('is_active', !form.is_active)}><span /></button><span className={form.is_active ? 'active' : 'inactive'}>{form.is_active ? 'Active' : 'Inactive'}</span></div>
            </div>
            {!form.is_active && program.is_active && <p className="admin-add-user-submit-error">Deactivating a program may also deactivate its enrolled students and linked users.</p>}
            {submitError && <p className="admin-add-user-submit-error" role="alert">{submitError}</p>}
            <button className="admin-add-user-submit" type="submit" disabled={isSubmitting}>{isSubmitting ? 'Saving…' : 'Save Changes'}</button>
        </form>
    </dialog>
}

export default EditProgramModal
