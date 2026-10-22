import { useState } from 'react'
import { useEffect } from 'react'
import { useNavigate } from 'react-router-dom'
import { useLocation } from 'react-router-dom'
import { request } from '../../../api/request'

//Reusables
import Sidebar from '../../../components/layout/Sidebar'
import Header from '../../../components/layout/Header'
import GenerateTemplate from './modals/GenerateTemplate'

// Modals
import ConfirmBlankModal from './modals/ConfirmBlankModal'
import ConfirmOverrideModal from './modals/ConfirmOverrideModal'
import SelectAttendanceWeekModal from './modals/SelectAttendanceWeekModal'
import ErrorModal from './modals/ErrorModal'
import SuccessModal from './modals/SuccessModal'

//Panels
import ImportAttendancePanel from './panels/ImportAttendancePanel'
import PreviewImportPanel from './panels/PreviewImportPanel'
import PreviewIndividualPanel from './panels/PreviewIndividualPanel'
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
    }[gathering] || gathering
}

function Import({ onGoToAttendance = () => {} }) {
    const location = useLocation()
    const loggedInUser = JSON.parse(localStorage.getItem('user') || '{}')
    const [form, setForm] = useState({
        program: loggedInUser.program || '',
        year_level: loggedInUser.year_level || '',
        gathering_type: location.state?.gathering_type || 'pm',
        raw_text: '',
    })
    const [fieldWarning, setFieldWarning] = useState(null)
    const [isGenerating, setIsGenerating] = useState(false)
    const [isProcessing, setIsProcessing] = useState(false)
    const [result, setResult] = useState(null)
    const navigate = useNavigate()

    const [batchMode, setBatchMode] = useState(false)

    // modal: null | 'generate' | 'success' | 'error'
    const [modal, setModal] = useState('select-week')
    const [modalMessage, setModalMessage] = useState('')
    const [parseErrors, setParseErrors] = useState(null)
    const [previewText, setPreviewText] = useState('')
    const [previewCount, setPreviewCount] = useState(0)
    const [previewRowCount, setPreviewRowCount] = useState(0)
    const [templateError, setTemplateError] = useState('')
    const [periods, setPeriods] = useState(null)
    const [isLoadingPeriods, setIsLoadingPeriods] = useState(true)
    const [weekPeriod, setWeekPeriod] = useState(null)

    useEffect(() => {
        let active = true
        request('/attendance/import-periods')
            .then(data => { if (active) setPeriods(data.periods) })
            .catch(error => {
                if (!active) return
                setModalMessage(error.message)
                setParseErrors(error.details || null)
                setModal('error')
            })
            .finally(() => { if (active) setIsLoadingPeriods(false) })
        return () => { active = false }
    }, [])

    const updateField = event => {
        const { name, value } = event.target
        setForm(current => ({ ...current, [name]: value }))
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
        setTemplateError('')
        setModal('generate')
    }

    const isWeekMode = form.gathering_type === 'week'
    const parsedGatheringType = getGatheringTypeFromText(form.raw_text)
    const gatheringTypeMismatch = !isWeekMode && parsedGatheringType && parsedGatheringType !== form.gathering_type

    const generateTemplate = async () => {
        setIsGenerating(true)
        try {
            const query = new URLSearchParams({
                program: form.program,
                year_level: form.year_level,
                gathering_type: form.gathering_type, // 'week' now flows straight through
                week_period: weekPeriod || 'current',
            })
            const data = await request(`/attendance/generate-template?${query}`)
            setPreviewText(data.template)
            setPreviewCount(data.count)
            setTemplateError('')
        } catch (error) {
            if (error.details?.template === null && /no active students/i.test(error.details?.message || error.message)) {
                setPreviewText('')
                setPreviewCount(0)
                setTemplateError('There are no active students assigned to your program and year level. Ask an administrator to activate or add students, then try again.')
                return
            }
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

    const processAttendance = async (eventOrOpts) => {
        if (eventOrOpts?.preventDefault) {
            eventOrOpts.preventDefault()
        }
        const opts = (typeof eventOrOpts === 'object' && !eventOrOpts?.preventDefault) ? eventOrOpts : {}
        const isConfirmBlank = opts.confirm_blank !== undefined ? opts.confirm_blank : confirmBlankPending
        const isOverride = opts.override !== undefined ? opts.override : overridePending

        if (!form.program || !form.year_level || !form.raw_text.trim()) {
            setFieldWarning('Complete the program, year level, and attendance text.')
            return
        }

        const parseErrorKeys = ['unmatched_lines', 'out_of_course_students', 'missing_students', 'incomplete_records', 'format_errors', 'all_no_response_warning']
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
            const payload = isWeekMode
                ? { raw_text: form.raw_text, confirm_blank: isConfirmBlank, override: isOverride, week_period: weekPeriod }
                : { ...form, confirm_blank: isConfirmBlank, override: isOverride, week_period: weekPeriod }

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
            setOverridePending(false)
            setDuplicateSource(null)
        } catch (error) {
            const details = error.details || {}
            if (details.status === 'duplicate_detected' || (details.requires_confirmation && details.duplicate_records)) {
                setDuplicateData(details)
                setDuplicateSource('process')
                setModal('confirm-override')
            } else if (details.requires_confirmation) {
                setModalMessage(details.all_no_response_warning || error.message)
                setParseErrors(details)
                setModal('confirm-blank')
            } else {
                setModalMessage(error.message)
                setParseErrors(details)
                setModal('error')
            }
        } finally {
            setIsProcessing(false)
        }
    }


    //Previewing functions
    const [isPreviewing, setIsPreviewing] = useState(false)

    const handleGeneratePreview = async () => {
        if (!form.raw_text.trim()) {
            setFieldWarning('Paste some attendance text first.')
            return
        }
        if (gatheringTypeMismatch) {
            setFieldWarning(`The gathering type in your text doesn't match the selected dropdown ("${form.gathering_type.toUpperCase()}"). Fix one or the other before previewing.`)
            return
        }
        setFieldWarning(null)
        setPreviewRowCount(0)

        if (overridePending) {
            setDuplicateSource('preview')
            setIsPreviewing(true)
            return
        }

        setIsProcessing(true)
        try {
            await request('/attendance/check-duplicates', {
                method: 'POST',
                body: JSON.stringify({
                    raw_text: form.raw_text,
                    gathering_type: form.gathering_type,
                    week_period: weekPeriod,
                }),
            })
            setDuplicateSource('preview')
            setIsPreviewing(true)
        } catch (error) {
            const details = error.details || {}
            if (details.status === 'duplicate_detected') {
                setDuplicateData(details)
                setDuplicateSource('preview')
                setModal('confirm-override')
            } else {
                setModalMessage(error.message)
                setParseErrors(details)
                setModal('error')
            }
        } finally {
            setIsProcessing(false)
        }
    }

    const handleBackToEdit = () => {
        setIsPreviewing(false)
        setPreviewRowCount(0)
        setOverridePending(false)
    }

    const [confirmBlankPending, setConfirmBlankPending] = useState(false)
    const [overridePending, setOverridePending] = useState(false)
    const [duplicateData, setDuplicateData] = useState(null)
    const [duplicateSource, setDuplicateSource] = useState(null)

    const periodHasDuplicates = period => ['pm', 'ws', 'tg'].some(type => (period?.[type]?.confirmable_count || 0) > 0)
    const handleChoosePeriod = period => {
        setWeekPeriod(period)
        setOverridePending(false)
        if (periodHasDuplicates(periods?.[period])) {
            const entries = ['pm', 'ws', 'tg'].filter(type => (periods?.[period]?.[type]?.confirmable_count || 0) > 0)
            const range = periods?.[period]?.[entries[0]]
            setDuplicateData({
                gathering_type: entries.join('/'),
                duplicates_count: entries.reduce((sum, type) => sum + periods[period][type].confirmable_count, 0),
                week_range: { start: range?.start, end: range?.end, label: entries.map(type => `${type.toUpperCase()} ${periods[period][type].label}`).join('; ') },
            })
            setDuplicateSource('period-select')
            setModal('confirm-override')
            return
        }
        setModal(null)
    }

    const handleConfirmBlankProceed = () => {
        setConfirmBlankPending(true)
        setModal(null)
        processAttendance({ confirm_blank: true, override: overridePending })
    }

    const handleConfirmOverrideProceed = () => {
        if (duplicateSource === 'period-select') {
            setOverridePending(true)
            setDuplicateData(null)
            setDuplicateSource(null)
            setModal(null)
            return
        }
        if (duplicateSource === 'preview') {
            setOverridePending(true)
            setModal(null)
            setDuplicateData(null)
            setDuplicateSource(null)
            setIsPreviewing(true)
            return
        }

        setOverridePending(true)
        setModal(null)
        processAttendance({ override: true, confirm_blank: confirmBlankPending })
    }

    const handleCancelOverride = () => {
        const cameFromPeriodChoice = duplicateSource === 'period-select'
        setModal(cameFromPeriodChoice ? 'select-week' : null)
        setDuplicateData(null)
        setOverridePending(false)
        setDuplicateSource(null)
        if (cameFromPeriodChoice) setWeekPeriod(null)
    }

    const clearForm = () => {
        setForm(current => ({ ...current, raw_text: '' }))
        setResult(null)
        setFieldWarning(null)
        setIsPreviewing(false)
        setPreviewRowCount(0)
        setConfirmBlankPending(false)
        setOverridePending(false)
        setDuplicateData(null)
        setDuplicateSource(null)
    }

    return (
        <div className="import">
            <Sidebar />
            <main className="import-main">
                <Header
                    title="Import"
                    subtitle="Paste raw attendance logs to get a formatted spreadsheet."
                    className="import-header"
                />

                <div className="import-content">
                    {weekPeriod && periods?.[weekPeriod] && (
                        <section className="import-period-banner" aria-live="polite">
                            <div className="import-period-summary">
                                <svg className="import-period-calendar-icon" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                    <rect x="3" y="5" width="18" height="16" rx="2" stroke="currentColor" strokeWidth="1.6" />
                                    <path d="M7 3v4M17 3v4M3 9h18" stroke="currentColor" strokeWidth="1.6" strokeLinecap="round" />
                                </svg>
                                <div className="import-period-banner-heading">
                                    <span className="import-period-eyebrow">Your editing</span>
                                    <h2>{weekPeriod === 'last' ? "Last week's attendance record" : "This week's attendance record"}</h2>
                                </div>
                            </div>
                            <div className="import-period-cards">
                                {[
                                    ['pm', 'Prayer Meeting'],
                                    ['ws', 'Worship Service'],
                                    ['tg', 'Thanksgiving'],
                                ].map(([type, label]) => {
                                    const range = periods[weekPeriod][type]
                                    const submitted = range.submitted
                                    const upcoming = range.is_upcoming && !submitted
                                    const tooltip = submitted
                                        ? 'Attendance for this week cannot be edited anymore. Submitted attendance records are uneditable.'
                                        : 'Attendance for this gathering cannot be added yet because its scheduled date range has not begun.'
                                    return (
                                        <div
                                            key={type}
                                            className={`import-period-date-card ${submitted ? 'import-period-date-submitted' : upcoming ? 'import-period-date-upcoming' : ''}`}
                                            data-tooltip={submitted || upcoming ? tooltip : undefined}
                                            tabIndex={submitted || upcoming ? 0 : undefined}
                                            aria-label={`${label}: ${range.label}${submitted || upcoming ? `. ${tooltip}` : ''}`}
                                        >
                                            <div className="import-period-card-heading">{label}</div>
                                            <div className="import-period-card-date">{range.label}</div>
                                        </div>
                                    )
                                })}
                            </div>
                            <button className="import-period-change-button" type="button" onClick={() => setModal('select-week')}>
                                Change week
                            </button>
                        </section>
                    )}
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
                    isPreviewing={isPreviewing}
                    previewRowCount={previewRowCount}
                    onGeneratePreview={handleGeneratePreview}
                    onBackToEdit={handleBackToEdit}
                    preview={
                        isPreviewing
                            ? (isWeekMode
                                ? <PreviewImportPanel rawText={form.raw_text} errors={parseErrors} onRowsParsed={setPreviewRowCount} onChange={(t) => setForm((cur) => ({ ...cur, raw_text: t }))} />
                                : <PreviewIndividualPanel rawText={form.raw_text} errors={parseErrors} gatheringType={form.gathering_type} onRowsParsed={setPreviewRowCount} onChange={(t) => setForm((cur) => ({ ...cur, raw_text: t }))} />
                            )
                            : null
                    }
                />
                </div>
            </main>
            {modal === 'confirm-override' && (
                <ConfirmOverrideModal
                    duplicateData={duplicateData}
                    isSubmitting={isProcessing}
                    onCancel={handleCancelOverride}
                    onOverride={handleConfirmOverrideProceed}
                />
            )}
            {modal === 'select-week' && (
                <SelectAttendanceWeekModal
                    periods={periods}
                    loading={isLoadingPeriods}
                    onSelect={handleChoosePeriod}
                    onCancel={() => {
                        if (!weekPeriod) setWeekPeriod('current')
                        setModal(null)
                    }}
                />
            )}
            {modal === 'confirm-blank' && (
                <ConfirmBlankModal
                    modalMessage={modalMessage}
                    onClose={() => setModal(null)}
                    onConfirm={handleConfirmBlankProceed}
                />
            )}
            {modal === 'generate' && (
                <GenerateTemplate
                    form={form}
                    updateField={updateField}
                    previewText={previewText}
                    previewCount={previewCount}
                    templateError={templateError}
                    setModal={setModal}
                    resetPreview={() => {
                        setPreviewText('')
                        setPreviewCount(0)
                        setTemplateError('')
                    }}
                    isGenerating={isGenerating}
                    generateTemplate={generateTemplate}
                    applyGeneratedTemplate={applyGeneratedTemplate}
                />
            )}

            {modal === 'success' && result && (
                <SuccessModal
                    result={result}
                    onClose={() => { setModal(null); setIsPreviewing(false) }}
                    onGoToAttendance={() => { setModal(null); setIsPreviewing(false); onGoToAttendance(); navigate('/attendance') }}
                />
            )}

            {modal === 'error' && (
                <ErrorModal
                    errorMessage={modalMessage}
                    errorDetails={parseErrors}
                    attendanceLocked={parseErrors?.status === 'attendance_locked'}
                    onClose={() => setModal(null)}
                />
            )}
        </div>
    )
}

export default Import
