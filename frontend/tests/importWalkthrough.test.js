import test from 'node:test'
import assert from 'node:assert/strict'
import { importWalkthroughPhase, importWalkthroughPaused, importWalkthroughPosition } from '../src/pages/secretary/import/walkthrough/importWalkthroughSteps.js'

test('guide follows actual import state, including returning to raw text', () => {
    assert.equal(importWalkthroughPhase({ modal: 'select-week' }), 0)
    assert.equal(importWalkthroughPhase({ modal: null, hasText: false }), 1)
    assert.equal(importWalkthroughPhase({ modal: 'generate' }), 2)
    assert.equal(importWalkthroughPhase({ modal: null, hasText: true }), 3)
    assert.equal(importWalkthroughPhase({ modal: null, hasText: true, isPreviewing: true }), 4)
    assert.equal(importWalkthroughPhase({ modal: null, hasText: true, isPreviewing: false }), 3)
    assert.equal(importWalkthroughPhase({ modal: 'success', isPreviewing: true, hasText: true }), 6)
})

test('errors, confirmations and loading pause the guide', () => {
    for (const modal of ['error', 'confirm-blank', 'confirm-override']) {
        assert.equal(importWalkthroughPaused({ modal, busy: false }), true)
    }
    assert.equal(importWalkthroughPaused({ modal: null, busy: true }), true)
    assert.equal(importWalkthroughPaused({ modal: 'generate', busy: false }), false)
    assert.equal(importWalkthroughPaused({ modal: null, busy: false }), false)
})

test('instruction card stays inside mobile, tablet, desktop and short viewports', () => {
    for (const [width, height] of [[320, 568], [390, 844], [460, 700], [720, 1024], [900, 700], [1440, 900], [844, 390]]) {
        for (const rect of [
            { left: 12, right: width - 12, top: 20, bottom: 160 },
            { left: width / 2, right: width - 12, top: height - 100, bottom: height - 12 },
            { left: 12, right: 160, top: 200, bottom: 240 },
        ]) {
            const card = importWalkthroughPosition(rect, { width, height })
            assert.ok(card.left >= 12)
            assert.ok(card.left + card.width <= width - 12)
            assert.ok(card.top >= 12)
            assert.ok(card.top + Math.min(300, height * .45) <= height - 12)
        }
    }
})
