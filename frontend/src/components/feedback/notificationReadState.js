function getStorageKey() {
    let userKey = 'guest'

    try {
        const user = JSON.parse(localStorage.getItem('user') || 'null')
        userKey = user?.id ?? user?.role ?? userKey
    } catch {
        // Keep notification state isolated even when cached user data is invalid.
    }

    return `gatrh-notifications-seen:${userKey}`
}

export function getSeenNotificationIds() {
    try {
        const ids = JSON.parse(localStorage.getItem(getStorageKey()) || '[]')
        return new Set(Array.isArray(ids) ? ids.map(String) : [])
    } catch {
        return new Set()
    }
}

export function markNotificationIdsSeen(ids) {
    const seen = getSeenNotificationIds()
    ids.filter(id => id != null).forEach(id => seen.add(String(id)))
    localStorage.setItem(getStorageKey(), JSON.stringify([...seen]))
    window.dispatchEvent(new Event('notifications:changed'))
}
