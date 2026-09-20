import './ImportAttendancePanel.css'

/**
 * The "Import Attendance" card: gathering-type selector, the raw-text textarea,
 * and the Generate/Clear/Process actions.
 *
 * `preview` lets the parent page slot in a Preview component later without
 * this file needing to change — if omitted, a plain placeholder box is shown
 * so the two-column layout still looks right on its own.
 */
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
    batchMode = false,
    onToggleBatchMode = () => {},
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

            <div className="import-columns">
                <label className="import-column">
                    <p className="import-column-label">Attendance Text</p>
                    <textarea
                        name="raw_text"
                        value={form.raw_text}
                        onChange={updateField}
                        placeholder="Paste your attendance here . . ."
                        rows="12"
                    />
                </label>

                <div className="import-column">
                    <p className="import-column-label">Preview</p>
                    {preview ?? <div className="import-preview-placeholder" />}
                </div>
            </div>

            {fieldWarning && <p className="import-field-warning">{fieldWarning}</p>}

            <div className="import-actions">
                <div className="import-actions-left">
                    <button className="import-clear-button" type="button" onClick={onClear}>
                        Clear
                    </button>
                </div>
                <div className="import-actions-right">
                    <button
                        className="import-secondary-button"
                        type="button"
                        onClick={onOpenGenerateModal}
                        disabled={isGenerating || isProcessing}
                    >
                        {isGenerating ? 'Generating Format...' : 'Generate Format'}
                    </button>
                </div>
            </div>

            <div className="process-action">
                <button className="import-primary-button" type="button" onClick={onProcess} disabled={isGenerating || isProcessing}>
                    {isProcessing ? 'Processing...' : 'Process Attendance'}
                </button>
            </div>
        </section>
    )
}

export default ImportAttendancePanel