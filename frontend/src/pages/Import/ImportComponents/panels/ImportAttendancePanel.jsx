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
    isPreviewing = false,
    onGeneratePreview = () => {},
    onBackToEdit = () => {},
}) {
    return (
        <section className="import-panel">
            <div className="import-panel-heading">
                <div>
                    <h2>Import Attendance</h2>
                </div>
                <label>
                    <select
                        name="gathering_type"
                        value={form.gathering_type}
                        onChange={updateField}
                        disabled={isPreviewing}
                        className={gatheringTypeMismatch ? 'import-gathering-mismatch' : ''}
                        aria-invalid={gatheringTypeMismatch || undefined}
                    >
                        <option value="pm">Prayer Meeting</option>
                        <option value="ws">Worship Service</option>
                        <option value="tg">Thanksgiving</option>
                        <option value="week">Whole Week Attendance</option>
                    </select>
                </label>
            </div>

            {fieldWarning && <p className="import-field-warning">{fieldWarning}</p>}

            <div className="import-columns">
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
                        disabled={!isPreviewing && gatheringTypeMismatch}
                    >
                        {isPreviewing ? 'Back to Raw Text' : 'Parse and See Preview'}
                    </button>
                </div>

                <div className="import-column">
                    <p className="import-column-label">Preview</p>
                    {preview ?? <div className="import-preview-placeholder" />}

                    <button
                        className="import-primary-button import-process-btn"
                        type="button"
                        onClick={onProcess}
                        disabled={isGenerating || isProcessing || !isPreviewing}
                    >
                        {isProcessing ? 'Processing...' : 'Process Attendance'}
                    </button>
                </div>
            </div>
        </section>
    )
}

export default ImportAttendancePanel