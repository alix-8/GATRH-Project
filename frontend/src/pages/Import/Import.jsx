import { useState } from 'react'
import Sidebar from '../../components/Sidebar/Sidebar'
import PageDate from '../../components/PageDate/PageDate'
import Notification from '../../components/Notification/Notification'
import GenerateTemplate from './modals/GenerateTemplate'
import ErrorModal from './modals/ErrorModal'
import './Import.css'

const CheckIcon = () => (
    <svg width="26" height="26" viewBox="0 0 24 24" fill="none">
        <path d="M20 6L9 17l-5-5" stroke="currentColor" strokeWidth="2.5" strokeLinecap="round" strokeLinejoin="round" />
    </svg>
)

const WarningIcon = () => (
    <svg width="24" height="24" viewBox="0 0 24 24" fill="none">
        <path d="M12 3l10 18H2L12 3z" stroke="currentColor" strokeWidth="2" strokeLinejoin="round" />
        <path d="M12 10v4" stroke="currentColor" strokeWidth="2" strokeLinecap="round" />
        <circle cx="12" cy="17" r="0.5" fill="currentColor" stroke="currentColor" />
    </svg>
)

function Import({ onGoToAttendance = () => {} }) {
    const loggedInUser = JSON.parse(localStorage.getItem('user') || '{}')
    const [form, setForm] = useState({
        program: loggedInUser.program || '',
        year_level: loggedInUser.year_level || '',
        gathering_type: 'pm',
        raw_text: '',
    })
    const [fieldWarning, setFieldWarning] = useState(null)
    const [isGenerating, setIsGenerating] = useState(false)
    const [isProcessing, setIsProcessing] = useState(false)
    const [result, setResult] = useState(null)

    // modal: null | 'generate' | 'success' | 'error'
    const [modal, setModal] = useState(null)
    const [modalMessage, setModalMessage] = useState('')
    const [parseErrors, setParseErrors] = useState(null)
    const [previewText, setPreviewText] = useState('')
    const [previewCount, setPreviewCount] = useState(0)

    const updateField = event => {
        const { name, value } = event.target
        setForm(current => ({ ...current, [name]: value }))
    }

    const request = async (url, options = {}) => {
        const token = localStorage.getItem('auth_token')
        const response = await fetch(`http://127.0.0.1:8000/api${url}`, {
            ...options,
            headers: {
                Accept: 'application/json',
                ...(options.body ? { 'Content-Type': 'application/json' } : {}),
                ...(token ? { Authorization: `Bearer ${token}` } : {}),
                ...options.headers,
            },
        })

        const data = await response.json().catch(() => ({}))
        if (!response.ok) {
            const error = new Error(data.message || Object.values(data.errors || {}).flat()[0] || 'The request failed.')
            error.details = data
            throw error
        }
        return data
    }

    const openGenerateModal = () => {
        if (!form.program || !form.year_level) {
            setFieldWarning('Enter a program and year level first.')
            return
        }

        setFieldWarning(null)
        setParseErrors(null)
        setPreviewText('')
        setPreviewCount(0)
        setModal('generate')
    }

    const generateTemplate = async () => {
        setIsGenerating(true)
        try {
            const query = new URLSearchParams({
                program: form.program,
                year_level: form.year_level,
                gathering_type: form.gathering_type,
            })
            const data = await request(`/attendance/generate-template?${query}`)
            setPreviewText(data.template)
            setPreviewCount(data.count)
        } catch (error) {
            setModalMessage(error.message)
            setParseErrors(null)
            setModal('error')
        } finally {
            setIsGenerating(false)
        }
    }

    const applyGeneratedTemplate = () => {
        setForm(current => ({ ...current, raw_text: previewText }))
        setModal(null)
    }

    const processAttendance = async event => {
        event.preventDefault()
        if (!form.program || !form.year_level || !form.raw_text.trim()) {
            setFieldWarning('Complete the program, year level, and attendance text.')
            return
        }

        const parseErrorKeys = ['unmatched_lines', 'missing_students', 'incomplete_records', 'format_errors', 'all_no_response_warning']
        const getStructuredParseErrors = payload => {
            const nestedErrors = payload?.errors && typeof payload.errors === 'object' ? payload.errors : {}
            const directErrors = parseErrorKeys.reduce((errors, key) => {
                if ((Array.isArray(payload?.[key]) && payload[key].length > 0) || (typeof payload?.[key] === 'string' && payload[key])) {
                    errors[key] = payload[key]
                }
                return errors
            }, {})

            return {
                ...nestedErrors,
                ...directErrors,
            }
        }

        setFieldWarning(null)
        setIsProcessing(true)
        setResult(null)
        try {
            const data = await request('/attendance/parse-text', {
                method: 'POST',
                body: JSON.stringify(form),
            })

            const responseErrors = getStructuredParseErrors(data)
            const hasValidationFailure = Object.keys(responseErrors).length > 0 || /contains errors|correct them before saving|invalid attendance/i.test(data.message || '')

            if (hasValidationFailure) {
                const error = new Error(data.message || 'Attendance text contains errors.')
                error.details = { ...data, ...responseErrors }
                throw error
            }

            setResult(data)
            setModal('success')
        } catch (error) {
            setModalMessage(error.message)
            setParseErrors(error.details || null)
            setModal('error')
        } finally {
            setIsProcessing(false)
        }
    }

    const clearForm = () => {
        setForm(current => ({ ...current, raw_text: '' }))
        setResult(null)
        setFieldWarning(null)
    }

    return (
        <div className="import">
            <Sidebar />
            <main className="import-main">
                <header className="import-header page-header">
                    <div>
                        <h3>Import</h3>
                        <p>Paste raw attendance logs to get a formatted spreadsheet.</p>
                    </div>
                    <div className="import-header-meta">
                        <PageDate />
                        <Notification />
                    </div>
                </header>

                <form className="import-content" onSubmit={processAttendance}>
                    <section className="import-panel">
                        <div className="import-panel-heading">
                            <div>
                                <h2>Import Attendance</h2>
                            </div>
                        </div>

                        {/* <div className="import-fields">
                            <label>
                                Gathering
                                <select name="gathering_type" value={form.gathering_type} onChange={updateField}>
                                    <option value="pm">Prayer Meeting</option>
                                    <option value="ws">Worship Service</option>
                                    <option value="tg">Thanksgiving</option>
                                    <option value="special">Special</option>
                                </select>
                            </label>
                        </div> */}

                        <label className="import-textarea-label">
                            <p className="import-textarea-label-title">
                                Attendance Text
                                <label className="import-modal-label">
                                    Gathering
                                    {/* <select name="gathering_type" placeholder="Gathering" value={form.gathering_type} onChange={handleGatheringChange}>
                                        <option value="pm">Prayer Meeting</option>
                                        <option value="ws">Worship Service</option>
                                        <option value="tg">Thanksgiving</option>
                                    </select> */}
                                </label>
                            </p>
                            <textarea name="raw_text" value={form.raw_text} onChange={updateField} placeholder="Paste your attendance text here..." rows="12" />
                        </label>

                        {fieldWarning && <p className="import-field-warning">{fieldWarning}</p>}

                        <div className="import-actions">
                            <div className="import-actions-left">
                                <button className="import-secondary-button" type="button" onClick={openGenerateModal} disabled={isGenerating || isProcessing}>
                                    {isGenerating ? 'Generating Format...' : 'Generate Format'}
                                </button>
                            </div>
                            <div className="import-actions-right">
                                <button className="import-clear-button" type="button" onClick={clearForm}>
                                    Clear
                                </button>
                            </div>
                        </div>
                        <div className="process-action">
                            <button className="import-primary-button" type="button" onClick={processAttendance} disabled={isGenerating || isProcessing}>
                                {isProcessing ? 'Processing...' : 'Process Attendance'}
                            </button>
                        </div>
                    </section>
                </form>
            </main>

            {modal === 'generate' && (
                <GenerateTemplate
                    form={form}
                    updateField={updateField}
                    previewText={previewText}
                    previewCount={previewCount}
                    setModal={setModal}
                    resetPreview={() => {
                        setPreviewText('')
                        setPreviewCount(0)
                    }}
                    isGenerating={isGenerating}
                    generateTemplate={generateTemplate}
                    applyGeneratedTemplate={applyGeneratedTemplate}
                />
            )}

            {modal === 'success' && result && (
                <div className="import-modal-overlay">
                    <div className="import-modal import-modal-centered">
                        <button className="import-modal-close" type="button" onClick={() => setModal(null)}>&times;</button>
                        <div className="import-status-icon import-status-icon-success">
                            <CheckIcon />
                        </div>
                        <h2 className="import-modal-title">Attendance Processed</h2>
                        <p className="import-modal-subtext">
                            The attendance log was imported successfully and is ready for review.
                        </p>

                        <div className="import-modal-summary import-modal-summary-success">
                            <p className="import-modal-summary-title">
                                <CheckIcon /> Import completed
                            </p>
                            <p>Draft #{result.draft_id} &middot; {result.summary.total_students} students detected</p>
                            <p>{result.summary.present_count} present, {result.summary.no_response_count} no response
                                {result.summary.unmatched_count > 0 && <>, {result.summary.unmatched_count} unmatched</>}
                            </p>
                        </div>

                        <div className="import-modal-buttons">
                            <button className="import-clear-button" type="button" onClick={() => setModal(null)}>
                                Close
                            </button>
                            <button
                                className="import-primary-button"
                                type="button"
                                onClick={() => { setModal(null); onGoToAttendance() }}
                            >
                                Go to Attendance
                            </button>
                        </div>
                    </div>
                </div>
            )}

            {modal === 'error' && (
                <ErrorModal
                    errorMessage={modalMessage}
                    errorDetails={parseErrors}
                    onClose={() => setModal(null)}
                />
            )}
        </div>
    )
}

export default Import