import { useState } from 'react'
import DisabledImportButton from '../components/DisabledImportButton'
import './ImportAttendancePanel.css'

function ImportAttendancePanel({
    form,
    updateField,
    gatheringTypeMismatch,
    fieldWarning,
    isGenerating,
    isProcessing,
    onOpenGenerateModal,
    onClear,
    onProcess,
    preview = null,
    previewRowCount = 0,
    isPreviewing = false,
    onGeneratePreview = () => {},
    onBackToEdit = () => {},
}) {
    const [isGatheringOpen, setIsGatheringOpen] = useState(false)

    return (
        <section className="import-panel">
            <div className="import-panel-heading">
                <div>
                    <h2>Import Attendance</h2>
                </div>
                <label data-import-guide="gathering" className={`import-gathering-select ${isGatheringOpen ? 'open' : ''}`} title={isPreviewing ? 'Go back to raw text to change gathering.' : undefined}>
                    <select
                        aria-label="Gathering type"
                        name="gathering_type"
                        value={form.gathering_type}
                        onChange={(event) => { updateField(event); setIsGatheringOpen(false) }}
                        onPointerDown={() => setIsGatheringOpen((open) => !open)}
                        onKeyDown={(event) => {
                            if (event.key === 'Escape') setIsGatheringOpen(false)
                            else if (['Enter', ' ', 'ArrowDown', 'ArrowUp'].includes(event.key)) setIsGatheringOpen(true)
                        }}
                        onBlur={() => setIsGatheringOpen(false)}
                        disabled={isPreviewing}
                        className={`import-native-select ${gatheringTypeMismatch ? 'import-gathering-mismatch' : ''}`}
                        aria-invalid={gatheringTypeMismatch || undefined}
                    >
                        <option value="week">All Gathering</option>
                        <option value="pm">Prayer Meeting</option>
                        <option value="ws">Worship Service</option>
                        <option value="tg">Thanksgiving</option>
                    </select>
                    <svg className="import-gathering-chevron" width="9" height="6" viewBox="0 0 12 8" fill="none" aria-hidden="true">
                        <path d="m1 1 5 5 5-5" stroke="currentColor" strokeWidth="1.5" strokeLinecap="round" strokeLinejoin="round" />
                    </svg>
                </label>
            </div>

            {fieldWarning && <p className="import-field-warning">{fieldWarning}</p>}

            <div className={`import-columns ${isPreviewing ? 'is-previewing' : ''}`}>
                <div className="import-column" data-import-guide-area="attendance-text">
                    <p className="import-column-label">Attendance Text</p>
                    <textarea
                        name="raw_text"
                        value={form.raw_text}
                        onChange={updateField}
                        readOnly={isPreviewing}
                        placeholder="Paste your attendance here . . ."
                        rows="12"
                    />

                    <div className="import-column-actions-row">
                        <DisabledImportButton className="import-clear-button" type="button" onClick={onClear} disabled={isPreviewing} disabledReason="Go back to raw text first." hintClassName="import-hint-clear">
                            Clear
                        </DisabledImportButton>
                        <DisabledImportButton                           
                            className="import-secondary-button"
                            data-import-guide="prepare" 
                            type="button"
                            onClick={onOpenGenerateModal}
                            disabled={isGenerating || isProcessing || isPreviewing}
                            disabledReason={isGenerating || isProcessing ? '' : 'Go back to raw text first.'}
                            hintClassName="import-hint-generate"
                        >
                            {isGenerating ? 'Generating Format...' : 'Generate Format'}
                        </DisabledImportButton>
                    </div>

                    <DisabledImportButton
                        type="button"
                        className="import-parse-preview-btn" data-import-guide="preview"
                        onClick={isPreviewing ? onBackToEdit : onGeneratePreview}
                        disabled={!isPreviewing && (gatheringTypeMismatch || isProcessing)}
                        disabledReason={isProcessing ? '' : 'Match the gathering header and dropdown.'}
                        hintClassName="import-hint-full"
                    >
                        {isPreviewing ? 'Back to Raw Text' : isProcessing ? 'Checking for duplicates...' : 'Parse and See Preview'}
                    </DisabledImportButton>
                </div>

                <div className="import-column" data-import-guide="review">
                    <p className="import-column-label">Preview</p>
                    {preview ?? <div className="import-preview-placeholder" />}

                    <DisabledImportButton
                        className="import-primary-button import-process-btn" data-import-guide="save"
                        type="button"
                        onClick={onProcess}
                        disabled={isGenerating || isProcessing || !isPreviewing || previewRowCount < 1}
                        disabledReason={isGenerating || isProcessing ? '' : !isPreviewing ? 'Parse and preview your attendance first.' : 'Add at least one student row.'}
                        hintClassName="import-hint-process"
                    >
                        {isProcessing ? 'Processing...' : 'Process Attendance'}
                    </DisabledImportButton>
                </div>
            </div>
        </section>
    )
}

export default ImportAttendancePanel
