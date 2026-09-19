import { useState } from 'react'
import './Calendar.css'

const WEEKDAYS = ['Su', 'Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa']
const MONTH_NAMES = [
    'January', 'February', 'March', 'April', 'May', 'June',
    'July', 'August', 'September', 'October', 'November', 'December',
]

function isSameDay(a, b) {
    return a && b &&
        a.getFullYear() === b.getFullYear() &&
        a.getMonth() === b.getMonth() &&
        a.getDate() === b.getDate()
}

/**
 * Reusable month calendar.
 *
 * Props:
 * - value:        Date | null   — the currently selected date (optional)
 * - onChange:     (date: Date) => void   — called when a day cell is clicked
 * - initialMonth: Date          — which month to open on if `value` isn't set (defaults to today)
 * - minDate/maxDate: Date       — optional bounds; days outside are disabled
 */
export default function Calendar({ value = null, onChange, initialMonth, minDate, maxDate }) {
    const [viewDate, setViewDate] = useState(() => value || initialMonth || new Date())

    const year = viewDate.getFullYear()
    const month = viewDate.getMonth()

    const firstOfMonth = new Date(year, month, 1)
    const startWeekday = firstOfMonth.getDay()
    const daysInMonth = new Date(year, month + 1, 0).getDate()
    const daysInPrevMonth = new Date(year, month, 0).getDate()

    const cells = []

    // Leading days from previous month
    for (let i = startWeekday - 1; i >= 0; i--) {
        cells.push({
            day: daysInPrevMonth - i,
            date: new Date(year, month - 1, daysInPrevMonth - i),
            outside: true,
        })
    }

    // Days in current month
    for (let d = 1; d <= daysInMonth; d++) {
        cells.push({ day: d, date: new Date(year, month, d), outside: false })
    }

    // Trailing days from next month, padded to complete the last week row
    let nextDay = 1
    while (cells.length % 7 !== 0) {
        cells.push({ day: nextDay, date: new Date(year, month + 1, nextDay), outside: true })
        nextDay++
    }

    const goToPrevMonth = () => setViewDate(new Date(year, month - 1, 1))
    const goToNextMonth = () => setViewDate(new Date(year, month + 1, 1))

    const isDisabled = (date) => {
        if (minDate && date < new Date(minDate.getFullYear(), minDate.getMonth(), minDate.getDate())) return true
        if (maxDate && date > new Date(maxDate.getFullYear(), maxDate.getMonth(), maxDate.getDate())) return true
        return false
    }

    const handleDayClick = (cell) => {
        if (isDisabled(cell.date)) return
        onChange?.(cell.date)
        if (cell.outside) setViewDate(new Date(cell.date.getFullYear(), cell.date.getMonth(), 1))
    }

    return (
        <div className="rcal">
            <div className="rcal-header">
                <button type="button" className="rcal-nav-btn" aria-label="Previous month" onClick={goToPrevMonth}>
                    «
                </button>
                <span className="rcal-title">{MONTH_NAMES[month]} {year}</span>
                <button type="button" className="rcal-nav-btn" aria-label="Next month" onClick={goToNextMonth}>
                    »
                </button>
            </div>

            <div className="rcal-weekdays">
                {WEEKDAYS.map((w) => (
                    <span key={w} className="rcal-weekday">{w}</span>
                ))}
            </div>

            <div className="rcal-grid">
                {cells.map((cell, i) => {
                    const selected = isSameDay(cell.date, value)
                    const disabled = isDisabled(cell.date)
                    return (
                        <button
                            type="button"
                            key={i}
                            className={[
                                'rcal-day',
                                cell.outside ? 'rcal-day-outside' : '',
                                selected ? 'rcal-day-selected' : '',
                                disabled ? 'rcal-day-disabled' : '',
                            ].filter(Boolean).join(' ')}
                            onClick={() => handleDayClick(cell)}
                            disabled={disabled}
                        >
                            {cell.day}
                        </button>
                    )
                })}
            </div>
        </div>
    )
}