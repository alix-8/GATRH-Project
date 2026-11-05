import './ProgramTableSkeleton.css'

export default function ProgramTableSkeleton({ count = 5 }) {
    return Array.from({ length: count }, (_, index) => (
        <tr className="ph-loading-row" key={`program-table-skeleton-${index}`} aria-hidden="true">
            <td><span className="ph-loading-avatar" /><span className="ph-loading-block ph-loading-name" /></td>
            <td><span className="ph-loading-block ph-loading-gathering" /></td>
            <td><span className="ph-loading-block ph-loading-program" /></td>
            <td><span className="ph-loading-block ph-loading-year" /></td>
            <td><span className="ph-loading-block ph-loading-date" /></td>
            <td><span className="ph-loading-block ph-loading-status" /></td>
            <td><span className="ph-loading-block ph-loading-action" /></td>
        </tr>
    ))
}
