const LOGIN_SKELETON_KEY = 'gatrh.login-skeleton'

export function prepareLoginSkeleton(role) {
    try {
        if (['secretary', 'program_secretary', 'program_head', 'cm_coordinator', 'chancellor'].includes(role)) {
            sessionStorage.setItem(LOGIN_SKELETON_KEY, 'pending')
        } else {
            clearLoginSkeleton()
        }
    } catch { /* Loading feedback must not prevent sign-in. */ }
}

export function isLoginSkeletonPending() {
    try {
        return sessionStorage.getItem(LOGIN_SKELETON_KEY) === 'pending'
    } catch {
        return false
    }
}

export function clearLoginSkeleton() {
    try {
        sessionStorage.removeItem(LOGIN_SKELETON_KEY)
    } catch { /* Storage may be unavailable. */ }
}
