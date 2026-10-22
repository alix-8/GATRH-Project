
import { useEffect, useState } from 'react'

function GenerateTemplate({
    form,
    previewText,
    previewCount,
    templateError,
    setModal,
    resetPreview,
    isGenerating,
    generateTemplate,
    cancelGenerateTemplate,
    applyGeneratedTemplate,
}) {
    const [isCopied, setIsCopied] = useState(false)
    const [gatheringType, setGatheringType] = useState(() => form.gathering_type === 'week' ? 'pm' : form.gathering_type)
    const [isGatheringOpen, setIsGatheringOpen] = useState(false)

    useEffect(() => {
        generateTemplate(gatheringType)
        return cancelGenerateTemplate
    }, [gatheringType, generateTemplate, cancelGenerateTemplate])

    const handleGatheringChange = event => {
        setGatheringType(event.target.value)
        setIsGatheringOpen(false)
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
            <div className="import-modal import-format-modal" role="dialog" aria-modal="true" aria-labelledby="import-format-title">
                <button className="import-modal-close" type="button" onClick={() => setModal(null)} aria-label="Close Generate Format">&times;</button>
                <h2 className="import-modal-title" id="import-format-title">Generate Format</h2>

                <label className="import-modal-label">
                    Gathering
                    <span className={`import-format-select ${isGatheringOpen ? 'open' : ''}`}>
                        <select
                            className="import-native-select"
                            name="gathering_type"
                            value={gatheringType}
                            onChange={handleGatheringChange}
                            onPointerDown={() => setIsGatheringOpen((open) => !open)}
                            onKeyDown={(event) => {
                                if (event.key === 'Escape') setIsGatheringOpen(false)
                                else if (['Enter', ' ', 'ArrowDown', 'ArrowUp'].includes(event.key)) setIsGatheringOpen(true)
                            }}
                            onBlur={() => setIsGatheringOpen(false)}
                        >
                            <option value="pm">Prayer Meeting</option>
                            <option value="ws">Worship Service</option>
                            <option value="tg">Thanksgiving</option>
                        </select>
                        <svg className="import-format-chevron" width="9" height="6" viewBox="0 0 12 8" fill="none" aria-hidden="true">
                            <path d="m1 1 5 5 5-5" stroke="currentColor" strokeWidth="1.5" strokeLinecap="round" strokeLinejoin="round" />
                        </svg>
                    </span>
                </label>

                <div className="import-modal-preview-header">
                    <div className="import-modal-preview-label">Format Preview</div>
                    <button className="import-copy-button" type="button" onClick={copyPreview} disabled={!previewText || isGenerating} aria-label={isCopied ? 'Copied format preview' : 'Copy format preview'} title={isCopied ? 'Copied' : 'Copy preview'}>
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                            <path d="M8 5h9a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2V7a2 2 0 0 1 2-2Z" stroke="currentColor" strokeWidth="1.5" />
                            <path d="M16 5V4a2 2 0 0 0-2-2H5a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h1" stroke="currentColor" strokeWidth="1.5" />
                        </svg>
                    </button>
                </div>

                <div className="import-modal-preview">
                    {isGenerating ? (
                        <p className="import-template-loading" role="status">Loading format preview…</p>
                    ) : templateError ? (
                        <div className="import-template-empty-state" role="status">
                            <strong>{templateError.startsWith('There are no active students') ? 'No active students found' : 'Unable to load format preview'}</strong>
                            <p>{templateError}</p>
                        </div>
                    ) : (
                        <pre>{previewText}</pre>
                    )}
                </div>

                <p className="import-modal-count">
                    {isGenerating ? 'Loading student names…' : templateError ? 'Preview unavailable.' : `${previewCount} student names found for this program and year level.`}
                </p>

                <div className="import-modal-buttons">
                    <button className="import-clear-button" type="button" onClick={() => setModal(null)}>
                        Cancel
                    </button>
                    <button
                        className="import-primary-button"
                        type="button"
                        onClick={() => previewText ? applyGeneratedTemplate(gatheringType) : generateTemplate(gatheringType)}
                        disabled={isGenerating}
                    >
                        {isGenerating ? 'Loading...' : 'Generate'}
                    </button>
                </div>
            </div>
        </div>
 )
}
 
export default GenerateTemplate;
