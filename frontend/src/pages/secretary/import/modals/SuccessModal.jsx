const CheckIcon = () => (
    <svg width="26" height="26" viewBox="0 0 24 24" fill="none">
        <path d="M20 6L9 17l-5-5" stroke="currentColor" strokeWidth="2.5" strokeLinecap="round" strokeLinejoin="round" />
    </svg>
)

function SuccessModal({ result, onClose, onGoToAttendance }) {
    return (
        <div className="import-modal-overlay">
            <div className="import-modal import-modal-centered">
                <button className="import-modal-close" type="button" onClick={onClose}>&times;</button>
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

                    {result.drafts ? (
                        Object.entries(result.summary || {}).map(([type, s]) => (
                            s.skipped ? (
                                <p key={type}>{type.toUpperCase()}: skipped — {s.reason}</p>
                            ) : (
                                <p key={type}>
                                    {type.toUpperCase()}: {s.total_students} students, {s.present_count} present, {s.no_response_count} no response
                                </p>
                            )
                        ))
                    ) : (
                        <>
                            <p>{result.summary.total_students} students detected</p>
                            <p>{result.summary.present_count} present, {result.summary.no_response_count} no response
                                {result.summary.unmatched_count > 0 && <>, {result.summary.unmatched_count} unmatched</>}
                            </p>
                        </>
                    )}
                </div>

                <div className="import-modal-buttons" data-import-guide="success">
                    <button className="import-clear-button" type="button" onClick={onClose}>
                        Close
                    </button>
                    <button className="import-primary-button" type="button" onClick={onGoToAttendance}>
                        Go to Attendance
                    </button>
                </div>
            </div>
        </div>
    )
}

export default SuccessModal;
