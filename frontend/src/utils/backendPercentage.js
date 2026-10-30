export default function backendPercentage(value) {
    const percentage = Number(value)
    return Number.isFinite(percentage) ? Math.min(100, Math.max(0, percentage)) : 0
}
