import {
    ATTENDANCE_TIME_RE,
    attendanceTimeError,
    resolveAttendanceDay,
} from '../../attendance/attendanceEditValidation'

const REQUIRED_MESSAGES = {
    day: 'Day is required',
    date: 'Day is required',
    time: 'Time is required',
    batch: 'Time is required',
    venue: 'Venue is required',
}

const TIME_FORMAT_MESSAGE = 'Use 4:30 AM'

function matchingErrorEntries(errors, studentName, gatheringType) {
    return errors?.format_errors?.filter((entry) => {
        const matchesStudent = entry.student_name === studentName || entry.raw_line?.includes(studentName)
        const matchesGathering = !entry.gathering_type || entry.gathering_type === gatheringType
        return matchesStudent && matchesGathering
    }) || []
}

function fieldNames(field) {
    return field === 'time' ? ['time', 'batch'] : field === 'day' ? ['day', 'date'] : [field]
}

function matchingError(entry, field) {
    const names = fieldNames(field)
    if (names.includes(entry.field)) return entry
    return (entry.errors || []).find((error) => names.includes(error.field))
}

function conciseServerMessage(reason, field, rangeLabel) {
    const normalized = String(reason || '').toLowerCase()

    if (normalized.includes('9 characters')) return 'Use 9 characters or fewer'
    if (normalized.includes('10 characters')) return 'Use 10 characters or fewer'
    if (normalized.includes('future date')) return 'Future dates aren’t allowed'
    if (field === 'day' && (normalized.includes('week range') || normalized.includes('applicable week') || normalized.includes('selected calendar week'))) {
        return rangeLabel ? `Allowed date range: ${rangeLabel}` : 'Use a valid day'
    }
    if (field === 'day') return 'Use a valid day'
    if (field === 'time' || field === 'batch') return TIME_FORMAT_MESSAGE

    return reason || ''
}

export function inlineImportError({ errors, studentName, gatheringType, field, value, venue, range, rangeLabel }) {
    const input = String(value ?? '').trim()

    if ((field === 'time' || field === 'batch') && input) {
        if (input.length > 10) return 'Use 10 characters or fewer'
        if (!ATTENDANCE_TIME_RE.test(input)) return TIME_FORMAT_MESSAGE

        const timeError = attendanceTimeError(value, gatheringType, venue)
        if (timeError) {
            const earliestTime = timeError.match(/must be (.+) or later\./)?.[1]
            return `Earliest ${String(gatheringType).toUpperCase()} time: ${earliestTime}`
        }
    }

    if (field === 'day' && input) {
        if (input.length > 9) return 'Use 9 characters or fewer'
        if (range && !resolveAttendanceDay(input, range, venue)) {
            if (/^\d{4}-\d{2}-\d{2}$/.test(input) && input > todayIso()) return 'Future dates aren’t allowed'
            return isDayOrDate(input) ? `Allowed date range: ${rangeLabel}` : 'Use a valid day'
        }
    }

    const error = matchingErrorEntries(errors, studentName, gatheringType)
        .map((entry) => matchingError(entry, field))
        .filter((entry) => entry && (entry.value === undefined || String(entry.value).trim() === String(value ?? '').trim()))
        .find(Boolean)
    if (error) return conciseServerMessage(error.reason, field, rangeLabel)

    const incomplete = errors?.incomplete_records?.find((entry) =>
        entry.student_name === studentName && (!entry.gathering_type || entry.gathering_type === gatheringType)
    )
    if (!String(value ?? '').trim() && incomplete?.missing?.some((missingField) => fieldNames(field).includes(missingField))) {
        return REQUIRED_MESSAGES[field] || ''
    }

    return ''
}

function todayIso() {
    return new Intl.DateTimeFormat('en-CA', {
        timeZone: 'Asia/Manila', year: 'numeric', month: '2-digit', day: '2-digit',
    }).format(new Date())
}

function isDayOrDate(value) {
    return /^\d{4}-\d{2}-\d{2}$/.test(value)
        || /^(sun(day)?|mon(day)?|tue(s|sday)?|wed(nesday)?|thu(r|rs|rsday)?|fri(day)?|sat(urday)?)$/i.test(value)
}

export function hasInlineImportError(options) {
    return Boolean(inlineImportError(options))
}

export { TIME_FORMAT_MESSAGE }
