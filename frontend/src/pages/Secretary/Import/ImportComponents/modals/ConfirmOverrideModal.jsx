import React from 'react'
import './ConfirmOverrideModal.css'

function ConfirmOverrideModal({ duplicateData, onCancel, onOverride, isSubmitting = false }) {
    const gatheringTypeLabel = duplicateData?.gathering_type
        ? (duplicateData.gathering_type === 'pm'
            ? 'Prayer Meeting'
            : duplicateData.gathering_type === 'ws'
                ? 'Worship Service'
                : duplicateData.gathering_type === 'tg'
                    ? 'Thanksgiving'
                    : duplicateData.gathering_type.toUpperCase())
        : 'Gathering'

    const weekLabel = duplicateData?.week_range?.label || 'the applicable week'
    const count = duplicateData?.duplicates_count || duplicateData?.duplicate_records?.length || 0

    return (
        <div className="confirm-override-overlay import-modal-overlay">
            <div className="confirm-override-modal import-modal import-modal-centered" role="dialog" aria-modal="true" aria-labelledby="override-modal-title">
                <button
                    className="confirm-override-close import-modal-close"
                    type="button"
                    onClick={onCancel}
                    disabled={isSubmitting}
                    aria-label="Close"
                >
                    &times;
                    
                </button>
                <div className="confirm-override-icon">
                    <svg width="26" height="26" viewBox="0 0 24 24" fill="none">
                        <path
                            d="M12 9v4m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"
                            stroke="currentColor"
                            strokeWidth="2"
                            strokeLinecap="round"
                            strokeLinejoin="round"
                        />
                    </svg>
                </div>
                <h2 id="override-modal-title" className="confirm-override-title import-modal-title">
                    Attendance Already Exists
                </h2>
                <p className="confirm-override-text import-modal-subtext">
                    An attendance record already exists for <strong>{gatheringTypeLabel}</strong> for {weekLabel}.
                    {count > 0 && ` (${count} student record${count > 1 ? 's' : ''} already recorded)`}
                </p>
                <p className="confirm-override-subtext">
                    Choose <strong>Cancel</strong> to close and leave the existing record unchanged, or <strong>Override</strong> to replace the existing record with the newly submitted data.
                </p>

                <div className="confirm-override-actions import-modal-buttons">
                    <button
                        className="confirm-override-btn-cancel import-clear-button"
                        type="button"
                        onClick={onCancel}
                        disabled={isSubmitting}
                    >
                        Cancel
                    </button>
                    <button
                        className="confirm-override-btn-override import-primary-button"
                        type="button"
                        onClick={onOverride}
                        disabled={isSubmitting}
                    >
                        {isSubmitting ? 'Overriding…' : 'Override'}
                    </button>
                </div>
            </div>
        </div>
    )
}

export default ConfirmOverrideModal
