export const ATTENDANCE_TIME_RE = /^(?:(?:[1-9]|1[0-2])\s*(?:AM|PM)|\d{1,2}:\d{2}\s*(?:AM|PM)?)$/i

const MINIMUM_GATHERING_TIMES = {
    pm: { minutes: 3 * 60, label: 'Prayer Meeting', time: '3:00 AM' },
    ws: { minutes: 3 * 60, label: 'Worship Service', time: '3:00 AM' },
    tg: { minutes: 16 * 60, label: 'Thanksgiving', time: '4:00 PM' },
}

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

export function isSpecialVenue(venue) {
    return String(venue ?? '').trim().toLowerCase() === 'special'
}

function attendanceTimeMinutes(value) {
    const match = String(value ?? '').trim().match(/^(?:(\d{1,2})\s*(AM|PM)|(\d{1,2}):(\d{2})\s*(AM|PM)?)$/i)
    if (!match) return null

    const hour = Number(match[1] ?? match[3])
    const minute = Number(match[4] ?? 0)
    const meridiem = (match[2] ?? match[5] ?? '').toUpperCase()
    if (minute > 59) return null

    if (meridiem) {
        if (hour < 1 || hour > 12) return null
        return ((hour % 12) + (meridiem === 'PM' ? 12 : 0)) * 60 + minute
    }

    return hour <= 23 ? hour * 60 + minute : null
}

export function attendanceTimeError(value, gatheringType, venue) {
    if (!hasValue(value) || !ATTENDANCE_TIME_RE.test(String(value).trim()) || isSpecialVenue(venue)) return ''
    const minimum = MINIMUM_GATHERING_TIMES[String(gatheringType ?? '').toLowerCase()]
    const minutes = attendanceTimeMinutes(value)
    if (!minimum || minutes === null || minutes >= minimum.minutes) return ''
    return `${minimum.label} attendance time must be ${minimum.time} or later.`
}

function todayIso() {
    return new Intl.DateTimeFormat('en-CA', {
        timeZone: 'Asia/Manila', year: 'numeric', month: '2-digit', day: '2-digit',
    }).format(new Date())
}

export function resolveAttendanceDay(value, weekRange, venue) {
    const day = String(value ?? '').trim()
    if (!day) return null

    if (isoDateIsValid(day)) {
        if (day > todayIso()) return null
        return isSpecialVenue(venue) || (day >= weekRange?.start && day <= weekRange?.end) ? day : null
    }

    if (!weekRange?.start || !weekRange?.end) return null

    const targetWeekday = WEEKDAYS[day.toLowerCase().replace(/\.$/, '')]
    if (targetWeekday === undefined) return null

    const matches = []
    const start = new Date(`${weekRange.start}T00:00:00Z`)
    let end = new Date(`${weekRange.end}T00:00:00Z`)
    if (isSpecialVenue(venue)) {
        start.setUTCDate(start.getUTCDate() - (start.getUTCDay() + 6) % 7)
        end = new Date(start.getTime() + 6 * 86400000)
    }
    for (let current = start; current <= end; current = new Date(current.getTime() + 86400000)) {
        if (current.getUTCDay() === targetWeekday) matches.push(toIsoDate(current))
    }

    return matches.length === 1 && matches[0] <= todayIso() ? matches[0] : null
}

export function attendanceDayError(value, weekRange, venue) {
    if (resolveAttendanceDay(value, weekRange, venue)) return ''
    return isSpecialVenue(venue)
        ? 'Enter a valid date (YYYY-MM-DD) or weekday in the selected calendar week. Future dates are not allowed.'
        : `Day must be a weekday or date within the applicable week range (${weekRange?.start || '—'} to ${weekRange?.end || '—'}). Future dates are not allowed.`
}

export function displayAttendanceDay(value, venue) {
    if (isSpecialVenue(venue)) return value || ''
    if (isoDateIsValid(String(value ?? ''))) {
        const [year, month, day] = String(value).split('-').map(Number)
        return new Date(Date.UTC(year, month - 1, day)).toLocaleDateString('en-US', { weekday: 'short', timeZone: 'UTC' })
    }
    return value || ''
}

export function getAutomaticAttendanceStatus(student, weekRange, gatheringType) {
    const validTime = hasValue(student.batch) && ATTENDANCE_TIME_RE.test(String(student.batch).trim())
        && !attendanceTimeError(student.batch, gatheringType, student.venue)
    const validDay = hasProvidedDay(student) && Boolean(resolveAttendanceDay(student.day, weekRange, student.venue))
    return validDay && validTime && hasValue(student.venue) ? 'present' : 'no_response'
}

export function getAttendanceEditErrors(students, weekRange, gatheringType) {
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

        const timeError = attendanceTimeError(student.batch, gatheringType, student.venue)
        if (hasTime && timeError) {
            formatErrors.push({
                raw_line: student.student_name,
                errors: [{ field: 'batch', value: student.batch, reason: timeError }],
            })
        }

        if (hasDay && !resolveAttendanceDay(student.day, weekRange, student.venue)) {
            formatErrors.push({
                raw_line: student.student_name,
                errors: [{
                    field: 'day',
                    value: student.day,
                    reason: attendanceDayError(student.day, weekRange, student.venue),
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
