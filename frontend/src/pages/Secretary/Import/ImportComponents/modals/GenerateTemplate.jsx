
import { useState } from 'react'

function GenerateTemplate({
    form,
    updateField,
    previewText,
    previewCount,
    setModal,
    resetPreview,
    isGenerating,
    generateTemplate,
    applyGeneratedTemplate,
}) {
    const [isCopied, setIsCopied] = useState(false)

    const handleGatheringChange = event => {
        updateField(event)
        resetPreview()
        setIsCopied(false)
    }

    const copyPreview = async () => {
        await navigator.clipboard.writeText(previewText)
        setIsCopied(true)
        window.setTimeout(() => setIsCopied(false), 1500)
    }

    return (
        <div className="import-modal-overlay">
            <div className="import-modal">
                <button className="import-modal-close" type="button" onClick={() => setModal(null)}>&times;</button>
                <h2 className="import-modal-title">Generate Format</h2>

                <label className="import-modal-label">
                    Gathering
                    <select className="import-native-select" name="gathering_type" placeholder="Gathering" value={form.gathering_type} onChange={handleGatheringChange}>
                        <option value="pm">Prayer Meeting</option>
                        <option value="ws">Worship Service</option>
                        <option value="tg">Thanksgiving</option>
                        <option value="week">Whole Week Attendance</option>
                    </select>
                </label>

                <div className="import-modal-preview-header">
                    <div className="import-modal-preview-label">Format Preview</div>
                    <button className="import-copy-button" type="button" onClick={copyPreview} disabled={!previewText || isGenerating}>
                        {isCopied ? 'Copied' : 'Copy'}
                    </button>
                </div>

                <div className="import-modal-preview">
                    <pre>{previewText}</pre>
                </div>

                <p className="import-modal-count">{previewCount} student names found for this program and year level.</p>

                <div className="import-modal-buttons">
                    <button className="import-clear-button" type="button" onClick={() => setModal(null)}>
                        Close
                    </button>
                    <button
                        className="import-primary-button"
                        type="button"
                        onClick={previewText ? applyGeneratedTemplate : generateTemplate}
                        disabled={isGenerating}
                    >
                        {isGenerating ? 'Generating...' : previewText ? 'Use Format' : 'Generate'}
                    </button>
                </div>
            </div>
        </div>
 )
}
 
export default GenerateTemplate;
