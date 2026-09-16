import { useState, useRef, useEffect } from 'react'
import Sidebar from '../../components/Sidebar/Sidebar'
import PageDate from '../../components/PageDate/PageDate'
import Notification from '../../components/Notification/Notification'
import SortbyIcon from '../../assets/sort-by-icon.png'
import DropdownIcon from '../../assets/drop-down-icon.png'
import './Students.css'

const SORT_OPTIONS = ['Ascending', 'Descending', 'Alphabetically']

function Students() {
    const [selectedSort, setSelectedSort] = useState(null)
    const [isOpen, setIsOpen] = useState(false)
    const dropdownRef = useRef(null)

    useEffect(() => {
        function handleClickOutside(event) {
            if (dropdownRef.current && !dropdownRef.current.contains(event.target)) {
                setIsOpen(false)
            }
        }
        document.addEventListener('mousedown', handleClickOutside)
        return () => document.removeEventListener('mousedown', handleClickOutside)
    }, [])

    const handleSelectOption = (option) => {
        setSelectedSort(option)
        setIsOpen(false)
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
                    <div className="sort-dropdown-container" ref={dropdownRef}>
                        <div
                            className={`filter-section-row ${isOpen ? 'active' : ''}`}
                            onClick={() => setIsOpen((prev) => !prev)}
                        >
                            <img
                                className="sort-by-icon"
                                src={SortbyIcon}
                                alt="Sort by Icon"
                            />
                            <p className="sort-by-text">{selectedSort || 'Sort by'}</p>
                            <img
                                className={`drop-down-icon ${isOpen ? 'rotate' : ''}`}
                                src={DropdownIcon}
                                alt="Dropdown Icon"
                            />
                        </div>

                        {isOpen && (
                            <ul className="sort-dropdown-menu">
                                {SORT_OPTIONS.map((option) => (
                                    <li
                                        key={option}
                                        className={`sort-dropdown-item ${selectedSort === option ? 'selected' : ''}`}
                                        onClick={() => handleSelectOption(option)}
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
