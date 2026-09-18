import { useState, useRef, useEffect } from 'react'
import { useNavigate } from 'react-router-dom'
import Sidebar from '../../components/Sidebar/Sidebar'
import PageDate from '../../components/PageDate/PageDate'
import Notification from '../../components/Notification/Notification'
import SortbyIcon from '../../assets/sort-by-icon.png'
import DropdownIcon from '../../assets/drop-down-icon.png'
import ResponseIcon from '../../assets/response-icon.png'
import LevelIcon from '../../assets/level-icon.png'
import SearchIcon from '../../assets/search-icon.png'
import './Students.css'

const SORT_OPTIONS = ['Ascending', 'Descending', 'Alphabetically']
const RESPONSE_OPTIONS = ['Responded', 'No Response']
const LEVEL_OPTIONS = ['None', 'Level 1', 'Level 2', 'Level 3']

function Students() {
    const navigate = useNavigate()
    const [students, setStudents] = useState([])
    const [loading, setLoading] = useState(() => Boolean(localStorage.getItem('auth_token')))
    const [searchQuery, setSearchQuery] = useState('')
    const [selectedSort, setSelectedSort] = useState(null)
    const [isSortOpen, setIsSortOpen] = useState(false)
    const sortDropdownRef = useRef(null)

    const [selectedResponse, setSelectedResponse] = useState(null)
    const [isResponseOpen, setIsResponseOpen] = useState(false)
    const responseDropdownRef = useRef(null)

    const [selectedLevel, setSelectedLevel] = useState(null)
    const [isLevelOpen, setIsLevelOpen] = useState(false)
    const levelDropdownRef = useRef(null)

    useEffect(() => {
        const token = localStorage.getItem('auth_token')
        if (!token) {
            return
        }

        fetch('http://127.0.0.1:8000/api/students/attendance-snapshot', {
            headers: {
                Accept: 'application/json',
                Authorization: `Bearer ${token}`,
            },
        })
            .then(res => res.ok ? res.json() : [])
            .then(data => {
                setStudents(Array.isArray(data.students) ? data.students : [])
                setLoading(false)
            })
            .catch(() => {
                setStudents([])
                setLoading(false)
            })
    }, [])

    useEffect(() => {
        function handleClickOutside(event) {
            if (sortDropdownRef.current && !sortDropdownRef.current.contains(event.target)) {
                setIsSortOpen(false)
            }
            if (responseDropdownRef.current && !responseDropdownRef.current.contains(event.target)) {
                setIsResponseOpen(false)
            }
            if (levelDropdownRef.current && !levelDropdownRef.current.contains(event.target)) {
                setIsLevelOpen(false)
            }
        }
        document.addEventListener('mousedown', handleClickOutside)
        return () => document.removeEventListener('mousedown', handleClickOutside)
    }, [])

    const handleSelectSort = (option) => {
        setSelectedSort(option)
        setIsSortOpen(false)
    }

    const handleSelectResponse = (option) => {
        setSelectedResponse(option)
        setIsResponseOpen(false)
    }

    const handleSelectLevel = (option) => {
        setSelectedLevel(option)
        setIsLevelOpen(false)
    }

    const normalizedYear = (year) => String(year).replace(/^([1-4])$/, '$1 Year')
    const filteredStudents = students
        .filter((student) => !selectedResponse || student.response === selectedResponse)
        .filter((student) => !selectedLevel || student.escalation_level === selectedLevel)
        .filter((student) => student.name.toLowerCase().includes(searchQuery.trim().toLowerCase()))
        .sort((a, b) => {
            if (!selectedSort) return 0
            const comparison = a.name.localeCompare(b.name)
            return selectedSort === 'Descending' ? -comparison : comparison
        })

    const respondedCount = students.filter((student) => student.response === 'Responded').length
    const noResponseCount = students.length - respondedCount
    const responseRate = students.length ? Math.round((respondedCount / students.length) * 100) : 0
    const noResponseRate = students.length ? Math.round((noResponseCount / students.length) * 100) : 0
    const escalationBars = (student) => {
        if (student.response === 'Responded') return 0
        return Math.min(3, Math.max(0, Number(student.no_response_count) || 0))
    }

    return (
        <div className="students">
            <Sidebar />

            <main className="students-main">
                <header className="students-header page-header">
                    <h3>Students</h3>
                    <p>Manage student information and attendance history.</p>
                    <PageDate />
                    <Notification />
                </header>

                <section className="filter-section">
                    <div className="sort-dropdown-container" ref={sortDropdownRef}>
                        <div
                            className={`filter-section-row ${isSortOpen ? 'active' : ''}`}
                            onClick={() => {
                                setIsSortOpen((prev) => !prev)
                                setIsResponseOpen(false)
                                setIsLevelOpen(false)
                            }}
                        >
                            <img
                                className="sort-by-icon"
                                src={SortbyIcon}
                                alt="Sort by Icon"
                            />
                            <p className="sort-by-text">{selectedSort || 'Sort by'}</p>
                            <img
                                className={`drop-down-icon ${isSortOpen ? 'rotate' : ''}`}
                                src={DropdownIcon}
                                alt="Dropdown Icon"
                            />
                        </div>

                        {isSortOpen && (
                            <ul className="sort-dropdown-menu">
                                {SORT_OPTIONS.map((option) => (
                                    <li
                                        key={option}
                                        className={`sort-dropdown-item ${selectedSort === option ? 'selected' : ''}`}
                                        onClick={() => handleSelectSort(option)}
                                    >
                                        {option}
                                    </li>
                                ))}
                            </ul>
                        )}
                    </div>

                    <div className="response-dropdown-container" ref={responseDropdownRef}>
                        <div
                            className={`filter-section-row ${isResponseOpen ? 'active' : ''}`}
                            onClick={() => {
                                setIsResponseOpen((prev) => !prev)
                                setIsSortOpen(false)
                                setIsLevelOpen(false)
                            }}
                        >
                            <img
                                className="response-icon"
                                src={ResponseIcon}
                                alt="Response Icon"
                            />
                            <p className="response-text">{selectedResponse || 'Response'}</p>
                            <img
                                className={`drop-down-icon ${isResponseOpen ? 'rotate' : ''}`}
                                src={DropdownIcon}
                                alt="Dropdown Icon"
                            />
                        </div>

                        {isResponseOpen && (
                            <ul className="response-dropdown-menu">
                                {RESPONSE_OPTIONS.map((option) => (
                                    <li
                                        key={option}
                                        className={`response-dropdown-item ${selectedResponse === option ? 'selected' : ''}`}
                                        onClick={() => handleSelectResponse(option)}
                                    >
                                        {option}
                                    </li>
                                ))}
                            </ul>
                        )}
                    </div>

                    <div className="level-dropdown-container" ref={levelDropdownRef}>
                        <div
                            className={`filter-section-row ${isLevelOpen ? 'active' : ''}`}
                            onClick={() => {
                                setIsLevelOpen((prev) => !prev)
                                setIsSortOpen(false)
                                setIsResponseOpen(false)
                            }}
                        >
                            <img
                                className="level-icon"
                                src={LevelIcon}
                                alt="Level Icon"
                            />
                            <p className="level-text">{selectedLevel || 'Level'}</p>
                            <img
                                className={`drop-down-icon ${isLevelOpen ? 'rotate' : ''}`}
                                src={DropdownIcon}
                                alt="Dropdown Icon"
                            />
                        </div>

                        {isLevelOpen && (
                            <ul className="level-dropdown-menu">
                                {LEVEL_OPTIONS.map((option) => (
                                    <li
                                        key={option}
                                        className={`level-dropdown-item ${selectedLevel === option ? 'selected' : ''}`}
                                        onClick={() => handleSelectLevel(option)}
                                    >
                                        {option}
                                    </li>
                                ))}
                            </ul>
                        )}
                    </div>

                    <div className="search-students-container">
                        <img
                            className="search-students-icon"
                            src={SearchIcon}
                            alt="Search Icon"
                        />
                        <input
                            type="text"
                            className="search-students-input"
                            placeholder="Search Students..."
                            value={searchQuery}
                            onChange={(e) => setSearchQuery(e.target.value)}
                        />
                    </div>
                </section>

                <section className="students-stats-section">
                    <div className="stat-card stat-card-total">
                        <div className="stat-card-header">
                            <h4 className="stat-card-title">Total Students</h4>
                            <p className="stat-card-subtitle">Overall enrolled</p>
                        </div>
                        <h2 className="stat-card-value">{loading ? '—' : students.length}</h2>
                    </div>

                    <div className="stat-card stat-card-responded">
                        <div className="stat-card-header">
                            <h4 className="stat-card-title">This Week Respondents</h4>
                            <p className="stat-card-subtitle">{loading ? '—' : `${responseRate}% response rate`}</p>
                        </div>
                        <h2 className="stat-card-value">{loading ? '—' : respondedCount}<span className="stat-card-slash">/{loading ? '—' : students.length}</span></h2>
                    </div>

                    <div className="stat-card stat-card-no-response">
                        <div className="stat-card-header">
                            <h4 className="stat-card-title">No Response</h4>
                            <p className="stat-card-subtitle">{loading ? '—' : `${noResponseRate}% of overall`}</p>
                        </div>
                        <h2 className="stat-card-value">{loading ? '—' : noResponseCount}</h2>
                    </div>
                </section>

                <section className="students-table-section">
                    <div className="students-table-heading">
                        <h2>Students</h2>
                        <span>Showing {filteredStudents.length} of {students.length}</span>
                    </div>
                    <div className="students-table-wrapper">
                        <table className="students-table">
                            <thead>
                                <tr>
                                    <th>Name</th><th>Year</th><th>Program</th><th>This Week's Response</th><th>Escalation</th><th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                {loading && <tr><td colSpan="6" className="students-table-state">Loading students…</td></tr>}
                                {!loading && filteredStudents.length === 0 && <tr><td colSpan="6" className="students-table-state">No students found.</td></tr>}
                                {!loading && filteredStudents.map((student) => (
                                    <tr key={student.id}>
                                        <td>{student.name}</td>
                                        <td>{normalizedYear(student.year_level)}</td>
                                        <td>{student.program}</td>
                                        <td><span className={`response-badge ${student.response === 'Responded' ? 'responded' : 'no-response'}`}>{student.response}</span></td>
                                        <td>
                                            <span className="escalation-bars" aria-label={`${student.no_response_count} no-response records`}>
                                                {[0, 1, 2].map((bar) => <i key={bar} className={bar < escalationBars(student) ? 'filled' : ''} />)}
                                            </span>
                                        </td>
                                        <td><button type="button" className="student-view-button" onClick={() => navigate('/attendance')}>View&nbsp; ›</button></td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </section>
            </main>
        </div>
    )
}

export default Students
