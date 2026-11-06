import LoadingCircle from '../../../../components/feedback/LoadingCircle'
import './ActivityTable.css'

const dateFormatter = new Intl.DateTimeFormat('en-US', { month: 'long', day: 'numeric', year: 'numeric' })
const timeFormatter = new Intl.DateTimeFormat('en-US', { hour: 'numeric', minute: '2-digit', hour12: true })

function ActivityTable({ activities, loading = false }) {
    return <div className="admin-activity-table-wrap">
        <table className="admin-activity-table">
            <thead><tr><th scope="col">Date</th><th scope="col">Time</th><th scope="col">Module</th><th scope="col">Performed by</th><th scope="col">Result</th></tr></thead>
            <tbody>
                {(activities ?? []).map(activity => {
                    const date = activity.created_at ? new Date(activity.created_at) : null
                    const isValidDate = date && !Number.isNaN(date.getTime())
                    return <tr key={activity.id}>
                        <td>{isValidDate ? dateFormatter.format(date) : '—'}</td>
                        <td>{isValidDate ? timeFormatter.format(date).replace(/\s/g, '').toLowerCase() : '—'}</td>
                        <td>{activity.module}</td>
                        <td className="admin-activity-actor">{activity.performed_by}</td>
                        <td><span className={`admin-activity-result${activity.result === 'Failed' ? ' failed' : ''}`}>{activity.result}</span></td>
                    </tr>
                })}
                {!loading && activities?.length === 0 && <tr><td className="admin-activity-empty" colSpan="5">No activity has been recorded yet.</td></tr>}
                {loading && <tr><td className="admin-activity-empty" colSpan="5"><LoadingCircle label="Loading activity…" size={20} /></td></tr>}
            </tbody>
        </table>
    </div>
}

export default ActivityTable
