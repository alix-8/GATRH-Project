export default function SubmissionPagination({ firstItem, lastItem, total, page, lastPage, onPageChange, disabled }) {
    return (
        <footer className="ph-submissions-pagination">
            <span>Showing {firstItem} to {lastItem} of {total}</span>
            <div>
                <button type="button" aria-label="Previous page" disabled={page <= 1 || disabled} onClick={() => onPageChange((current) => Math.max(1, current - 1))}>‹</button>
                <strong>{page}</strong>
                <button type="button" aria-label="Next page" disabled={page >= lastPage || disabled} onClick={() => onPageChange((current) => Math.min(lastPage, current + 1))}>›</button>
            </div>
        </footer>
    )
}
