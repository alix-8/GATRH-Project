import './DashboardSkeleton.css'

function Block({ className = '' }) {
    return <span className={`admin-skeleton-block ${className}`} />
}

function PanelHeading({ action = false }) {
    return (
        <div className="admin-skeleton-panel-heading">
            <div>
                <Block className="admin-skeleton-panel-title" />
                <Block className="admin-skeleton-panel-subtitle" />
            </div>
            {action && <Block className="admin-skeleton-panel-link" />}
        </div>
    )
}

export default function DashboardSkeleton() {
    return (
        <div className="admin-skeleton" role="status" aria-label="Loading admin dashboard" aria-busy="true">
            <div className="admin-skeleton-layout" aria-hidden="true">
                <aside className="admin-skeleton-sidebar">
                    <Block className="admin-skeleton-logo" />
                    <div className="admin-skeleton-nav">
                        {[0, 1, 2, 3, 4].map(index => (
                            <div className="admin-skeleton-nav-row" key={index}>
                                <Block className="admin-skeleton-nav-icon" />
                                <Block className="admin-skeleton-nav-label" />
                            </div>
                        ))}
                    </div>
                    <div className="admin-skeleton-sidebar-footer">
                        <Block className="admin-skeleton-logout" />
                        <Block className="admin-skeleton-settings" />
                    </div>
                </aside>

                <main className="admin-skeleton-main">
                    <header className="admin-skeleton-header">
                        <div className="admin-skeleton-heading">
                            <Block className="admin-skeleton-title" />
                            <Block className="admin-skeleton-subtitle" />
                        </div>
                        <div className="admin-skeleton-date"><Block /><Block /></div>
                    </header>

                    <div className="admin-skeleton-content">
                        <section className="admin-skeleton-summary">
                            {[0, 1, 2, 3].map(index => (
                                <div className="admin-skeleton-summary-card" key={index}>
                                    <Block className="admin-skeleton-summary-icon" />
                                    <div className="admin-skeleton-summary-copy">
                                        <Block /><Block /><Block />
                                    </div>
                                </div>
                            ))}
                        </section>

                        <div className="admin-skeleton-panels">
                            <section className="admin-skeleton-panel">
                                <PanelHeading />
                                <div className="admin-skeleton-accounts">
                                    {[0, 1, 2, 3].map(index => (
                                        <div className="admin-skeleton-account-row" key={index}>
                                            <Block className="admin-skeleton-account-icon" />
                                            <div className="admin-skeleton-account-copy"><Block /><Block /></div>
                                            <Block className="admin-skeleton-account-button" />
                                        </div>
                                    ))}
                                </div>
                            </section>

                            <section className="admin-skeleton-panel">
                                <PanelHeading />
                                <div className="admin-skeleton-actions">
                                    {[0, 1, 2, 3].map(index => (
                                        <div className="admin-skeleton-action" key={index}>
                                            <Block className="admin-skeleton-action-icon" />
                                            <Block className="admin-skeleton-action-title" />
                                            <Block className="admin-skeleton-action-detail" />
                                        </div>
                                    ))}
                                </div>
                            </section>
                        </div>

                        <section className="admin-skeleton-panel admin-skeleton-activity">
                            <PanelHeading action />
                            <div className="admin-skeleton-table">
                                <div className="admin-skeleton-table-row admin-skeleton-table-head">
                                    {[0, 1, 2, 3, 4].map(index => <Block key={index} />)}
                                </div>
                                {[0, 1, 2].map(row => (
                                    <div className="admin-skeleton-table-row" key={row}>
                                        {[0, 1, 2, 3, 4].map(column => <Block key={column} />)}
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
