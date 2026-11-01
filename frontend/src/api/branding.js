import { API_BASE_URL } from './request'

let pendingBranding = null

/** Share overlapping public reads, without caching completed branding data. */
export function loadBranding() {
    if (!pendingBranding) {
        pendingBranding = fetch(`${API_BASE_URL}/branding`, { cache: 'no-store' })
            .then(response => {
                if (!response.ok) throw new Error('Unable to load system branding.')
                return response.json()
            })
            .finally(() => { pendingBranding = null })
    }
    return pendingBranding
}
