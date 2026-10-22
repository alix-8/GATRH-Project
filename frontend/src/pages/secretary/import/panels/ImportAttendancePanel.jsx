import { useState } from 'react'
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
                <label className={`import-gathering-select ${isGatheringOpen ? 'open' : ''}`}>
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
                <div className="import-column">
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
                        <button className="import-clear-button" type="button" onClick={onClear} disabled={isPreviewing}>
                            Clear
                        </button>
                        <button
                            className="import-secondary-button"
                            type="button"
                            onClick={onOpenGenerateModal}
                            disabled={isGenerating || isProcessing || isPreviewing}
                        >
                            {isGenerating ? 'Generating Format...' : 'Generate Format'}
                        </button>
                    </div>

                    <button
                        type="button"
                        className="import-parse-preview-btn"
                        onClick={isPreviewing ? onBackToEdit : onGeneratePreview}
                        disabled={!isPreviewing && (gatheringTypeMismatch || isProcessing)}
                    >
                        {isPreviewing ? 'Back to Raw Text' : isProcessing ? 'Checking for duplicates...' : 'Parse and See Preview'}
                    </button>
                </div>

                <div className="import-column">
                    <p className="import-column-label">Preview</p>
                    {preview ?? <div className="import-preview-placeholder" />}

                    <button
                        className="import-primary-button import-process-btn"
                        type="button"
                        onClick={onProcess}
                        disabled={isGenerating || isProcessing || !isPreviewing || previewRowCount < 1}
                    >
                        {isProcessing ? 'Processing...' : 'Process Attendance'}
                    </button>
                </div>
            </div>
        </section>
    )
}

export default ImportAttendancePanel
