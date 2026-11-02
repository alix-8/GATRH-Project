import './SelectAttendanceWeekModal.css'

const gatheringLabels = [
    ['pm', 'Prayer Meeting'],
    ['ws', 'Worship Service'],
    ['tg', 'Thanksgiving'],
]

function SelectAttendanceWeekModal({ periods, loading, error, onSelect, onCancel }) {
    const options = [
        { key: 'last', title: "Add Attendance for Last Week" },
        { key: 'current', title: "Add Attendance for This Week" },
    ]

    return (
        <div className="import-week-overlay" onMouseDown={event => event.target === event.currentTarget && onCancel()}>
            <section className="import-week-dialog" role="dialog" aria-modal="true" aria-labelledby="import-week-title">
                <h2 id="import-week-title">Choose an attendance week</h2>
                <p className="import-week-intro">The date ranges below will be used to format and save attendance.</p>
                {loading ? <p className="import-week-status">Preparing attendance weeks…</p> : null}
                {error ? <p className="import-week-error" role="alert">{error}</p> : null}
                {!loading && !error && (
                    <div className="import-week-options" data-import-guide="week">
                        {options.map(option => {
                            const data = periods?.[option.key]
                            return (
                                <button
                                    key={option.key}
                                    type="button"
                                    className="import-week-option"
                                    onClick={() => onSelect(option.key)}
                                >
                                    <span className="import-week-option-title">{option.title}</span>
                                    <span className="import-week-date-list">
                                        {gatheringLabels.map(([key, label]) => (
                                            <span key={key}><strong>{label}</strong><br />{data?.[key]?.label || 'Date unavailable'}</span>
                                        ))}
                                    </span>
                                    <span className="import-week-select-hint">Select this week <span aria-hidden="true">→</span></span>
                                </button>
                            )
                        })}
                    </div>
                )}
                <div className="import-week-footer">
                    <button type="button" className="import-clear-button" onClick={onCancel}>Cancel</button>
                </div>
            </section>
        </div>
    )
}

export default SelectAttendanceWeekModal
