import { createReadRequestCoordinator } from './readRequestCoordinator'
import { createCsrfSession } from './csrfSession'

const reads = createReadRequestCoordinator()
let readGeneration = 0

export const getApiCooldownMs = () => reads.remainingMs()

export const API_BASE_URL = (
    import.meta.env.VITE_API_BASE_URL || 'http://localhost:8000/api'
).replace(/\/+$/, '')

export function xsrfHeaders() {
    const cookie = document.cookie.split('; ').find(value => value.startsWith('XSRF-TOKEN='))
    return cookie ? { 'X-XSRF-TOKEN': decodeURIComponent(cookie.slice('XSRF-TOKEN='.length)) } : {}
}

const csrfSession = createCsrfSession({
    fetchCookie: () => fetch(`${API_BASE_URL.replace(/\/api$/, '')}/sanctum/csrf-cookie`, { credentials: 'include' }),
    hasToken: () => Boolean(xsrfHeaders()['X-XSRF-TOKEN']),
})

export const initializeCsrf = options => csrfSession.prepare(options)
export const submitWithCsrf = send => csrfSession.submit(send)

async function sendRequest(url, options) {
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
        error.status = response.status
        if (response.status === 429) {
            reads.pause(response.headers.get('Retry-After'))
            error.retryAfter = Math.ceil(reads.remainingMs() / 1000)
            error.message = `Too many requests. Please try again in ${error.retryAfter} seconds.`
        }
        error.details = data
        throw error
    }
    return data
}

export function request(url, options = {}) {
    const method = (options.method || 'GET').toUpperCase()
    if (method !== 'GET') {
        // Reads started after a write must not share an older in-flight read.
        readGeneration++
        return sendRequest(url, options).finally(() => { readGeneration++ })
    }

    const { signal, ...readOptions } = options
    const key = JSON.stringify([localStorage.getItem('user'), readGeneration, url, readOptions])
    return reads.run(key, sharedSignal => sendRequest(url, { ...options, signal: sharedSignal }), signal)
}
