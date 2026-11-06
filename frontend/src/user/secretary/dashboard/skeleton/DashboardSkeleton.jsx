import './DashboardSkeleton.css'

function Placeholder({ className = '' }) {
    return <span className={`secretary-skeleton-block ${className}`} />
}

function DashboardContentSkeleton() {
    return (
        <div className="secretary-skeleton-content" aria-hidden="true">
            <section className="secretary-skeleton-hero">
                <div className="secretary-skeleton-hero-copy">
                    <Placeholder className="secretary-skeleton-hero-title" />
                    <Placeholder className="secretary-skeleton-hero-subtitle" />
                    <div className="secretary-skeleton-hero-stats">
                        <Placeholder /><Placeholder />
                    </div>
                </div>
                <Placeholder className="secretary-skeleton-hero-illustration" />
            </section>

            <section className="secretary-skeleton-kpis">
                {[0, 1, 2, 3].map(index => (
                    <div className="secretary-skeleton-kpi" key={index}>
                        <Placeholder className="secretary-skeleton-kpi-icon" />
                        <div className="secretary-skeleton-kpi-copy">
                            <Placeholder /><Placeholder /><Placeholder />
                        </div>
                    </div>
                ))}
            </section>

            <div className="secretary-skeleton-grid">
                <div className="secretary-skeleton-column">
                    <section className="secretary-skeleton-panel secretary-skeleton-chart">
                        <div className="secretary-skeleton-panel-heading">
                            <Placeholder className="secretary-skeleton-heading-line" />
                            <Placeholder className="secretary-skeleton-filter" />
                        </div>
                        <Placeholder className="secretary-skeleton-legend" />
                        <div className="secretary-skeleton-chart-bars">
                            {[0, 1, 2].map(index => <Placeholder key={index} />)}
                        </div>
                    </section>

                    <section className="secretary-skeleton-panel secretary-skeleton-records">
                        <div className="secretary-skeleton-panel-heading">
                            <Placeholder className="secretary-skeleton-heading-line" />
                            <Placeholder className="secretary-skeleton-filter" />
                        </div>
                        <Placeholder className="secretary-skeleton-records-header" />
                        {[0, 1, 2].map(index => (
                            <div className="secretary-skeleton-record-row" key={index}>
                                {[0, 1, 2, 3, 4].map(column => <Placeholder key={column} />)}
                            </div>
                        ))}
                    </section>
                </div>

                <div className="secretary-skeleton-column">
                    <section className="secretary-skeleton-panel secretary-skeleton-reminders">
                        <div className="secretary-skeleton-panel-heading">
                            <Placeholder className="secretary-skeleton-heading-line" />
                            <Placeholder className="secretary-skeleton-filter" />
                        </div>
                        {[0, 1, 2].map(index => (
                            <div className="secretary-skeleton-reminder-row" key={index}>
                                <Placeholder /><Placeholder /><Placeholder />
                            </div>
                        ))}
                    </section>

                    <section className="secretary-skeleton-panel secretary-skeleton-monthly">
                        <Placeholder className="secretary-skeleton-heading-line" />
                        <Placeholder className="secretary-skeleton-monthly-subtitle" />
                        {[0, 1, 2].map(index => (
                            <div className="secretary-skeleton-monthly-row" key={index}>
                                <Placeholder /><Placeholder />
                            </div>
                        ))}
                    </section>
                </div>
            </div>
        </div>
    )
}

export default function DashboardSkeleton({ contentOnly = false }) {
    if (contentOnly) {
        return (
            <div className="secretary-skeleton secretary-skeleton--content" role="status" aria-label="Loading secretary dashboard" aria-busy="true">
                <DashboardContentSkeleton />
            </div>
        )
    }

    return (
        <div className="secretary-skeleton" role="status" aria-label="Loading secretary dashboard" aria-busy="true">
            <div className="secretary-skeleton-layout">
                <aside className="secretary-skeleton-sidebar" aria-hidden="true">
                    <Placeholder className="secretary-skeleton-logo" />
                    <div className="secretary-skeleton-sidebar-links">
                        {[0, 1, 2, 3, 4].map(index => (
                            <div className="secretary-skeleton-sidebar-link" key={index}>
                                <Placeholder className="secretary-skeleton-nav-icon" />
                                <Placeholder className="secretary-skeleton-nav-label" />
                            </div>
                        ))}
                    </div>
                    <div className="secretary-skeleton-sidebar-footer">
                        <Placeholder className="secretary-skeleton-nav-label" />
                        <Placeholder className="secretary-skeleton-nav-icon" />
                    </div>
                </aside>

                <main className="secretary-skeleton-main">
                    <header className="secretary-skeleton-header" aria-hidden="true">
                        <div className="secretary-skeleton-heading">
                            <Placeholder className="secretary-skeleton-title" />
                            <Placeholder className="secretary-skeleton-subtitle" />
                        </div>
                        <div className="secretary-skeleton-date">
                            <Placeholder /><Placeholder />
                        </div>
                    </header>
                    <DashboardContentSkeleton />
                </main>
            </div>
        </div>
    )
}
