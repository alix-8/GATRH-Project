export const API_BASE_URL = (
    import.meta.env.VITE_API_BASE_URL || 'http://localhost:8000/api'
).replace(/\/+$/, '')

export function xsrfHeaders() {
    const cookie = document.cookie.split('; ').find(value => value.startsWith('XSRF-TOKEN='))
    return cookie ? { 'X-XSRF-TOKEN': decodeURIComponent(cookie.slice('XSRF-TOKEN='.length)) } : {}
}

export async function initializeCsrf() {
    const appBase = API_BASE_URL.replace(/\/api$/, '')
    await fetch(`${appBase}/sanctum/csrf-cookie`, { credentials: 'include' })
}

export async function request(url, options = {}) {
    const response = await fetch(`${API_BASE_URL}${url}`, {
        ...options,
        credentials: 'include',
        headers: {
            Accept: 'application/json',
            ...(options.body && !(options.body instanceof FormData) ? { 'Content-Type': 'application/json' } : {}),
            ...xsrfHeaders(),
            ...options.headers,
        },
    })

    const data = await response.json().catch(() => ({}))
    if (!response.ok) {
        if (response.status === 401) {
            localStorage.removeItem('user')
            window.dispatchEvent(new CustomEvent('auth:expired'))
        }
        const error = new Error(data.message || Object.values(data.errors || {}).flat()[0] || 'The request failed.')
        error.details = data
        throw error
    }
    return data
}
