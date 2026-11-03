export default function WalkthroughArrow({ target, headerBottom, viewport }) {
    if (
        !target ||
        (target.width === 0 && target.height === 0) ||
        (target.top === 0 && target.bottom === 0 && target.left === 0 && target.right === 0)
    ) {
        return null
    }

    const size = 28
    const gap = 12
    let left = target.left - size - gap
    let top = target.top + (target.height - size) / 2
    let rotation = 0

    if (left < gap) {
        left = target.right + gap
        rotation = 180
    }

    if (left + size > viewport.width - gap) {
        left = Math.max(gap, Math.min(viewport.width - size - gap, target.left + target.width / 2 - size / 2))
        top = target.top - size - gap
        rotation = 90

        if (top < headerBottom + gap) {
            top = target.bottom + gap
            rotation = -90
        }
    }

    if (
        target.bottom <= headerBottom ||
        target.top >= viewport.height ||
        top < headerBottom ||
        top + size > viewport.height
    ) {
        return null
    }

    return (
        <svg
            className="import-walkthrough-arrow"
            style={{ left, top, transform: `rotate(${rotation}deg)` }}
            width={size}
            height={size}
            viewBox="0 0 28 28"
            aria-hidden="true"
            focusable="false"
        >
            <g className="import-walkthrough-arrow-motion">
                <path d="M3 14h21M15 5l9 9-9 9" />
            </g>
        </svg>
    )
}
