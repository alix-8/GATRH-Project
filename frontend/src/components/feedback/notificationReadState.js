function getStorageKey(kind = 'seen') {
    let userKey = 'guest'

    try {
        const user = JSON.parse(localStorage.getItem('user') || 'null')
        userKey = user?.id ?? user?.role ?? userKey
    } catch {
        // Keep notification state isolated even when cached user data is invalid.
    }

    return `gatrh-notifications-${kind}:${userKey}`
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

export function getDismissedNotificationIds() {
    try {
        const ids = JSON.parse(localStorage.getItem(getStorageKey('dismissed')) || '[]')
        return new Set(Array.isArray(ids) ? ids.map(String) : [])
    } catch {
        return new Set()
    }
}

export function dismissNotificationIds(ids) {
    const dismissed = getDismissedNotificationIds()
    ids.filter(id => id != null).forEach(id => dismissed.add(String(id)))
    localStorage.setItem(getStorageKey('dismissed'), JSON.stringify([...dismissed]))
}
