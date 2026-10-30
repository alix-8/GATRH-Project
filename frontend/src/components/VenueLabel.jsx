import './VenueLabel.css'

export default function VenueLabel({ value, fallback = '—' }) {
    const special = String(value ?? '').trim().toLowerCase() === 'special'

    return special
        ? <span className="venue-special-badge">Special</span>
        : <>{value || fallback}</>
}
