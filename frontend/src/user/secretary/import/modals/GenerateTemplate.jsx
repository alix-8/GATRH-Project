import DisabledImportButton from '../components/DisabledImportButton'

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
    onGatheringChange,
}) {
    const [isCopied, setIsCopied] = useState(false)
    const [isGatheringOpen, setIsGatheringOpen] = useState(false)
    const gatheringType = form.gathering_type || 'week'

    useEffect(() => {
        generateTemplate(gatheringType)
        return cancelGenerateTemplate
    }, [gatheringType, generateTemplate, cancelGenerateTemplate])

    const handleGatheringChange = event => {
        const nextGatheringType = event.target.value
        onGatheringChange?.(nextGatheringType)
        setIsGatheringOpen(false)
        resetPreview()
        setIsCopied(false)
    }

    const copyPreview = async () => {
        await navigator.clipboard.writeText(previewText)
        setIsCopied(true)
        window.setTimeout(() => setIsCopied(false), 2000)
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
                            data-import-guide="format-gathering"
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
                            <option value="week">All gathering</option>
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
                    <DisabledImportButton
                        className={`import-copy-button ${previewText && !isGenerating && !isCopied ? 'is-ready' : ''} ${isCopied ? 'is-copied' : ''}`} 
                        data-import-guide="copy"
                        type="button"
                        onClick={copyPreview}
                        disabled={!previewText || isGenerating}
                        disabledReason={isGenerating ? '' : 'Generate a format first.'}
                        aria-label={isCopied ? 'Copied format preview' : 'Copy format preview'}
                        title={!previewText || isGenerating ? undefined : isCopied ? 'Copied' : 'Copy preview'}
                    >
                        {isCopied ? (
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                <path d="m5 12 4 4L19 6" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" />
                            </svg>
                        ) : (
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                            <path d="M8 5h9a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2V7a2 2 0 0 1 2-2Z" fill="currentColor" />
                            <path d="M16 5V2l3 3h-2a1 1 0 0 1-1-1Z" fill="#fff" />
                            <path d="M6 18H5a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9" stroke="currentColor" strokeWidth="1.5" strokeLinecap="round" strokeLinejoin="round" />
                        </svg>
                        )}
                    </DisabledImportButton>
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

                <div className="import-modal-buttons" data-import-guide="format">
                    <button className="import-clear-button" type="button" onClick={() => setModal(null)}>
                        Cancel
                    </button>
                    <DisabledImportButton
                        className="import-primary-button"
                        type="button"
                        onClick={() => previewText ? applyGeneratedTemplate(gatheringType) : generateTemplate(gatheringType)}
                        disabled={isGenerating}
                    >
                        {isGenerating ? 'Loading...' : previewText ? 'Use Format' : 'Generate'}
                    </DisabledImportButton>
                </div>
            </div>
        </div>
 )
}
 
export default GenerateTemplate;
