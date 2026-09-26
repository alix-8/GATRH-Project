import React from 'react';
import './AttendancePagination.css'

// Inline Chevron Icons matching the design spec
const ChevronLeftIcon = () => (
    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5" strokeLinecap="round" strokeLinejoin="round">
        <path d="M15 18l-6-6 6-6" />
    </svg>
);

const ChevronRightIcon = () => (
    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5" strokeLinecap="round" strokeLinejoin="round">
        <path d="M9 18l6-6-6-6" />
    </svg>
);

export const AttendancePagination = ({ page, meta, goToPage }) => {
    const totalPages = meta?.last_page || 1;
    const currentPage = meta?.current_page || page || 1;

    // Helper to generate page numbers with ellipses (...)
    const getPageNumbers = () => {
        const pages = [];
        if (totalPages <= 5) {
            for (let i = 1; i <= totalPages; i++) pages.push(i);
        } else {
            pages.push(1);
            if (currentPage > 3) pages.push('...');
            
            const start = Math.max(2, currentPage - 1);
            const end = Math.min(totalPages - 1, currentPage + 1);

            for (let i = start; i <= end; i++) {
                if (!pages.includes(i)) pages.push(i);
            }

            if (currentPage < totalPages - 2) pages.push('...');
            if (!pages.includes(totalPages)) pages.push(totalPages);
        }
        return pages;
    };

    return (
        <div className="attendance-pagination">
            <span>Showing {currentPage} out of {totalPages} pages</span>
            <div className="pagination-controls">
                {/* Previous Button */}
                <button 
                    onClick={() => goToPage(currentPage - 1)} 
                    disabled={currentPage === 1}
                    className="pagination-btn icon-btn"
                    aria-label="Previous Page"
                >
                    <ChevronLeftIcon />
                </button>

                {/* Dynamic Page Buttons */}
                {getPageNumbers().map((item, index) => {
                    if (item === '...') {
                        return (
                            <span key={`ellipsis-${index}`} className="pagination-ellipsis">
                                ...
                            </span>
                        );
                    }

                    return (
                        <button
                            key={item}
                            onClick={() => goToPage(item)}
                            className={`pagination-btn ${item === currentPage ? 'page-active' : ''}`}
                        >
                            {item}
                        </button>
                    );
                })}

                {/* Next Button */}
                <button 
                    onClick={() => goToPage(currentPage + 1)} 
                    disabled={currentPage === totalPages}
                    className="pagination-btn icon-btn"
                    aria-label="Next Page"
                >
                    <ChevronRightIcon />
                </button>
            </div>
        </div>
    );
};

export default AttendancePagination;