export default function SubmissionPagination({ page, lastPage, onPageChange, disabled }) {
    const pages = []

    if (lastPage <= 5) {
        for (let current = 1; current <= lastPage; current += 1) {
            pages.push(current)
        }
    } else {
        pages.push(1)

        if (page > 3) {
            pages.push('…')
        }

        for (
            let current = Math.max(2, page - 1);
            current <= Math.min(lastPage - 1, page + 1);
            current += 1
        ) {
            pages.push(current)
        }

        if (page < lastPage - 2) {
            pages.push('…')
        }

        pages.push(lastPage)
    }

    return (
        <footer className="ph-submissions-pagination">
            <span>Showing {page} out of {lastPage} pages</span>
            <div>
                <button
                    type="button"
                    aria-label="Previous page"
                    disabled={page <= 1 || disabled}
                    onClick={() => onPageChange((current) => Math.max(1, current - 1))}
                >
                    ‹
                </button>

                {pages.map((item, index) => item === '…' ? (
                    <span
                        key={`ellipsis-${index}`}
                        className="ph-submissions-pagination-ellipsis"
                    >
                        …
                    </span>
                ) : (
                    <button
                        type="button"
                        key={item}
                        aria-current={item === page ? 'page' : undefined}
                        className={item === page ? 'ph-submissions-current-page' : ''}
                        disabled={disabled}
                        onClick={() => onPageChange(item)}
                    >
                        {item}
                    </button>
                ))}

                <button
                    type="button"
                    aria-label="Next page"
                    disabled={page >= lastPage || disabled}
                    onClick={() => onPageChange((current) => Math.min(lastPage, current + 1))}
                >
                    ›
                </button>
            </div>
        </footer>
    )
}
