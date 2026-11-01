import PageDate from './PageDate'
import Notification from '../feedback/Notification'
import './Header.css'

function Header({
    title,
    subtitle,
    description,
    showDate = true,
    showNotification = true,
    children,
    className = '',
}) {
    const subtitleText = subtitle ?? description
    let receivesReminders = true
    try {
        receivesReminders = JSON.parse(localStorage.getItem('user') || 'null')?.role !== 'chancellor'
    } catch { /* Keep the existing header behavior if cached user data is invalid. */ }

    return (
        <header className={`app-header page-header ${className}`.trim()}>
            <div className="header-title">
                {title && <h3>{title}</h3>}
                {subtitleText && <p>{subtitleText}</p>}
            </div>

            <div className="header-meta">
                {children}
                {showDate && <PageDate className="header-date" />}
                {showNotification && receivesReminders && <Notification />}
            </div>
        </header>
    )
}

export { Header, Header as PageHeader }
export default Header
