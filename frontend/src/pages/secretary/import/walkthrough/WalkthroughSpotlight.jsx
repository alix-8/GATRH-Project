export default function WalkthroughSpotlight({ bounds }) {
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
