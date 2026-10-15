import { SearchIcon } from '../icons/Reusables'
import './SearchPill.css'

export default function SearchPill({ value, onChange, placeholder = 'Search…', ariaLabel, className = '' }) {
    return (
        <label className={`search-pill ${className}`.trim()}>
            <SearchIcon />
            <input
                className="search-pill-input"
                type="text"
                aria-label={ariaLabel || placeholder}
                placeholder={placeholder}
                value={value}
                onChange={onChange}
            />
        </label>
    )
}
