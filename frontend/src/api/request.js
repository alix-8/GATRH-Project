export async function request(url, options = {}) {
    const token = localStorage.getItem('auth_token')
    const response = await fetch(`http://127.0.0.1:8000/api${url}`, {
        ...options,
        headers: {
            Accept: 'application/json',
            ...(options.body ? { 'Content-Type': 'application/json' } : {}),
            ...(token ? { Authorization: `Bearer ${token}` } : {}),
            ...options.headers,
        },
    })

    const data = await response.json().catch(() => ({}))
    if (!response.ok) {
        const error = new Error(data.message || Object.values(data.errors || {}).flat()[0] || 'The request failed.')
        error.details = data
        throw error
    }
    return data
}