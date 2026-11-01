/** Share simultaneous reads only; never cache completed attendance responses. */
export function createReadRequestCoordinator({ now = Date.now } = {}) {
    const pending = new Map()
    let pausedUntil = 0

    function remainingMs() {
        return Math.max(0, pausedUntil - now())
    }

    function pause(retryAfter) {
        const seconds = Number(retryAfter)
        const delay = retryAfter !== null && String(retryAfter).trim() !== '' && Number.isFinite(seconds)
            ? Math.max(1000, seconds * 1000)
            : Math.max(1000, (Date.parse(retryAfter) || now() + 60_000) - now())
        pausedUntil = Math.max(pausedUntil, now() + delay)
    }

    function cooldownError() {
        const error = new Error(`Too many requests. Please try again in ${Math.ceil(remainingMs() / 1000)} seconds.`)
        error.status = 429
        error.retryAfter = Math.ceil(remainingMs() / 1000)
        return error
    }

    function run(key, load, signal) {
        if (signal?.aborted) return Promise.reject(new DOMException('Request aborted', 'AbortError'))
        if (remainingMs() > 0) return Promise.reject(cooldownError())

        let entry = pending.get(key)
        if (!entry) {
            const controller = new AbortController()
            entry = { controller, consumers: 0, promise: null }
            const current = entry
            entry.promise = Promise.resolve().then(() => {
                if (remainingMs() > 0) throw cooldownError()
                return load(controller.signal)
            }).finally(() => {
                if (pending.get(key) === current) pending.delete(key)
            })
            pending.set(key, entry)
        }

        entry.consumers++
        return new Promise((resolve, reject) => {
            let settled = false
            const release = () => {
                if (settled) return false
                settled = true
                signal?.removeEventListener('abort', abort)
                entry.consumers--
                if (entry.consumers === 0) {
                    entry.controller.abort()
                    if (pending.get(key) === entry) pending.delete(key)
                }
                return true
            }
            const abort = () => {
                if (release()) reject(new DOMException('Request aborted', 'AbortError'))
            }
            signal?.addEventListener('abort', abort, { once: true })
            entry.promise.then(
                value => { if (release()) resolve(value) },
                error => { if (release()) reject(error) },
            )
        })
    }

    return { run, pause, remainingMs, isBusy: () => pending.size > 0 }
}
