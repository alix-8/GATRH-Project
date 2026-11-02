import { useEffect, useRef, useState } from 'react'
import { request } from '../../../api/request'
import ModalSelect from './ModalSelect'
import { handleModalFormEnter, isBackdropClick } from '../../../utils/modalDialogInteractions'
import './AddUserModal.css'
import './AddProgramModal.css'

function AddProgramModal({ onClose, onCreated }) {
    const dialogRef = useRef(null)
    const [form, setForm] = useState({ name: '', code: '', program_head_id: '', max_year_level: '4', is_active: true })
    const [programHeads, setProgramHeads] = useState([])
    const [headsLoading, setHeadsLoading] = useState(true)
    const [headsError, setHeadsError] = useState('')
    const [fieldErrors, setFieldErrors] = useState({})
    const [submitError, setSubmitError] = useState('')
    const [isSubmitting, setIsSubmitting] = useState(false)

    useEffect(() => {
        const dialog = dialogRef.current
        const previousOverflow = document.body.style.overflow
        document.body.style.overflow = 'hidden'
        dialog.showModal()

        let active = true
        request('/admin/program-head-options')
            .then(data => {
                if (!active) return
                setProgramHeads(data.program_head_options ?? [])
                setHeadsLoading(false)
            })
            .catch(error => {
                if (!active) return
                setHeadsError(error.message || 'Unable to load program heads. Close and reopen this form to try again.')
                setHeadsLoading(false)
            })

        return () => {
            active = false
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

        try {
            const result = await request('/admin/programs', {
                method: 'POST',
                body: JSON.stringify({
                    name: form.name.trim(),
                    code: form.code.trim().toUpperCase(),
                    program_head_ids: [Number(form.program_head_id)],
                    max_year_level: Number(form.max_year_level),
                    is_active: form.is_active,
                }),
            })
            onCreated(result.message)
        } catch (error) {
            const errors = error.details?.errors ?? {}
            setFieldErrors({ ...errors, program_head_id: errors['program_head_ids.0'] ?? errors.program_head_ids })
            setSubmitError(error.message || 'Unable to add the program. Please try again.')
            setIsSubmitting(false)
        }
    }

    return <dialog ref={dialogRef} className="admin-add-user-modal admin-add-program-modal" aria-labelledby="admin-add-program-title" aria-describedby="admin-add-program-description" onCancel={event => {
        event.preventDefault()
        if (!isSubmitting) onClose()
    }} onClick={event => {
        if (!isBackdropClick(event)) return
        const bounds = event.currentTarget.getBoundingClientRect()
        const outside = event.clientX < bounds.left || event.clientX > bounds.right || event.clientY < bounds.top || event.clientY > bounds.bottom
        if (outside && !isSubmitting) onClose()
    }}>
        <button className="admin-add-user-close" type="button" onClick={onClose} disabled={isSubmitting} aria-label="Close Add New Program">×</button>
        <h2 id="admin-add-program-title">Add New Program</h2>
        <p id="admin-add-program-description" className="admin-add-user-description">Create a new academic program and assign a program head.</p>
        <form onSubmit={handleSubmit} onKeyDown={handleModalFormEnter}>
            <div className="admin-add-user-field">
                <label htmlFor="admin-add-program-name">Program Name<span>*</span></label>
                <input id="admin-add-program-name" name="name" autoFocus required maxLength={255} placeholder="Name of the program" value={form.name} onChange={event => updateField('name', event.target.value)} aria-invalid={Boolean(fieldErrors.name)} />
                {fieldErrors.name && <small className="admin-add-user-field-error">{fieldErrors.name[0]}</small>}
            </div>
            <div className="admin-add-user-field">
                <label htmlFor="admin-add-program-code">Program Code<span>*</span></label>
                <input id="admin-add-program-code" name="code" required maxLength={20} pattern="[A-Za-z0-9_-]+" placeholder="Enter the program shortcut name" value={form.code} onChange={event => updateField('code', event.target.value.toUpperCase())} aria-invalid={Boolean(fieldErrors.code)} />
                {fieldErrors.code && <small className="admin-add-user-field-error">{fieldErrors.code[0]}</small>}
            </div>
            <div className="admin-add-user-field">
                <label htmlFor="admin-add-program-head">Assigned Program Head<span>*</span></label>
                <ModalSelect id="admin-add-program-head" name="program_head_id" required value={form.program_head_id} onChange={event => updateField('program_head_id', event.target.value)} disabled={headsLoading || Boolean(headsError) || !programHeads.length} aria-invalid={Boolean(fieldErrors.program_head_id)}>
                    <option value="">{headsLoading ? 'Loading program heads…' : 'Select Program Head'}</option>
                    {programHeads.map(head => <option key={head.id} value={head.id}>{head.name}</option>)}
                </ModalSelect>
                {headsError && <small className="admin-add-user-field-error">{headsError}</small>}
                {!headsLoading && !headsError && !programHeads.length && <small className="admin-add-user-field-error">No active Program Heads are available. Add a Program Head first.</small>}
                {fieldErrors.program_head_id && <small className="admin-add-user-field-error">{fieldErrors.program_head_id[0]}</small>}
            </div>
            <div className="admin-add-user-field">
                <label htmlFor="admin-add-program-years">Year Levels<span>*</span></label>
                <ModalSelect id="admin-add-program-years" name="max_year_level" required value={form.max_year_level} onChange={event => updateField('max_year_level', event.target.value)} aria-invalid={Boolean(fieldErrors.max_year_level)}>
                    <option value="4">1st Year - 4th Year (Default)</option>
                    <option value="2">1st Year - 2nd Year</option>
                </ModalSelect>
                {fieldErrors.max_year_level && <small className="admin-add-user-field-error">{fieldErrors.max_year_level[0]}</small>}
            </div>
            <div className="admin-add-user-status">
                <span>Program Status</span>
                <div><button type="button" role="switch" aria-checked={form.is_active} aria-label="Program active" className={`admin-add-user-switch${form.is_active ? ' active' : ''}`} onClick={() => updateField('is_active', !form.is_active)}><span /></button><span className={form.is_active ? 'active' : 'inactive'}>{form.is_active ? 'Active' : 'Inactive'}</span></div>
            </div>
            {submitError && <p className="admin-add-user-submit-error" role="alert">{submitError}</p>}
            <button className="admin-add-user-submit" type="submit" disabled={isSubmitting || headsLoading || Boolean(headsError) || !programHeads.length}>{isSubmitting ? 'Adding Program…' : 'Add Program'}</button>
            <div className="admin-add-user-note permissions"><span aria-hidden="true">i</span><p><strong>Program Information</strong><br />One Program Head may handle multiple programs. Year levels from 1st through {form.max_year_level === '2' ? '2nd' : '4th'} Year will be available for this program.</p></div>
        </form>
    </dialog>
}

export default AddProgramModal
