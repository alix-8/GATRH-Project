import { useState } from 'react'
import './ModalSelect.css'

function ModalSelect({ children, onChange, onBlur, onKeyDown, onPointerDown, disabled, ...props }) {
    const [isOpen, setIsOpen] = useState(false)

    function handleKeyDown(event) {
        if (disabled) return
        if (event.key === 'Escape' || event.key === 'Tab' || event.key === 'Enter') setIsOpen(false)
        if (event.key === 'ArrowDown' || event.key === 'ArrowUp' || event.key === ' ') setIsOpen(true)
        onKeyDown?.(event)
    }

    return <div className={`admin-modal-select${isOpen && !disabled ? ' is-open' : ''}${disabled ? ' is-disabled' : ''}`}>
        <select
            {...props}
            disabled={disabled}
            onPointerDown={event => {
                if (disabled) return
                setIsOpen(current => !current)
                onPointerDown?.(event)
            }}
            onKeyDown={handleKeyDown}
            onChange={event => { setIsOpen(false); onChange?.(event) }}
            onBlur={event => { setIsOpen(false); onBlur?.(event) }}
        >{children}</select>
        <span className="admin-modal-select-chevron" aria-hidden="true">
            <svg viewBox="0 0 12 10" fill="none"><path d="m2 3 4 4 4-4" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round" /></svg>
        </span>
    </div>
}

export default ModalSelect
