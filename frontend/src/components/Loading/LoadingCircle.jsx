import './LoadingCircle.css'

export default function LoadingCircle({ label = 'Loading…', size = 22, inline = false, className = '' }) {
    return (
        <span
            className={`loading-circle ${inline ? 'loading-circle-inline' : ''} ${className}`.trim()}
            role="status"
            aria-label={label || 'Loading'}
            style={{ '--loading-circle-size': `${size}px` }}
        >
            <span className="loading-circle-spinner" aria-hidden="true" />
            {label && <span className="loading-circle-label">{label}</span>}
        </span>
    )
}
