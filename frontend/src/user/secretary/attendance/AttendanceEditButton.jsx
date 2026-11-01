export default function AttendanceEditButton({ editing, disabled, onClick }) {
    return (
        <button
            type="button"
            className="drawer-edit-btn"
            onClick={onClick}
            disabled={disabled}
            aria-pressed={editing}
        >
            {editing ? '✓ Done' : 'Edit'}
        </button>
    )
}
