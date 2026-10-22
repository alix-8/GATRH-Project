import RecordDay from '../../../components/RecordDay'
import {
    ATTENDANCE_TIME_RE,
    displayAttendanceDay,
    getAutomaticAttendanceStatus,
    resolveAttendanceDay,
} from './attendanceEditValidation'
import './EditableAttendanceTable.css'

function editableValue(value) {
    return value && value !== '---' ? value : ''
}

export default function EditableAttendanceTable({ students, day, weekRange, editing, onStudentChange, emptyMessage }) {
    return (
        <table className={`drawer-table ${editing ? 'drawer-table-editing' : ''}`}>
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Time</th>
                    <th>Date</th>
                    <th>Location</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                {students.map((student, index) => {
                    const status = getAutomaticAttendanceStatus(student, weekRange)
                    const hasDay = student.day_provided === false ? false : Boolean(student.day && student.day !== '---')
                    const hasBatch = Boolean(student.batch && student.batch !== '---')
                    const hasVenue = Boolean(student.venue && student.venue !== '---')
                    const hasAnyAttendanceValue = hasDay || hasBatch || hasVenue
                    const incomplete = hasAnyAttendanceValue && (!hasDay || !hasBatch || !hasVenue)
                    const invalidTime = hasBatch && !ATTENDANCE_TIME_RE.test(student.batch.trim())
                    const invalidDay = hasDay && !resolveAttendanceDay(student.day, weekRange)
                    const timeError = invalidTime ? 'Batch time must be in a valid time format, e.g. "8AM" or "8:30 AM".' : ''
                    const dayError = invalidDay
                        ? `Day must be a weekday or date within the applicable week range (${weekRange?.start || '—'} to ${weekRange?.end || '—'}).`
                        : ''

                    return (
                    <tr key={student.student_id ?? index}>
                        <td title={student.student_name}>{student.student_name}</td>
                        <td>
                            {editing ? (
                                <input
                                    aria-label={`Time for ${student.student_name}`}
                                    type="text"
                                    className={`${invalidTime ? 'drawer-edit-field-error' : ''} ${incomplete && !hasBatch ? 'drawer-edit-field-warning' : ''}`}
                                    value={editableValue(student.batch)}
                                    placeholder="Time"
                                    title={timeError || (incomplete && !hasBatch ? 'Time is required when any attendance details are entered.' : '')}
                                    onChange={(event) => onStudentChange(student, 'batch', event.target.value)}
                                />
                            ) : <span title={student.batch || '—'}>{student.batch || '—'}</span>}
                        </td>
                        <td>
                            {editing ? (
                                <input
                                    aria-label={`Date for ${student.student_name}`}
                                    type="text"
                                    className={`${invalidDay ? 'drawer-edit-field-error' : ''} ${incomplete && !hasDay ? 'drawer-edit-field-warning' : ''}`}
                                    value={student.day_provided === false ? '' : displayAttendanceDay(editableValue(student.day || day))}
                                    placeholder="Date"
                                    title={dayError || (incomplete && !hasDay ? 'Date is required when any attendance details are entered.' : '')}
                                    onChange={(event) => onStudentChange(student, 'day', event.target.value)}
                                />
                            ) : <RecordDay value={resolveAttendanceDay(student.day, weekRange) || day} status={status} />}
                        </td>
                        <td>
                            {editing ? (
                                <input
                                    aria-label={`Location for ${student.student_name}`}
                                    type="text"
                                    className={incomplete && !hasVenue ? 'drawer-edit-field-warning' : ''}
                                    value={editableValue(student.venue)}
                                    placeholder="Location"
                                    title={incomplete && !hasVenue ? 'Location is required when any attendance details are entered.' : ''}
                                    onChange={(event) => onStudentChange(student, 'venue', event.target.value)}
                                />
                            ) : <span title={student.venue || '—'}>{student.venue || '—'}</span>}
                        </td>
                        <td>
                            <span
                                title={editing ? 'Status is set automatically when day, time, and venue are filled.' : undefined}
                                className={`status-indicator status-${status === 'present' ? 'present' : 'no-response'}`}
                            >
                                <span className="dot" /> {status === 'present' ? 'Present' : 'No response'}
                            </span>
                        </td>
                    </tr>
                    )
                })}
                {students.length === 0 && (
                    <tr><td colSpan="5" className="drawer-table-empty">{emptyMessage}</td></tr>
                )}
            </tbody>
        </table>
    )
}
