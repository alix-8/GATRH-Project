export default function WalkthroughSpotlight({ bounds }) {
    if (
        !bounds ||
        bounds.bottom <= bounds.top ||
        bounds.right <= bounds.left ||
        (bounds.top === 0 && bounds.bottom === 0 && bounds.left === 0 && bounds.right === 0)
    ) {
        return null
    }

    const spotlightHeight = bounds.bottom - bounds.top
    const shadedAreas = [
        { top: 0, left: 0, right: 0, height: bounds.top },
        { top: bounds.bottom, left: 0, right: 0, bottom: 0 },
        { top: bounds.top, left: 0, width: bounds.left, height: spotlightHeight },
        { top: bounds.top, left: bounds.right, right: 0, height: spotlightHeight },
    ]

    return shadedAreas.map((style, index) => (
        <div
            key={index}
            className="import-walkthrough-shade"
            aria-hidden="true"
            style={style}
        />
    ))
}
