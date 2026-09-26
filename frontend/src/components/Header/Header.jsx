import PageDate from '../PageDate/PageDate'
import Notification from '../Notification/Notification'
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

    return (
        <header className={`app-header page-header ${className}`.trim()}>
            <div className="header-title">
                {title && <h3>{title}</h3>}
                {subtitleText && <p>{subtitleText}</p>}
            </div>

            <div className="header-meta">
                {children}
                {showDate && <PageDate className="header-date" />}
                {showNotification && <Notification />}
            </div>
        </header>
    )
}

export { Header, Header as PageHeader }
export default Header
