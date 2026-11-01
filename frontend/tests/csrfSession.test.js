import { test } from 'node:test'
import assert from 'node:assert/strict'
import { createCsrfSession } from '../src/api/csrfSession.js'

test('page preparation and sign-in share one CSRF request', async () => {
    let token = false
    let calls = 0
    const csrf = createCsrfSession({
        hasToken: () => token,
        fetchCookie: async () => { calls++; token = true; return { ok: true } },
    })
    const prepare = csrf.prepare()
    const login = csrf.submit(async () => ({ status: 200 }))
    await prepare
    assert.equal((await login).status, 200)
    await csrf.submit(async () => ({ status: 401 }))
    assert.equal(calls, 1)
})

test('an expired token is refreshed and login retries exactly once', async () => {
    let prepared = 0
    let submitted = 0
    const csrf = createCsrfSession({ hasToken: () => true, fetchCookie: async () => { prepared++; return { ok: true } } })
    const response = await csrf.submit(async () => ({ status: ++submitted === 1 ? 419 : 200 }))
    assert.equal(response.status, 200)
    assert.equal(prepared, 2)
    assert.equal(submitted, 2)
    submitted = 0
    await csrf.submit(async () => { submitted++; return { status: 419 } })
    assert.equal(submitted, 2)
})

test('incorrect credentials and rate limits never automatically retry', async () => {
    const csrf = createCsrfSession({ hasToken: () => true, fetchCookie: async () => ({ ok: true }) })
    for (const status of [401, 403, 422, 429, 503]) {
        let calls = 0
        const response = await csrf.submit(async () => { calls++; return { status } })
        assert.equal(response.status, status)
        assert.equal(calls, 1)
    }
})

test('failed CSRF preparation is recoverable and never sends login prematurely', async () => {
    let attempts = 0
    let submissions = 0
    const csrf = createCsrfSession({ hasToken: () => true, fetchCookie: async () => ({ ok: ++attempts > 1 }) })
    const send = async () => { submissions++; return { status: 200 } }
    await assert.rejects(csrf.submit(send), /prepare your sign-in session/)
    assert.equal(submissions, 0)
    assert.equal((await csrf.submit(send)).status, 200)
})

test('a removed cookie causes CSRF preparation again', async () => {
    let token = false
    let calls = 0
    const csrf = createCsrfSession({ hasToken: () => token, fetchCookie: async () => { token = true; calls++; return { ok: true } } })
    await csrf.prepare()
    token = false
    await csrf.prepare()
    assert.equal(calls, 2)
})
