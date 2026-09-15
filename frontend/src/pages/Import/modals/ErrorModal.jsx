import { useState } from 'react'

const WarningIcon = () => (
    <svg width="24" height="24" viewBox="0 0 24 24" fill="none">
        <path d="M12 3l10 18H2L12 3z" stroke="currentColor" strokeWidth="2" strokeLinejoin="round" />
        <path d="M12 10v4" stroke="currentColor" strokeWidth="2" strokeLinecap="round" />
        <circle cx="12" cy="17" r="0.5" fill="currentColor" stroke="currentColor" />
    </svg>
)

const errorLabels = {
    unmatched_lines: ['Unmatched Lines', 'Student names could not be matched'],
    missing_students: ['Missing Students', 'Students were not included in the attendance text'],
    incomplete_records: ['Incomplete Records', 'Required attendance information is missing'],
    format_errors: ['Format Errors', 'Attendance lines contain invalid formatting'],
    all_no_response_warning: ['No Response Warning', 'Every student has blank attendance fields'],
}

const getErrorItems = (errorDetails = {}) => {
    return [
        ['unmatched_lines', errorDetails.unmatched_lines || []],
        ['missing_students', errorDetails.missing_students || []],
        ['incomplete_records', errorDetails.incomplete_records || []],
        ['format_errors', errorDetails.format_errors || []],
        ['all_no_response_warning', errorDetails.all_no_response_warning || ''],
    ].filter(([, items]) => items.length > 0)
}

//icon for each error type, used in the error modal
function ErrorTypeIcon({ type }) {
    if (type === 'unmatched_lines' || type === 'missing_students') {
        return <span className="import-error-type-icon">&#9679;</span>
    }

    if (type === 'format_errors') {
        return <span className="import-error-type-icon">&#9633;</span>
    }

    if (type === 'incomplete_records') {
        return <span className="import-error-type-icon">&#9716;</span>
    }

    return <span className="import-error-type-icon">&#9632;</span>
}

//renders the list of error items for each error type
function ErrorItems({ type, items }) {
    if (type === 'unmatched_lines') {
        return items.map((item, index) => (
            <p className="import-error-item" key={`${type}-${index}`}>
                {item.extracted_name || item.student_name || 'Unknown student'}
            </p>
        ))
    }

    if (type === 'missing_students') {
        return items.map((item, index) => (
            <p className="import-error-item" key={`${type}-${index}`}>
                {item.student_name || 'Unknown student'}
            </p>
        ))
    }

    if (type === 'format_errors') {
        return items.flatMap((item, itemIndex) => (
            (item.errors || []).map((error, errorIndex) => {
                const isBlankFieldsError = error.field === 'attendance_details' || error.field === 'name'

                return (
                    <p className="import-error-item" key={`${type}-${itemIndex}-${errorIndex}`}>
                        {!isBlankFieldsError && <span className="import-error-line">{item.raw_line}</span>}
                        <strong className="import-error-reason">{error.reason}</strong>
                    </p>
                )
            })
        ))
    }

    if (type === 'incomplete_records') {
        return items.map((item, index) => (
            <p className="import-error-item" key={`${type}-${index}`}>
                <span className="import-error-line">{item.raw_line}</span>
                <strong className="import-error-reason">
                    Missing: {(item.missing || []).join(', ') || 'required attendance information'}
                </strong>
            </p>
        ))
    }

    if (type === 'all_no_response_warning') {
        return <p className="import-error-item">{items}</p>
    }

    return items.map((item, index) => (
        <p className="import-error-item" key={`${type}-${index}`}>
            {item.student_name || item.reason || 'Attendance information is incomplete.'}
        </p>
    ))
}

function ErrorModal({ errorMessage, errorDetails, onClose }) {
    const [expandedError, setExpandedError] = useState(null)
    const errorItems = getErrorItems(errorDetails)

    return (
        <div className="import-modal-overlay">
            <div className="import-modal import-modal-centered">
                <button className="import-modal-close" type="button" onClick={onClose}>&times;</button>
                <div className="import-status-icon import-status-icon-error">
                    <WarningIcon />
                </div>
                <h2 className="import-modal-title">Import Failed</h2>
                <p className="import-modal-subtext">
                    The attendance text could not be imported. Fix the issue below and try again.
                </p>

                {errorItems.length > 0 && (
                    <div className="import-error-details">
                        {errorItems.map(([type, items]) => {
                            const [title, subtitle] = errorLabels[type]
                            const isExpanded = expandedError === type
                            return (
                                <section className={`import-error-section ${isExpanded ? 'expanded' : ''}`} key={type}>
                                    <button
                                        className="import-error-row"
                                        type="button"
                                        onClick={() => setExpandedError(isExpanded ? null : type)}
                                    >
                                        <ErrorTypeIcon type={type} />
                                        <span className="import-error-row-copy">
                                            <strong>{title}</strong>
                                            <small>{subtitle}</small>
                                        </span>
                                        <span className="import-error-chevron">{isExpanded ? '^' : 'v'}</span>
                                    </button>
                                    {isExpanded && (
                                        <div className="import-error-items">
                                            <ErrorItems type={type} items={items} />
                                        </div>
                                    )}
                                </section>
                            )
                        })}
                    </div>
                )}

                {!errorItems.length && (
                    <div className="import-modal-summary import-modal-summary-error">
                        <p className="import-modal-summary-title">
                            <WarningIcon /> {errorMessage}
                        </p>
                    </div>
                )}

                <div className="import-modal-buttons">
                    <button className="import-clear-button" type="button" onClick={onClose}>
                        Close
                    </button>
                    <button className="import-primary-button" type="button" onClick={onClose}>
                        Try again
                    </button>
                </div>
            </div>
        </div>
    );
}

export default ErrorModal;