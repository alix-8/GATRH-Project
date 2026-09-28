import './RecordsPagination.css'

export default function RecordsPagination({ page, lastPage, onPageChange, disabled = false }) {
    const pages = []
    if (lastPage <= 5) {
        for (let current = 1; current <= lastPage; current += 1) pages.push(current)
    } else {
        pages.push(1)
        if (page > 3) pages.push('…')
        for (let current = Math.max(2, page - 1); current <= Math.min(lastPage - 1, page + 1); current += 1) pages.push(current)
        if (page < lastPage - 2) pages.push('…')
        pages.push(lastPage)
    }

    return (
        <div className="ph-records-pagination">
            <span>Showing {page} out of {lastPage} pages</span>
            <div className="ph-records-pagination-controls">
                <button type="button" onClick={() => onPageChange(page - 1)} disabled={disabled || page === 1} aria-label="Previous page">‹</button>
                {pages.map((item, index) => item === '…'
                    ? <span key={`ellipsis-${index}`} className="ph-records-pagination-ellipsis">…</span>
                    : <button type="button" key={item} onClick={() => onPageChange(item)} disabled={disabled} className={item === page ? 'active' : ''} aria-current={item === page ? 'page' : undefined}>{item}</button>)}
                <button type="button" onClick={() => onPageChange(page + 1)} disabled={disabled || page === lastPage} aria-label="Next page">›</button>
            </div>
        </div>
    )
}
