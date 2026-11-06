import './DashboardSkeleton.css'

function Block({ className = '' }) {
    return <span className={`chancellor-skeleton-block ${className}`} />
}

function PanelHeading({ action = false }) {
    return <div className="chancellor-skeleton-panel-heading">
        <div>
            <Block className="chancellor-skeleton-panel-title" />
            <Block className="chancellor-skeleton-panel-subtitle" />
        </div>
        {action && <Block className="chancellor-skeleton-panel-action" />}
    </div>
}

export default function DashboardSkeleton() {
    return (
        <div className="chancellor-skeleton" role="status" aria-label="Loading chancellor dashboard" aria-busy="true">
            <div className="chancellor-skeleton-layout" aria-hidden="true">
                <aside className="chancellor-skeleton-sidebar">
                    <Block className="chancellor-skeleton-logo" />
                    <div className="chancellor-skeleton-nav-item">
                        <Block className="chancellor-skeleton-nav-icon" />
                        <Block className="chancellor-skeleton-nav-label" />
                    </div>
                    <div className="chancellor-skeleton-sidebar-footer">
                        <Block className="chancellor-skeleton-sign-out" />
                        <Block className="chancellor-skeleton-profile" />
                    </div>
                </aside>

                <main className="chancellor-skeleton-main">
                    <header className="chancellor-skeleton-header">
                        <div className="chancellor-skeleton-heading">
                            <Block className="chancellor-skeleton-title" />
                            <Block className="chancellor-skeleton-subtitle" />
                        </div>
                        <div className="chancellor-skeleton-date"><Block /><Block /></div>
                    </header>

                    <div className="chancellor-skeleton-content">
                        <section className="chancellor-skeleton-summary">
                            {[0, 1, 2, 3].map(index => <div className="chancellor-skeleton-summary-card" key={index}>
                                <Block className="chancellor-skeleton-summary-icon" />
                                <div className="chancellor-skeleton-summary-copy">
                                    <Block /><Block /><Block />
                                </div>
                            </div>)}
                        </section>

                        <div className="chancellor-skeleton-columns">
                            <div className="chancellor-skeleton-column">
                                <section className="chancellor-skeleton-panel chancellor-skeleton-overall">
                                    <PanelHeading />
                                    <div className="chancellor-skeleton-overall-body">
                                        <div><Block /><Block /></div>
                                        <Block className="chancellor-skeleton-overall-track" />
                                    </div>
                                </section>

                                <section className="chancellor-skeleton-panel chancellor-skeleton-attention">
                                    <PanelHeading action />
                                    <div className="chancellor-skeleton-month"><Block /><Block /><Block /></div>
                                    <div className="chancellor-skeleton-table">
                                        <div className="chancellor-skeleton-table-head">
                                            {[0, 1, 2, 3, 4, 5, 6].map(index => <Block key={index} />)}
                                        </div>
                                        {[0, 1, 2, 3, 4, 5].map(row => <div className="chancellor-skeleton-table-row" key={row}>
                                            {[0, 1, 2, 3, 4, 5, 6].map(column => <Block key={column} />)}
                                        </div>)}
                                    </div>
                                    <div className="chancellor-skeleton-table-footer"><Block /><Block /></div>
                                </section>
                            </div>

                            <div className="chancellor-skeleton-column">
                                <section className="chancellor-skeleton-panel chancellor-skeleton-weekly">
                                    <PanelHeading />
                                    <div className="chancellor-skeleton-chart">
                                        <div className="chancellor-skeleton-chart-bars">
                                            {[0, 1, 2, 3].map(index => <Block key={index} />)}
                                        </div>
                                        <div className="chancellor-skeleton-chart-legend">
                                            {[0, 1, 2, 3].map(index => <Block key={index} />)}
                                        </div>
                                    </div>
                                </section>

                                <section className="chancellor-skeleton-panel chancellor-skeleton-response">
                                    <PanelHeading />
                                    <div className="chancellor-skeleton-response-list">
                                        {[0, 1, 2, 3, 4, 5].map(index => <div className="chancellor-skeleton-response-row" key={index}>
                                            <div><Block /><Block /></div>
                                            <Block className="chancellor-skeleton-response-track" />
                                        </div>)}
                                    </div>
                                </section>
                            </div>
                        </div>
                    </div>
                </main>
            </div>
        </div>
    )
}
