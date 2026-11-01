import './DashboardSkeleton.css'

/**
 * Full-page animated loading skeleton for the dashboard.
 * Mirrors the sidebar + header + stat-cards + table/panel layout,
 * with a shimmering placeholder in place of real content.
 *
 * Usage:
 *   {loading ? <DashboardSkeleton /> : <Dashboard data={data} />}
 */
function DashboardSkeleton({ contentOnly = false }) {
    const Content = contentOnly ? 'div' : 'main'
    return (
        <div className={`skeleton-page${contentOnly ? ' skeleton-page--content' : ''}`} aria-busy="true" aria-label="Loading dashboard">
            {!contentOnly && <aside className="skeleton-sidebar">
                <div className="sk sk-avatar" />
                <div className="sk sk-line sk-w-70" />
                <div className="sk sk-line sk-w-50" />
                <div className="sk sk-line sk-w-60" />
                <div className="sk sk-line sk-w-40" />
                <div className="sk sk-line sk-w-55" />
                <div className="skeleton-sidebar-footer">
                    <div className="sk sk-line sk-w-40" />
                    <div className="sk sk-circle-sm" />
                </div>
            </aside>}

            <Content className="skeleton-main">
                {!contentOnly && <header className="skeleton-header">
                    <div className="sk sk-line sk-w-30 sk-tall" />
                    <div className="skeleton-header-right">
                        <div className="sk sk-line sk-w-20" />
                        <div className="sk sk-circle-sm" />
                    </div>
                </header>}

                <section className="skeleton-hero">
                    <div className="skeleton-hero-text">
                        <div className="sk sk-line sk-w-80" />
                        <div className="sk sk-line sk-w-60" />
                        <div className="skeleton-hero-actions">
                            <div className="sk sk-pill" />
                            <div className="sk sk-pill" />
                        </div>
                    </div>
                    <div className="sk sk-block" />
                </section>

                <section className="skeleton-panels">
                    <div className="skeleton-panel skeleton-panel-wide">
                        <div className="skeleton-panel-head">
                            <div className="sk sk-line sk-w-40" />
                            <div className="sk sk-pill-sm" />
                        </div>
                        <div className="sk sk-block sk-block-tall" />
                    </div>

                    <div className="skeleton-panel skeleton-panel-narrow">
                        <div className="skeleton-panel-head">
                            <div className="sk sk-line sk-w-50" />
                        </div>
                        <div className="skeleton-list-row">
                            <div className="sk sk-circle-sm" />
                            <div className="sk sk-line sk-w-60" />
                        </div>
                        <div className="skeleton-list-row">
                            <div className="sk sk-circle-sm" />
                            <div className="sk sk-line sk-w-70" />
                        </div>
                        <div className="skeleton-list-row">
                            <div className="sk sk-circle-sm" />
                            <div className="sk sk-line sk-w-45" />
                        </div>
                    </div>
                </section>

                <section className="skeleton-table-card">
                    <div className="skeleton-table-head">
                        <div className="sk sk-line sk-w-40" />
                        <div className="sk sk-line sk-w-20" />
                    </div>
                    {Array.from({ length: 4 }).map((_, i) => (
                        <div className="skeleton-table-row" key={i}>
                            <div className="sk sk-line sk-w-30" />
                            <div className="sk sk-line sk-w-20" />
                            <div className="sk sk-line sk-w-25" />
                            <div className="sk sk-pill-sm" />
                        </div>
                    ))}
                </section>
            </Content>
        </div>
    )
}

export default DashboardSkeleton
