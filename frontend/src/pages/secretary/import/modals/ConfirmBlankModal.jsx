function ConfirmBlankModal({ modalMessage, onClose, onConfirm }) {
    return (
        <div className="import-modal-overlay">
            <div className="import-modal import-modal-centered">
                <h2 className="import-modal-title">Some fields are blank</h2>
                <p className="import-modal-subtext">{modalMessage}</p>
                <div className="import-modal-buttons">
                    <button className="import-clear-button" type="button" onClick={onClose}>
                        Go back and edit
                    </button>
                    <button className="import-primary-button" type="button" onClick={onConfirm}>
                        Mark as absent and continue
                    </button>
                </div>
            </div>
        </div>
    )
}

export default ConfirmBlankModal;