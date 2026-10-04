import { useEffect, useRef, useState } from 'react'
import CalendarIcon from '../../assets/calendar-icon.svg'
import DropdownIcon from '../../assets/drop-down-icon.svg'
import Calendar from '../Calendar/Calendar'
import './FilterDropdown.css'

function fromDateValue(value) {
    if (!value) return null
    const [year, month, day] = value.split('-').map(Number)
    return new Date(year, month - 1, day)
}

function toDateValue(date) {
    return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`
}

export default function CalendarFilter({ value, onChange }) {
    const [open, setOpen] = useState(false)
    const root = useRef(null)
    const selectedDate = fromDateValue(value)
    const label = selectedDate
        ? selectedDate.toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' })
        : 'Date'

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
        <div className={`app-dropdown filter-dropdown filter-calendar-dropdown ${open ? 'is-open' : ''}`} ref={root}>
            <button type="button" className="app-dropdown-trigger filter-dropdown-trigger" aria-expanded={open} onClick={() => setOpen((current) => !current)}>
                <img className="filter-dropdown-icon" src={CalendarIcon} alt="" />
                <span>{label}</span>
                <img className={`filter-dropdown-chevron ${open ? 'open' : ''}`} src={DropdownIcon} alt="" />
            </button>
            {open && <div className="filter-calendar-popover"><Calendar value={selectedDate} initialMonth={selectedDate || new Date()} onChange={(date) => { onChange(toDateValue(date)); setOpen(false) }} /></div>}
        </div>
    )
}
