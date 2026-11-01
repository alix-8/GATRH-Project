import test from 'node:test'
import assert from 'node:assert/strict'
import { importWalkthroughPhase, importWalkthroughPaused, importWalkthroughPosition, importWalkthroughStepIndex, importWalkthroughSteps, importWalkthroughCanAdvance } from '../src/user/secretary/import/walkthrough/importWalkthroughSteps.js'

test('guide follows actual import state, including returning to raw text', () => {
    assert.equal(importWalkthroughPhase({ modal: 'select-week' }), importWalkthroughStepIndex('week'))
    assert.equal(importWalkthroughPhase({ modal: null, hasText: false }), importWalkthroughStepIndex('week'))
    assert.equal(importWalkthroughPhase({ modal: 'generate' }), importWalkthroughStepIndex('verify-gathering'))
    assert.equal(importWalkthroughPhase({ modal: null, hasText: true }), importWalkthroughStepIndex('preview'))
    assert.equal(importWalkthroughPhase({ modal: null, hasText: true, isPreviewing: true }), importWalkthroughStepIndex('review'))
    assert.equal(importWalkthroughPhase({ modal: null, hasText: true, isPreviewing: false }), importWalkthroughStepIndex('preview'))
    assert.equal(importWalkthroughPhase({ modal: 'success', isPreviewing: true, hasText: true }), importWalkthroughStepIndex('success'))
})

test('errors, confirmations and loading pause the guide', () => {
    for (const modal of ['error', 'confirm-blank', 'confirm-override']) {
        assert.equal(importWalkthroughPaused({ modal, busy: false }), true)
    }
    assert.equal(importWalkthroughPaused({ modal: null, busy: true }), true)
    assert.equal(importWalkthroughPaused({ modal: 'generate', busy: false }), false)
    assert.equal(importWalkthroughPaused({ modal: null, busy: false }), false)
})

test('week guide sits below the section and aligns with Change week', () => {
    const section = { left: 20, right: 1380, top: 20, bottom: 180 }
    const button = { left: 1200, right: 1360, top: 60, bottom: 110 }
    const card = importWalkthroughPosition(button, { width: 1440, height: 900 }, { belowSection: section })
    assert.equal(card.top, 192)
    assert.equal(card.left + card.width, button.right)
    assert.ok(card.top > section.bottom)
    const mobile = importWalkthroughPosition(
        { left: 220, right: 360, top: 280, bottom: 320 },
        { width: 390, height: 568 },
        { belowSection: { bottom: 340 } },
    )
    assert.ok(mobile.top > 340)
    assert.ok(mobile.top + mobile.maxHeight <= 556)
})

test('selected week remains available for validation before continuing', () => {
    const step = importWalkthroughSteps[importWalkthroughPhase({ modal: null, hasText: false })]
    assert.equal(step.id, 'week')
    assert.equal(step.target, '[data-import-guide="change-week"]')
    assert.equal(step.spotlight, '[data-import-guide="week-summary"]')
    assert.equal(step.anchorToTarget, true)
    assert.equal(step.selectWeekModalTarget, '[data-import-guide="week"]')
    assert.equal(importWalkthroughCanAdvance(step, { modal: null, hasText: false }), true)
    assert.equal(importWalkthroughCanAdvance(step, { modal: 'select-week', hasText: false }), false)
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


test('inserting another step does not shift workflow destinations', () => {
    const steps = [...importWalkthroughSteps]
    steps.splice(1, 0, { id: 'extra-instruction', advance: 'next' })
    for (const [state, id] of [
        [{ modal: 'select-week' }, 'week'],
        [{ modal: 'generate' }, 'verify-gathering'],
        [{ modal: null, hasText: true }, 'preview'],
        [{ modal: null, isPreviewing: true }, 'review'],
        [{ modal: 'success' }, 'success'],
    ]) {
        assert.equal(steps[importWalkthroughPhase(state, steps)].id, id)
    }
})

test('modal gathering step targets its dropdown and main preparation stays reachable', () => {
    const gathering = importWalkthroughStepIndex('gathering')
    assert.equal(importWalkthroughSteps[gathering + 1].id, 'prepare')
    const modalStep = importWalkthroughSteps[importWalkthroughPhase({ modal: 'generate' })]
    assert.equal(modalStep.id, 'verify-gathering')
    assert.equal(modalStep.target, '[data-import-guide="format-gathering"]')
    assert.equal(importWalkthroughCanAdvance(modalStep, { modal: 'generate', hasText: true }), true)
    assert.equal(importWalkthroughCanAdvance(modalStep, { modal: 'generate', hasText: false }), true)
    const nextStep = importWalkthroughSteps[importWalkthroughStepIndex('verify-gathering') + 1]
    assert.equal(nextStep.id, 'copy-format')
    assert.equal(nextStep.target, '[data-import-guide="copy"]')
    assert.equal(importWalkthroughCanAdvance(nextStep, { modal: 'generate', hasText: false }), true)
    const actionsStep = importWalkthroughSteps[importWalkthroughStepIndex('copy-format') + 1]
    assert.equal(actionsStep.id, 'format-actions')
    assert.equal(actionsStep.target, '[data-import-guide="format"]')
    assert.equal(importWalkthroughCanAdvance(actionsStep, { modal: 'generate', hasText: false }), false)
    assert.equal(importWalkthroughSteps[importWalkthroughStepIndex('format-actions') + 1].id, 'preview')
    const prepare = importWalkthroughSteps[importWalkthroughStepIndex('prepare')]
    assert.equal(importWalkthroughCanAdvance(prepare, { modal: null, hasText: false }), false)
    assert.equal(importWalkthroughCanAdvance(prepare, { modal: null, hasText: true }), true)
    assert.equal(new Set(importWalkthroughSteps.map(step => step.id)).size, importWalkthroughSteps.length)
})
