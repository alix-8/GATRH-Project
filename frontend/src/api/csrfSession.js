/** Prepare one CSRF session at a time and reuse it until the server rejects it. */
export function createCsrfSession({ fetchCookie, hasToken }) {
    let pending = null
    let prepared = false

    function prepare({ force = false } = {}) {
        if (pending) return pending
        if (!force && prepared && hasToken()) return Promise.resolve()

        prepared = false
        pending = Promise.resolve().then(fetchCookie).then(response => {
            if (!response.ok) throw new Error('Unable to prepare your sign-in session. Please try again.')
            prepared = true
        }).finally(() => { pending = null })
        return pending
    }

    async function submit(send) {
        await prepare()
        let response = await send()
        // A rejected CSRF check has not authenticated the user. Refresh and
        // retry once; never retry incorrect passwords or other failures.
        if (response.status === 419) {
            await prepare({ force: true })
            response = await send()
        }
        return response
    }

    return { prepare, submit }
}
