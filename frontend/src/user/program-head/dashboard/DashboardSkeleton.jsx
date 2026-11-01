import './DashboardSkeleton.css'

function Placeholder({ className = '' }) {
    return <span className={`ph-skeleton-block ${className}`} />
}

export default function DashboardSkeleton({ programSecretary = false }) {
    return (
        <div className="ph-skeleton" role="status" aria-label="Loading program dashboard" aria-busy="true">
            <div className="ph-skeleton-layout" aria-hidden="true">
                <aside className="ph-skeleton-sidebar">
                    <Placeholder className="ph-skeleton-logo" />
                    <div className="ph-skeleton-nav">
                        {[0, 1, 2, 3].map(index => (
                            <div className="ph-skeleton-nav-link" key={index}>
                                <Placeholder className="ph-skeleton-nav-icon" />
                                <Placeholder className="ph-skeleton-nav-label" />
                            </div>
                        ))}
                    </div>
                    <div className="ph-skeleton-sidebar-footer">
                        <Placeholder className="ph-skeleton-nav-label" />
                        <Placeholder className="ph-skeleton-nav-icon" />
                    </div>
                </aside>

                <main className="ph-skeleton-main">
                    <header className="ph-skeleton-header">
                        <div className="ph-skeleton-heading">
                            <Placeholder className="ph-skeleton-title" />
                            <Placeholder className="ph-skeleton-subtitle" />
                        </div>
                        <div className="ph-skeleton-date">
                            <Placeholder /><Placeholder />
                        </div>
                    </header>

                    <div className="ph-skeleton-content">
                        <section className={`ph-skeleton-metrics${programSecretary ? ' ph-skeleton-metrics-three' : ''}`}>
                            {Array.from({ length: programSecretary ? 3 : 4 }, (_, index) => (
                                <div className="ph-skeleton-metric" key={index}>
                                    <Placeholder className="ph-skeleton-metric-icon" />
                                    <div className="ph-skeleton-metric-copy">
                                        <Placeholder /><Placeholder /><Placeholder />
                                    </div>
                                </div>
                            ))}
                        </section>

                        <section className="ph-skeleton-panel ph-skeleton-pending">
                            <div className="ph-skeleton-panel-heading">
                                <div className="ph-skeleton-panel-copy">
                                    <Placeholder className="ph-skeleton-panel-title" />
                                    <Placeholder className="ph-skeleton-panel-subtitle" />
                                </div>
                                <Placeholder className="ph-skeleton-panel-action" />
                            </div>
                            <div className="ph-skeleton-table-scroll">
                                <div className="ph-skeleton-table-heading">
                                    {[0, 1, 2, 3, 4, 5, 6].map(index => <Placeholder key={index} />)}
                                </div>
                                {[0, 1].map(index => (
                                    <div className="ph-skeleton-table-row" key={index}>
                                        {[0, 1, 2, 3, 4, 5, 6].map(column => <Placeholder key={column} />)}
                                    </div>
                                ))}
                            </div>
                        </section>

                        <section className="ph-skeleton-panel ph-skeleton-activity">
                            <div className="ph-skeleton-panel-copy">
                                <Placeholder className="ph-skeleton-panel-title" />
                                <Placeholder className="ph-skeleton-panel-subtitle" />
                            </div>
                            <div className="ph-skeleton-activity-list">
                                {[0, 1, 2, 3].map(index => (
                                    <div className="ph-skeleton-activity-item" key={index}>
                                        <Placeholder className="ph-skeleton-activity-icon" />
                                        <div className="ph-skeleton-activity-copy">
                                            <Placeholder /><Placeholder />
                                        </div>
                                        <Placeholder className="ph-skeleton-activity-time" />
                                    </div>
                                ))}
                            </div>
                        </section>
                    </div>
                </main>
            </div>
        </div>
    )
}
