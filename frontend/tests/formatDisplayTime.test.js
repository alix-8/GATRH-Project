import test from 'node:test'
import assert from 'node:assert/strict'
import formatDisplayTime from '../src/utils/formatDisplayTime.js'

test('saved record and draft time formats display consistently', () => {
    for (const value of ['18:09', '18:09:00', '6:09pm', '06:09 pm', '6:09 PM']) {
        assert.equal(formatDisplayTime(value), '6:09 PM')
    }
    assert.equal(formatDisplayTime('6PM'), '6:00 PM')
    assert.equal(formatDisplayTime('06:09'), '6:09 AM')
})

test('midnight and noon retain their correct AM/PM meaning', () => {
    assert.equal(formatDisplayTime('00:00'), '12:00 AM')
    assert.equal(formatDisplayTime('12:00'), '12:00 PM')
    assert.equal(formatDisplayTime('12AM'), '12:00 AM')
    assert.equal(formatDisplayTime('12PM'), '12:00 PM')
})

test('missing times keep the requested placeholder and unexpected values remain visible', () => {
    for (const value of [null, undefined, '', '—', '---']) {
        assert.equal(formatDisplayTime(value, '---'), '---')
    }
    for (const value of ['24:00', '13:00 PM', '6:99 PM', 'unknown']) {
        assert.equal(formatDisplayTime(value), value)
    }
})
