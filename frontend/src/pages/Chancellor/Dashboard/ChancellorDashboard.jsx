import { useEffect, useMemo, useRef, useState } from 'react'
import Sidebar from '../../../components/Sidebar/Sidebar'
import Header from '../../../components/Header/Header'
import Calendar from '../../../components/Calendar/Calendar'
import { request } from '../../../api/request'
import CalendarIcon from '../../../assets/calendar-icon.svg'
import AllProgramsIcon from '../../../assets/all-programs-icon.svg'
import AllWeeksIcon from '../../../assets/all-weeks-icon.svg'
import DropdownIcon from '../../../assets/drop-down-icon.svg'
import './ChancellorDashboard.css'

function getTodayDateValue() {
    const today = new Date()
    return `${today.getFullYear()}-${String(today.getMonth() + 1).padStart(2, '0')}-${String(today.getDate()).padStart(2, '0')}`
}

function fromDateValue(value) {
    const [year, month, day] = value.split('-').map(Number)
    return new Date(year, month - 1, day)
}

function toDateValue(date) {
    return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`
}

function ChancellorDashboard() {
    const [date, setDate] = useState('')
    const [program, setProgram] = useState('')
    const [week, setWeek] = useState('all')
    const [programs, setPrograms] = useState([])
    const [loadError, setLoadError] = useState('')
    const [isCalendarOpen, setIsCalendarOpen] = useState(false)
    const [isProgramOpen, setIsProgramOpen] = useState(false)
    const [isWeekOpen, setIsWeekOpen] = useState(false)
    const calendarRef = useRef(null)
    const programRef = useRef(null)
    const weekRef = useRef(null)
    const query = useMemo(() => {
        const params = new URLSearchParams({ date: date || getTodayDateValue() })
        if (program) params.set('program', program)
        if (week !== 'all') params.set('week', week)
        return params.toString()
    }, [date, program])
    const selectedDate = date ? fromDateValue(date) : null

    useEffect(() => {
        const controller = new AbortController()

        async function loadDashboard() {
            setLoadError('')

            try {
                const data = await request(`/dashboard?${query}`, { signal: controller.signal })
                setPrograms(Array.isArray(data.programs) ? data.programs : [])
            } catch (error) {
                if (error.name !== 'AbortError') {
                    setLoadError(error.message || 'Could not load dashboard filters.')
                }
            }
        }

        loadDashboard()
        return () => controller.abort()
    }, [query])

    useEffect(() => {
        const closeOutside = (event) => {
            if (calendarRef.current && !calendarRef.current.contains(event.target)) {
                setIsCalendarOpen(false)
            }
            if (programRef.current && !programRef.current.contains(event.target)) {
                setIsProgramOpen(false)
            }
            if (weekRef.current && !weekRef.current.contains(event.target)) {
                setIsWeekOpen(false)
            }
        }
        const closeOnEscape = (event) => {
            if (event.key === 'Escape') {
                setIsCalendarOpen(false)
                setIsProgramOpen(false)
                setIsWeekOpen(false)
            }
        }

        document.addEventListener('mousedown', closeOutside)
        document.addEventListener('keydown', closeOnEscape)
        return () => {
            document.removeEventListener('mousedown', closeOutside)
            document.removeEventListener('keydown', closeOnEscape)
        }
    }, [])

    return (
        <div className="chancellor-dashboard">
            <Sidebar />
            <main className="chancellor-dashboard-main">
                <Header
                    title="Dashboard"
                    subtitle="Monitor attendance reporting progress across all programs."
                    className="chancellor-dashboard-header"
                />
                <section className="chancellor-dashboard-filters" aria-label="Dashboard filters">
                    <div className={`chancellor-filter-dropdown ${isCalendarOpen ? 'is-open' : ''}`} ref={calendarRef}>
                        <button
                            type="button"
                            className="chancellor-filter-trigger"
                            aria-expanded={isCalendarOpen}
                            onClick={() => {
                                setIsCalendarOpen((open) => !open)
                                setIsProgramOpen(false)
                            }}
                        >
                            <img className="chancellor-filter-icon" src={CalendarIcon} alt="" />
                            <span>{date ? selectedDate.toLocaleDateString(undefined, {
                                month: 'short',
                                day: 'numeric',
                                year: 'numeric',
                            }) : 'Date'}</span>
                            <img
                                className={`chancellor-filter-chevron ${isCalendarOpen ? 'open' : ''}`}
                                src={DropdownIcon}
                                alt=""
                            />
                        </button>
                        {isCalendarOpen && (
                            <div className="chancellor-calendar-menu">
                                <Calendar
                                    value={selectedDate}
                                    initialMonth={selectedDate || new Date()}
                                    onChange={(nextDate) => {
                                        setDate(toDateValue(nextDate))
                                        setIsCalendarOpen(false)
                                    }}
                                />
                            </div>
                        )}
                    </div>
                    <div className={`chancellor-filter-dropdown ${isProgramOpen ? 'is-open' : ''}`} ref={programRef}>
                        <button
                            type="button"
                            className="chancellor-filter-trigger"
                            aria-expanded={isProgramOpen}
                            onClick={() => {
                                setIsProgramOpen((open) => !open)
                                setIsCalendarOpen(false)
                            }}
                        >
                            <img className="chancellor-filter-icon" src={AllProgramsIcon} alt="" />
                            <span className="chancellor-program-label">{program || 'All Programs'}</span>
                            <img
                                className={`chancellor-filter-chevron ${isProgramOpen ? 'open' : ''}`}
                                src={DropdownIcon}
                                alt=""
                            />
                        </button>
                        {isProgramOpen && (
                            <ul className="chancellor-program-menu" role="listbox" aria-label="Programs">
                                <li>
                                    <button
                                        type="button"
                                        role="option"
                                        aria-selected={!program}
                                        className={`chancellor-program-option ${!program ? 'selected' : ''}`}
                                        onClick={() => {
                                            setProgram('')
                                            setIsProgramOpen(false)
                                        }}
                                    >
                                        All Programs
                                    </button>
                                </li>
                                {programs.map((code) => (
                                    <li key={code}>
                                        <button
                                            type="button"
                                            role="option"
                                            aria-selected={program === code}
                                            className={`chancellor-program-option ${program === code ? 'selected' : ''}`}
                                            onClick={() => {
                                                setProgram(code)
                                                setIsProgramOpen(false)
                                            }}
                                        >
                                            {code}
                                        </button>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </div>
                    
                    <div className={`chancellor-filter-dropdown ${isWeekOpen ? 'is-open' : ''}`} ref={weekRef}>
                        <button
                            type="button"
                            className="chancellor-filter-trigger"
                            aria-expanded={isWeekOpen}
                            onClick={() => {
                                setIsWeekOpen((open) => !open)
                                setIsCalendarOpen(false)
                                setIsProgramOpen(false)
                            }}
                        >
                            <img className="chancellor-filter-icon" id="all-weeks-icon" src={AllWeeksIcon} alt="" />
                            <span>{week === 'all' ? 'All Weeks' : `Week ${week}`}</span>
                            <img
                                className={`chancellor-filter-chevron ${isWeekOpen ? 'open' : ''}`}
                                src={DropdownIcon}
                                alt=""
                            />
                        </button>
                        {isWeekOpen && (
                            <ul className="chancellor-program-menu chancellor-week-menu" role="listbox" aria-label="Weeks">
                                {['all', '1', '2', '3', '4'].map((weekOption) => (
                                    <li key={weekOption}>
                                        <button
                                            type="button"
                                            role="option"
                                            aria-selected={week === weekOption}
                                            className={`chancellor-program-option ${week === weekOption ? 'selected' : ''}`}
                                            onClick={() => {
                                                setWeek(weekOption)
                                                setIsWeekOpen(false)
                                            }}
                                        >
                                            {weekOption === 'all' ? 'All Weeks' : `Week ${weekOption}`}
                                        </button>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </div>
                   
                </section>
                {loadError && <p className="chancellor-dashboard-error" role="alert">{loadError}</p>}
            </main>
        </div>
    )
}

export default ChancellorDashboard
