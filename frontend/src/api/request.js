export const API_BASE_URL = (
    import.meta.env.VITE_API_BASE_URL || 'http://127.0.0.1:8000/api'
).replace(/\/+$/, '')

export async function request(url, options = {}) {
    const token = localStorage.getItem('auth_token')
    const response = await fetch(`${API_BASE_URL}${url}`, {
        ...options,
        headers: {
            Accept: 'application/json',
            ...(options.body && !(options.body instanceof FormData) ? { 'Content-Type': 'application/json' } : {}),
            ...(token ? { Authorization: `Bearer ${token}` } : {}),
            ...options.headers,
        },
    })

    const data = await response.json().catch(() => ({}))
    if (!response.ok) {
        if (response.status === 401 && token) {
            localStorage.removeItem('auth_token')
            localStorage.removeItem('user')
            window.dispatchEvent(new CustomEvent('auth:expired'))
        }
        const error = new Error(data.message || Object.values(data.errors || {}).flat()[0] || 'The request failed.')
        error.details = data
        throw error
    }
    return data
}
