import { useState } from 'react'
import { useNavigate } from 'react-router-dom'
import Sidebar from '../../components/Sidebar/Sidebar'
import PageDate from '../../components/PageDate/PageDate'
import Notification from '../../components/Notification/Notification'
import GenerateTemplate from './ImportComponents/modals/GenerateTemplate'
import ErrorModal from './ImportComponents/modals/ErrorModal'
import ImportAttendancePanel from './ImportComponents/panels/ImportAttendancePanel'
import PreviewImportPanel from './ImportComponents/panels/PreviewImportPanel'
import './Import.css'

const CheckIcon = () => (
    <svg width="26" height="26" viewBox="0 0 24 24" fill="none">
        <path d="M20 6L9 17l-5-5" stroke="currentColor" strokeWidth="2.5" strokeLinecap="round" strokeLinejoin="round" />
    </svg>
)

const getGatheringTypeFromText = text => {
    const match = text.match(/^\s*gathering\s*:\s*(.+?)\s*$/im)
    if (!match) return null

    const gathering = match[1].trim().toLowerCase()
    return {
        pm: 'pm',
        'prayer meeting': 'pm',
        ws: 'ws',
        'worship service': 'ws',
        tg: 'tg',
        thanksgiving: 'tg',
        special: 'special',
        'special gathering': 'special',
    }[gathering] || gathering
}

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
    const navigate = useNavigate()

    const [batchMode, setBatchMode] = useState(false)

    // modal: null | 'generate' | 'success' | 'error'
    const [modal, setModal] = useState(null)
    const [modalMessage, setModalMessage] = useState('')
    const [parseErrors, setParseErrors] = useState(null)
    const [previewText, setPreviewText] = useState('')
    const [previewCount, setPreviewCount] = useState(0)
    const parsedGatheringType = getGatheringTypeFromText(form.raw_text)
    const gatheringTypeMismatch = parsedGatheringType && parsedGatheringType !== form.gathering_type

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

    const isWeekMode = form.gathering_type === 'week'

    const generateTemplate = async () => {
        setIsGenerating(true)
        try {
            const query = new URLSearchParams({
                program: form.program,
                year_level: form.year_level,
                gathering_type: form.gathering_type, // 'week' now flows straight through
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
        event?.preventDefault?.()
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
            return { ...nestedErrors, ...directErrors }
        }

        setFieldWarning(null)
        setIsProcessing(true)
        setResult(null)
        try {
            const endpoint = isWeekMode ? '/attendance/parse-batch-text' : '/attendance/parse-text'
            const payload = isWeekMode ? { raw_text: form.raw_text } : form

            const data = await request(endpoint, { method: 'POST', body: JSON.stringify(payload) })

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

                <div className="import-content">
                    <ImportAttendancePanel
                        form={form}
                        updateField={updateField}

                        gatheringTypeMismatch={gatheringTypeMismatch}
                        fieldWarning={fieldWarning}
                        isGenerating={isGenerating}
                        isProcessing={isProcessing}

                        onOpenGenerateModal={openGenerateModal}
                        onClear={clearForm}
                        onProcess={processAttendance}
                        
                        preview={isWeekMode ? <PreviewImportPanel rawText={form.raw_text} /> : null}
                    />
                </div>
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
                                onClick={() => { setModal(null); onGoToAttendance(); navigate('/attendance');}}
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