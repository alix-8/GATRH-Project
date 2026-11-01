import { afterEach, test } from 'node:test'
import assert from 'node:assert/strict'
import { clearLoginSkeleton, isLoginSkeletonPending, prepareLoginSkeleton } from '../src/utils/loginSkeletonState.js'

const originalStorage = globalThis.sessionStorage

function mockStorage() {
    const values = new Map()
    globalThis.sessionStorage = {
        getItem: key => values.get(key) ?? null,
        setItem: (key, value) => values.set(key, value),
        removeItem: key => values.delete(key),
    }
}

afterEach(() => {
    if (originalStorage === undefined) delete globalThis.sessionStorage
    else globalThis.sessionStorage = originalStorage
})

test('login enables the first full-page skeleton, but later navigation does not', () => {
    mockStorage()
    assert.equal(isLoginSkeletonPending(), false)
    for (const role of ['secretary', 'cm_coordinator']) {
        prepareLoginSkeleton(role)
        assert.equal(isLoginSkeletonPending(), true)
        // Render checks must not consume the flag before the mounting effect.
        assert.equal(isLoginSkeletonPending(), true)
        clearLoginSkeleton()
        assert.equal(isLoginSkeletonPending(), false)
    }
})

test('a new login resets loading feedback and other roles clear stale flags', () => {
    mockStorage()
    prepareLoginSkeleton('secretary')
    clearLoginSkeleton()
    prepareLoginSkeleton('secretary')
    assert.equal(isLoginSkeletonPending(), true)
    prepareLoginSkeleton('program_head')
    assert.equal(isLoginSkeletonPending(), false)
})

test('unavailable storage does not interrupt authentication or navigation', () => {
    globalThis.sessionStorage = {
        getItem() { throw new Error('Unavailable') },
        setItem() { throw new Error('Unavailable') },
        removeItem() { throw new Error('Unavailable') },
    }
    assert.doesNotThrow(() => prepareLoginSkeleton('cm_coordinator'))
    assert.equal(isLoginSkeletonPending(), false)
    assert.doesNotThrow(clearLoginSkeleton)
})
