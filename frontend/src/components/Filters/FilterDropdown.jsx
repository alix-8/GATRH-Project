import { useEffect, useRef, useState } from 'react'
import DropdownIcon from '../../assets/drop-down-icon.png'
import DropdownChevronWhite from '../Icons/DropdownChevronWhite.svg'
import './FilterDropdown.css'

export default function FilterDropdown({ label, value, options, icon, onChange, primary = false }) {
    const [open, setOpen] = useState(false)
    const root = useRef(null)
    const selectedLabel = options.find((option) => option.value === value)?.label || label

    useEffect(() => {
        const closeOutside = (event) => {
            if (root.current && !root.current.contains(event.target)) setOpen(false)
        }
        const closeOnEscape = (event) => event.key === 'Escape' && setOpen(false)
        document.addEventListener('mousedown', closeOutside)
        document.addEventListener('keydown', closeOnEscape)
        return () => {
            document.removeEventListener('mousedown', closeOutside)
            document.removeEventListener('keydown', closeOnEscape)
        }
    }, [])

    return (
        <div className={`app-dropdown filter-dropdown ${open ? 'is-open' : ''}`} ref={root}>
            <button type="button" className={`app-dropdown-trigger filter-dropdown-trigger ${primary ? 'app-dropdown-trigger--primary' : ''}`} aria-expanded={open} onClick={() => setOpen((current) => !current)}>
                {icon && <img className="filter-dropdown-icon" src={icon} alt="" />}
                <span>{selectedLabel}</span>
                <img className={`filter-dropdown-chevron ${open ? 'open' : ''}`} src={primary ? DropdownChevronWhite : DropdownIcon} alt="" />
            </button>
            {open && <ul className="app-dropdown-menu filter-dropdown-menu" role="listbox" aria-label={label}>
                {options.map((option) => <li key={option.value || 'all'}>
                    <button type="button" role="option" aria-selected={value === option.value} className={`app-dropdown-option ${value === option.value ? 'selected' : ''}`} onClick={() => { onChange(option.value); setOpen(false) }}>{option.label}</button>
                </li>)}
            </ul>}
        </div>
    )
}
