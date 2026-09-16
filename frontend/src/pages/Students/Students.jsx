import { useState, useRef, useEffect } from 'react'
import Sidebar from '../../components/Sidebar/Sidebar'
import PageDate from '../../components/PageDate/PageDate'
import Notification from '../../components/Notification/Notification'
import SortbyIcon from '../../assets/sort-by-icon.png'
import DropdownIcon from '../../assets/drop-down-icon.png'
import ResponseIcon from '../../assets/response-icon.png'
import './Students.css'

const SORT_OPTIONS = ['Ascending', 'Descending', 'Alphabetically']
const RESPONSE_OPTIONS = ['Responded', 'No Response']

function Students() {
    const [selectedSort, setSelectedSort] = useState(null)
    const [isSortOpen, setIsSortOpen] = useState(false)
    const sortDropdownRef = useRef(null)

    const [selectedResponse, setSelectedResponse] = useState(null)
    const [isResponseOpen, setIsResponseOpen] = useState(false)
    const responseDropdownRef = useRef(null)

    useEffect(() => {
        function handleClickOutside(event) {
            if (sortDropdownRef.current && !sortDropdownRef.current.contains(event.target)) {
                setIsSortOpen(false)
            }
            if (responseDropdownRef.current && !responseDropdownRef.current.contains(event.target)) {
                setIsResponseOpen(false)
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

                    <div className="sort-dropdown-container" ref={responseDropdownRef}>
                        <div
                            className={`filter-section-row ${isResponseOpen ? 'active' : ''}`}
                            onClick={() => {
                                setIsResponseOpen((prev) => !prev)
                                setIsSortOpen(false)
                            }}
                        >
                            <img
                                className="sort-by-icon"
                                src={ResponseIcon}
                                alt="Response Icon"
                            />
                            <p className="sort-by-text">{selectedResponse || 'Response'}</p>
                            <img
                                className={`drop-down-icon ${isResponseOpen ? 'rotate' : ''}`}
                                src={DropdownIcon}
                                alt="Dropdown Icon"
                            />
                        </div>

                        {isResponseOpen && (
                            <ul className="sort-dropdown-menu">
                                {RESPONSE_OPTIONS.map((option) => (
                                    <li
                                        key={option}
                                        className={`sort-dropdown-item ${selectedResponse === option ? 'selected' : ''}`}
                                        onClick={() => handleSelectResponse(option)}
                                    >
                                        {option}
                                    </li>
                                ))}
                            </ul>
                        )}
                    </div>
                </section>
            </main>
        </div>
    )
}

export default Students
