import { test } from 'node:test'
import assert from 'node:assert/strict'
import { createReadRequestCoordinator } from '../src/api/readRequestCoordinator.js'

test('simultaneous identical reads share one operation without caching completed data', async () => {
    const reads = createReadRequestCoordinator()
    let calls = 0
    const load = async () => ({ version: ++calls })
    const results = await Promise.all([reads.run('reminders', load), reads.run('reminders', load)])
    assert.equal(calls, 1)
    assert.deepEqual(results, [{ version: 1 }, { version: 1 }])
    assert.deepEqual(await reads.run('reminders', load), { version: 2 })
})

test('distinct account, filter, or generation keys remain separate', async () => {
    const reads = createReadRequestCoordinator()
    let calls = 0
    await Promise.all(['user1/year1/v1', 'user1/year2/v1', 'user2/year1/v1', 'user1/year1/v2']
        .map(key => reads.run(key, async () => ++calls)))
    assert.equal(calls, 4)
})

test('one cancelled consumer does not cancel another consumers shared read', async () => {
    const reads = createReadRequestCoordinator()
    const first = new AbortController()
    const second = new AbortController()
    let finish
    let underlyingSignal
    const load = signal => {
        underlyingSignal = signal
        return new Promise(resolve => { finish = resolve })
    }
    const cancelled = reads.run('dashboard', load, first.signal)
    const cancelledCheck = assert.rejects(cancelled, { name: 'AbortError' })
    const active = reads.run('dashboard', load, second.signal)
    await Promise.resolve()
    first.abort()
    assert.equal(underlyingSignal.aborted, false)
    finish({ year: 2 })
    await cancelledCheck
    assert.deepEqual(await active, { year: 2 })
})

test('cancelling all consumers aborts the network and allows a fresh read', async () => {
    const reads = createReadRequestCoordinator()
    const controller = new AbortController()
    let networkSignal
    const cancelled = reads.run('dashboard', signal => {
        networkSignal = signal
        return new Promise((resolve, reject) => {
            signal.addEventListener('abort', () => reject(new DOMException('Aborted', 'AbortError')))
        })
    }, controller.signal)
    const check = assert.rejects(cancelled, { name: 'AbortError' })
    await Promise.resolve()
    controller.abort()
    await check
    assert.equal(networkSignal.aborted, true)
    assert.equal(await reads.run('dashboard', async () => 'fresh'), 'fresh')
})

test('Retry-After seconds pauses all reads and resumes when the cooldown expires', async () => {
    let clock = 100_000
    const reads = createReadRequestCoordinator({ now: () => clock })
    let calls = 0
    reads.pause('30')
    await assert.rejects(reads.run('dashboard', async () => ++calls), error => error.status === 429 && error.retryAfter === 30)
    await assert.rejects(reads.run('reminders', async () => ++calls), { status: 429 })
    assert.equal(calls, 0)
    clock += 30_000
    assert.equal(reads.remainingMs(), 0)
    assert.equal(await reads.run('dashboard', async () => ++calls), 1)
})

test('Retry-After supports HTTP dates and a missing-header fallback', () => {
    const clock = Date.parse('2026-11-01T00:00:00Z')
    const dated = createReadRequestCoordinator({ now: () => clock })
    dated.pause('Sun, 01 Nov 2026 00:00:45 GMT')
    assert.equal(dated.remainingMs(), 45_000)
    for (const header of [null, '', 'invalid']) {
        const reads = createReadRequestCoordinator({ now: () => clock })
        reads.pause(header)
        assert.equal(reads.remainingMs(), 60_000)
    }
    dated.pause('1')
    assert.equal(dated.remainingMs(), 45_000)
})

test('failed shared reads are not retained or automatically retried', async () => {
    const reads = createReadRequestCoordinator()
    let calls = 0
    const failure = async () => { calls++; throw new Error('Unavailable') }
    const results = await Promise.allSettled([reads.run('dashboard', failure), reads.run('dashboard', failure)])
    assert.equal(calls, 1)
    assert.ok(results.every(result => result.status === 'rejected'))
    assert.equal(await reads.run('dashboard', async () => 'recovered'), 'recovered')
})

test('already-aborted callers do not start a request', async () => {
    const reads = createReadRequestCoordinator()
    const controller = new AbortController()
    controller.abort()
    let calls = 0
    await assert.rejects(reads.run('dashboard', async () => ++calls, controller.signal), { name: 'AbortError' })
    assert.equal(calls, 0)
})

test('background polling can detect a pending read and resume after completion', async () => {
    const reads = createReadRequestCoordinator()
    let finish
    assert.equal(reads.isBusy(), false)
    const pending = reads.run('dashboard', () => new Promise(resolve => { finish = resolve }))
    assert.equal(reads.isBusy(), true)
    await Promise.resolve()
    finish('loaded')
    await pending
    assert.equal(reads.isBusy(), false)
})
