import { useEffect, useRef, useState } from 'react'
import { request } from '../../../api/request'
import ModalSelect from '../dashboard/ModalSelect'
import '../dashboard/AddUserModal.css'
import '../dashboard/AddStudentModal.css'
import './ImportStudentsModal.css'

const years = [
    { value: '1', label: '1st Year' },
    { value: '2', label: '2nd Year' },
    { value: '3', label: '3rd Year' },
    { value: '4', label: '4th Year' },
]

function ImportStudentsModal({ programs, onClose, onImported }) {
    const dialogRef = useRef(null)
    const fileRef = useRef(null)
    const [file, setFile] = useState(null)
    const [program, setProgram] = useState('')
    const [yearLevel, setYearLevel] = useState('')
    const [isActive, setIsActive] = useState(true)
    const [isDragging, setIsDragging] = useState(false)
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

    function selectFile(selected) {
        if (!selected) return
        if (!/\.(xlsx|csv)$/i.test(selected.name)) {
            setFile(null)
            setFieldErrors(current => ({ ...current, file: ['Choose an .xlsx or .csv file.'] }))
            return
        }
        if (selected.size > 2 * 1024 * 1024) {
            setFile(null)
            setFieldErrors(current => ({ ...current, file: ['The file must be 2 MB or smaller.'] }))
            return
        }
        setFile(selected)
        setFieldErrors(current => ({ ...current, file: undefined }))
        setSubmitError('')
    }

    async function handleSubmit(event) {
        event.preventDefault()
        const errors = {}
        if (!file) errors.file = ['Choose an Excel or CSV file.']
        if (!program) errors.program = ['Select a program.']
        if (!yearLevel) errors.year_level = ['Select a year level.']
        if (Object.keys(errors).length) {
            setFieldErrors(errors)
            return
        }

        setIsSubmitting(true)
        setSubmitError('')
        setFieldErrors({})
        const formData = new FormData()
        formData.append('file', file)
        formData.append('program', program)
        formData.append('year_level', yearLevel)
        formData.append('is_active', isActive ? '1' : '0')
        try {
            const result = await request('/admin/students/import', { method: 'POST', body: formData })
            onImported(result.message)
        } catch (error) {
            setSubmitError(error.message || 'Unable to import students.')
            setFieldErrors(error.details?.errors ?? {})
            setIsSubmitting(false)
        }
    }

    return <dialog ref={dialogRef} className="admin-add-user-modal admin-add-student-modal admin-import-students-modal" aria-labelledby="admin-import-students-title" aria-describedby="admin-import-students-description" onCancel={event => {
        event.preventDefault()
        if (!isSubmitting) onClose()
    }} onClick={event => {
        const bounds = event.currentTarget.getBoundingClientRect()
        if (!isSubmitting && (event.clientX < bounds.left || event.clientX > bounds.right || event.clientY < bounds.top || event.clientY > bounds.bottom)) onClose()
    }}>
        <button className="admin-add-user-close" type="button" onClick={onClose} disabled={isSubmitting} aria-label="Close Import Students">×</button>
        <h2 id="admin-import-students-title">Import Students</h2>
        <p id="admin-import-students-description" className="admin-add-user-description">Upload a student list and assign its program, year level, and status.</p>
        <form onSubmit={handleSubmit}>
            <div className="admin-add-user-field admin-import-file-field">
                <label htmlFor="admin-import-students-file">Attach an Excel File<span>*</span></label>
                <input ref={fileRef} id="admin-import-students-file" className="admin-import-file-input" type="file" accept=".xlsx,.csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,text/csv" onChange={event => selectFile(event.target.files?.[0])} />
                <button className={`admin-import-drop-zone${isDragging ? ' is-dragging' : ''}`} type="button" onClick={() => fileRef.current?.click()} onDragOver={event => { event.preventDefault(); setIsDragging(true) }} onDragLeave={() => setIsDragging(false)} onDrop={event => { event.preventDefault(); setIsDragging(false); selectFile(event.dataTransfer.files?.[0]) }}>
                    <svg viewBox="0 0 20 20" fill="none" aria-hidden="true"><path d="M5 2.5h6l4 4v10.5H5zM11 2.5v4h4M10 9v5m-2.5-2.5h5" stroke="currentColor" strokeWidth="1.4" strokeLinecap="round" strokeLinejoin="round" /></svg>
                    <span>{file ? file.name : 'Add a File'}</span>
                </button>
                {fieldErrors.file && <small className="admin-add-user-field-error">{fieldErrors.file[0]}</small>}
            </div>
            <div className="admin-add-user-field">
                <label htmlFor="admin-import-students-program">Program<span>*</span></label>
                <ModalSelect id="admin-import-students-program" value={program} onChange={event => { const value = event.target.value; setProgram(value); if (value === 'ACT' && Number(yearLevel) > 2) setYearLevel(''); setFieldErrors(current => ({ ...current, program: undefined })) }} aria-invalid={Boolean(fieldErrors.program)}>
                    <option value="">Select Program</option>
                    {programs.map(item => <option key={item.id} value={item.code}>{item.code}</option>)}
                </ModalSelect>
                {fieldErrors.program && <small className="admin-add-user-field-error">{fieldErrors.program[0]}</small>}
            </div>
            <div className="admin-add-user-field">
                <label htmlFor="admin-import-students-year">Year Level<span>*</span></label>
                <ModalSelect id="admin-import-students-year" value={yearLevel} onChange={event => { setYearLevel(event.target.value); setFieldErrors(current => ({ ...current, year_level: undefined })) }} aria-invalid={Boolean(fieldErrors.year_level)}>
                    <option value="">Select Year Level</option>
                    {(program === 'ACT' ? years.slice(0, 2) : years).map(year => <option key={year.value} value={year.value}>{year.label}</option>)}
                </ModalSelect>
                {fieldErrors.year_level && <small className="admin-add-user-field-error">{fieldErrors.year_level[0]}</small>}
            </div>
            <div className="admin-add-user-status">
                <span>Student Status</span>
                <div><button type="button" role="switch" aria-checked={isActive} aria-label="Imported students active" className={`admin-add-user-switch${isActive ? ' active' : ''}`} onClick={() => setIsActive(current => !current)}><span /></button><span className={isActive ? 'active' : 'inactive'}>{isActive ? 'Active' : 'Inactive'}</span></div>
            </div>
            {submitError && <p className="admin-add-user-submit-error" role="alert">{submitError}</p>}
            <button className="admin-add-user-submit" type="submit" disabled={isSubmitting}>{isSubmitting ? 'Importing Students…' : 'Import Students'}</button>
            <div className="admin-add-user-note permissions"><span aria-hidden="true">i</span><p><strong>File Format</strong><br />Upload an .xlsx or .csv file with a Full Name column. All imported students receive the selected program, year level, and status.</p></div>
        </form>
    </dialog>
}

export default ImportStudentsModal
