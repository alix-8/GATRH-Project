import './UserAvatar.css'

const PROGRAM_COLORS = {
    bsis: { backgroundColor: '#f8d7da', color: '#9f2330' },
    act: { backgroundColor: '#ffe2cc', color: '#a84908' },
    bsa: { backgroundColor: '#fff1b8', color: '#806300' },
    bsais: { backgroundColor: '#fff1b8', color: '#806300' },
    bab: { backgroundColor: '#d8e9ff', color: '#24599b' },
    bssw: { backgroundColor: '#eadcff', color: '#6337a0' },
}

function getProgramKey(program = '') {
    const value = String(program).trim().toUpperCase()
    return ['BSIS', 'BSAIS', 'BSA', 'ACT', 'BAB', 'BSSW'].find((code) =>
        new RegExp(`(^|\\b)${code}(\\b|$)`).test(value),
    )?.toLowerCase()
}

function getInitials(name = '') {
    const value = String(name).trim()
    const [lastName, firstNames] = value.split(',').map((part) => part.trim())
    if (firstNames && lastName) return `${firstNames[0]}${lastName[0]}`.toUpperCase()

    const parts = value.split(/\s+/).filter(Boolean)
    if (!parts.length) return '?'
    return parts.length === 1
        ? parts[0].slice(0, 2).toUpperCase()
        : `${parts[0][0]}${parts.at(-1)[0]}`.toUpperCase()
}

export default function UserAvatar({ name, program, className = '', as: Element = 'span' }) {
    const programKey = getProgramKey(program)
    const colors = PROGRAM_COLORS[programKey] || { backgroundColor: '#e5edf4', color: '#53647a' }

    return (
        <Element
            className={`user-avatar ${className}`.trim()}
            style={colors}
            aria-hidden="true"
        >
            {getInitials(name)}
        </Element>
    )
}
