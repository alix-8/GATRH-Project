export const ATTENDANCE_TIME_RE = /^(?:(?:[1-9]|1[0-2])\s*(?:AM|PM)|\d{1,2}:\d{2}\s*(?:AM|PM)?)$/i

function hasValue(value) {
    return Boolean(value && String(value).trim() && String(value).trim() !== '---')
}

function hasProvidedDay(student) {
    return student.day_provided === false ? false : hasValue(student.day)
}

const WEEKDAYS = {
    sunday: 0, sun: 0,
    monday: 1, mon: 1,
    tuesday: 2, tue: 2, tues: 2,
    wednesday: 3, wed: 3,
    thursday: 4, thu: 4, thur: 4, thurs: 4,
    friday: 5, fri: 5,
    saturday: 6, sat: 6,
}

function toIsoDate(date) {
    return `${date.getUTCFullYear()}-${String(date.getUTCMonth() + 1).padStart(2, '0')}-${String(date.getUTCDate()).padStart(2, '0')}`
}

function isoDateIsValid(value) {
    if (!/^\d{4}-\d{2}-\d{2}$/.test(value || '')) return false
    const [year, month, day] = value.split('-').map(Number)
    const date = new Date(Date.UTC(year, month - 1, day))
    return date.getUTCFullYear() === year && date.getUTCMonth() === month - 1 && date.getUTCDate() === day
}

export function resolveAttendanceDay(value, weekRange) {
    const day = String(value ?? '').trim()
    if (!day || !weekRange?.start || !weekRange?.end) return null

    if (isoDateIsValid(day)) {
        return day >= weekRange.start && day <= weekRange.end ? day : null
    }

    const targetWeekday = WEEKDAYS[day.toLowerCase().replace(/\.$/, '')]
    if (targetWeekday === undefined) return null

    const matches = []
    const start = new Date(`${weekRange.start}T00:00:00Z`)
    const end = new Date(`${weekRange.end}T00:00:00Z`)
    for (let current = start; current <= end; current = new Date(current.getTime() + 86400000)) {
        if (current.getUTCDay() === targetWeekday) matches.push(toIsoDate(current))
    }

    return matches.length === 1 ? matches[0] : null
}

export function displayAttendanceDay(value) {
    if (isoDateIsValid(String(value ?? ''))) {
        const [year, month, day] = String(value).split('-').map(Number)
        return new Date(Date.UTC(year, month - 1, day)).toLocaleDateString('en-US', { weekday: 'short', timeZone: 'UTC' })
    }
    return value || ''
}

export function getAutomaticAttendanceStatus(student, weekRange) {
    const validTime = hasValue(student.batch) && ATTENDANCE_TIME_RE.test(String(student.batch).trim())
    const validDay = hasProvidedDay(student) && Boolean(resolveAttendanceDay(student.day, weekRange))
    return validDay && validTime && hasValue(student.venue) ? 'present' : 'no_response'
}

export function getAttendanceEditErrors(students, weekRange) {
    const formatErrors = []
    const incompleteRecords = []

    students.forEach((student) => {
        const hasDay = hasProvidedDay(student)
        const hasTime = hasValue(student.batch)
        const hasVenue = hasValue(student.venue)
        const anyFilled = hasDay || hasTime || hasVenue
        const missing = [
            !hasDay ? 'day' : null,
            !hasTime ? 'batch' : null,
            !hasVenue ? 'venue' : null,
        ].filter(Boolean)

        if (hasTime && !ATTENDANCE_TIME_RE.test(String(student.batch).trim())) {
            formatErrors.push({
                raw_line: student.student_name,
                errors: [{
                    field: 'batch',
                    value: student.batch,
                    reason: 'Batch time must be in a valid time format, e.g. "8AM" or "8:30 AM".',
                }],
            })
        }

        if (hasDay && !resolveAttendanceDay(student.day, weekRange)) {
            formatErrors.push({
                raw_line: student.student_name,
                errors: [{
                    field: 'day',
                    value: student.day,
                    reason: `Day must be a weekday or date within the applicable week range (${weekRange.start} to ${weekRange.end}).`,
                }],
            })
        }

        if (anyFilled && missing.length > 0) {
            incompleteRecords.push({
                student_name: student.student_name,
                raw_line: student.student_name,
                missing,
            })
        }
    })

    return { format_errors: formatErrors, incomplete_records: incompleteRecords }
}

export function hasAttendanceEditErrors(errors) {
    return errors.format_errors.length > 0 || errors.incomplete_records.length > 0
}
