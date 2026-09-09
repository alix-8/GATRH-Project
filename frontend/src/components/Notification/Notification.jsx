import NotificationIcon from '../../assets/notification-icon.png'
import './Notification.css'

function Notification() {
    return (

        <button type="button"
            className="notification-icon"
            aria-label="Notifications"
        >
            <img
                src={NotificationIcon}
                alt="Notification Icon"
            />
        </button>

    )

}

export default Notification