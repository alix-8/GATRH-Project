import { useEffect, useId, useState } from 'react'
import { createPortal } from 'react-dom'
import {
    importWalkthroughPhase,
    importWalkthroughPaused,
    importWalkthroughSteps,
    importWalkthroughPosition,
    importWalkthroughStepIndex,
    importWalkthroughCanAdvance,
} from './importWalkthroughSteps'
import WalkthroughSpotlight from './WalkthroughSpotlight'
import WalkthroughArrow from './WalkthroughArrow'
import './ImportWalkthrough.css'

const storageKey = 'gatrh.import-walkthrough.v1'
const shouldShowGuide = () => {
    try {
        return !localStorage.getItem(storageKey)
    } catch {
        return true
    }
}

const isElementVisible = el => {
    if (!el) return false
    if (typeof el.checkVisibility === 'function') {
        return el.checkVisibility({ checkOpacity: true, checkVisibilityCSS: true })
    }
    return Boolean(el.offsetWidth || el.offsetHeight || el.getClientRects().length)
}

export default function ImportWalkthrough({ modal, isPreviewing, hasText, busy, gatheringType }) {
    const [active, setActive] = useState(shouldShowGuide)
    const [manual, setManual] = useState(null)
    const [anchor, setAnchor] = useState(null)
    const [isSmallScreen, setIsSmallScreen] = useState(() => (typeof window !== 'undefined' ? window.innerWidth <= 900 : false))
    const [portalContainer, setPortalContainer] = useState(null)
    const titleId = useId()
    const phase = importWalkthroughPhase({ modal, isPreviewing, hasText })
    const context = `${modal}|${isPreviewing}|${hasText}|${gatheringType}`
    const step = manual?.context === context ? manual.step : phase
    const visible = active && !importWalkthroughPaused({
        modal,
        busy,
        isSmallScreen,
        screenWidth: typeof window !== 'undefined' ? window.innerWidth : undefined,
    })
    const configuredStep = importWalkthroughSteps[step]
    const instruction = {
        ...configuredStep,
        target: modal === 'select-week'
            ? configuredStep.selectWeekModalTarget || configuredStep.target
            : configuredStep.target,
    }

    const finish = () => {
        setActive(false)
        try {
            localStorage.setItem(storageKey, 'dismissed')
        } catch {
            // The guide still works when browser storage is unavailable.
        }
    }

    const start = () => {
        setManual(!modal && !isPreviewing
            ? { context, step: importWalkthroughStepIndex('week') }
            : null)
        setActive(true)
    }

    const goToStep = nextStep => {
        if (nextStep < 0 || nextStep >= importWalkthroughSteps.length) return
        setManual({ context, step: nextStep })
    }

    useEffect(() => {
        const updateScreenSize = () => {
            setIsSmallScreen(window.innerWidth <= 900)
        }
        window.addEventListener('resize', updateScreenSize)
        return () => window.removeEventListener('resize', updateScreenSize)
    }, [])

    useEffect(() => {
        setPortalContainer(document.getElementById('import-walkthrough-portal') || document.querySelector('.import'))
    }, [])

    useEffect(() => {
        if (!active) return undefined
        const onGatheringChange = event => {
            if (event.target.matches('[data-import-guide="gathering"] select')) {
                setManual({
                    context: `${modal}|${isPreviewing}|${hasText}|${event.target.value}`,
                    step: importWalkthroughStepIndex(hasText ? 'preview' : 'prepare'),
                })
            }
        }
        const onGuideAction = event => {
            if (event.target.closest('.import-week-option')) {
                setManual({
                    context: `null|${isPreviewing}|${hasText}|${gatheringType}`,
                    step: importWalkthroughStepIndex('gathering'),
                })
            }

            const formatAction = event.target.closest('[data-import-guide="format"] button')
            if (modal === 'generate' && formatAction && !formatAction.disabled) {
                const usesFormat = formatAction.classList.contains('import-primary-button')
                setManual({
                    context: `null|${isPreviewing}|${usesFormat || hasText}|${gatheringType}`,
                    step: importWalkthroughStepIndex('preview'),
                })
            }
        }
        document.addEventListener('change', onGatheringChange)
        document.addEventListener('click', onGuideAction, true)
        return () => {
            document.removeEventListener('change', onGatheringChange)
            document.removeEventListener('click', onGuideAction, true)
        }
    }, [active, modal, isPreviewing, hasText, gatheringType])

    useEffect(() => {
        if (!visible) return undefined
        const target = document.querySelector(instruction.target)
        if (!target || !isElementVisible(target)) return undefined

        // A step can keep its surrounding inputs clear while outlining one action.
        const stepSpotlight = instruction.spotlight
            ? document.querySelector(instruction.spotlight)
            : null
        const spotlightTarget = target.closest('.import-modal, .import-week-dialog') || stepSpotlight || target
        const clearTarget = instruction.clearTarget
            ? document.querySelector(instruction.clearTarget)
            : null
        clearTarget?.setAttribute('data-import-guide-clear', '')
        target.setAttribute('data-import-guide-active', '')
        const placeBelowSection = instruction.placement === 'below-section' && !modal
        const scrollTarget = placeBelowSection ? spotlightTarget : target
        const header = !modal ? document.querySelector('.import-header') : null
        header?.setAttribute('data-import-guide-header', '')
        const originalScrollMargin = scrollTarget.style.scrollMarginTop
        const updateScrollMargin = () => {
            if (header) {
                // Leave room for the sticky header when revealing a tour target.
                scrollTarget.style.scrollMarginTop = `${header.getBoundingClientRect().height + 12}px`
            }
        }
        updateScrollMargin()
        scrollTarget.scrollIntoView({
            block: placeBelowSection ? 'start' : 'nearest',
            inline: 'nearest',
            behavior: 'instant',
        })
        let frame
        const measure = () => {
            cancelAnimationFrame(frame)
            frame = requestAnimationFrame(() => {
                if (!isElementVisible(target) || !isElementVisible(spotlightTarget)) {
                    setAnchor(null)
                    return
                }
                updateScrollMargin()
                const rect = spotlightTarget.getBoundingClientRect()
                if (rect.width === 0 && rect.height === 0) {
                    setAnchor(null)
                    return
                }
                // Never let the clear spotlight window expose content behind the header.
                const headerBottom = header
                    ? Math.max(0, Math.min(window.innerHeight, header.getBoundingClientRect().bottom))
                    : 0
                const spotlightTop = Math.max(headerBottom, Math.min(window.innerHeight, rect.top - 6))
                const spotlight = {
                    top: spotlightTop,
                    bottom: Math.max(spotlightTop, Math.min(window.innerHeight, rect.bottom + 6)),
                    left: Math.max(0, Math.min(window.innerWidth, rect.left - 6)),
                    right: Math.max(0, Math.min(window.innerWidth, rect.right + 6)),
                }
                const viewport = { width: window.innerWidth, height: window.innerHeight }
                const anchorRect = instruction.anchorToTarget
                    ? target.getBoundingClientRect()
                    : rect
                setAnchor({
                    context,
                    step,
                    spotlight,
                    arrowTarget: target.getBoundingClientRect(),
                    headerBottom,
                    viewport,
                    ...importWalkthroughPosition(anchorRect, viewport, {
                        belowSection: placeBelowSection ? rect : null,
                    }),
                })
            })
        }
        measure()
        const observer = new ResizeObserver(measure)
        observer.observe(target)
        if (spotlightTarget !== target) {
            observer.observe(spotlightTarget)
        }
        const bannerCards = spotlightTarget.querySelector?.('.import-period-cards')
        if (bannerCards) {
            observer.observe(bannerCards)
        }
        if (header) observer.observe(header)
        window.addEventListener('resize', measure)
        window.addEventListener('scroll', measure, true)
        return () => {
            cancelAnimationFrame(frame)
            observer.disconnect()
            setAnchor(null)
            target.removeAttribute('data-import-guide-active')
            clearTarget?.removeAttribute('data-import-guide-clear')
            header?.removeAttribute('data-import-guide-header')
            scrollTarget.style.scrollMarginTop = originalScrollMargin
            window.removeEventListener('resize', measure)
            window.removeEventListener('scroll', measure, true)
        }
    }, [visible, step, context, instruction.target, instruction.spotlight, instruction.clearTarget, instruction.anchorToTarget, instruction.placement, modal])

    const showCard = visible && anchor?.context === context && anchor.step === step
    const isLastStep = step === importWalkthroughSteps.length - 1
    const canAdvanceManually = importWalkthroughCanAdvance(instruction, { modal, hasText })

    const targetPortal = portalContainer || (typeof document !== 'undefined' ? (document.getElementById('import-walkthrough-portal') || document.querySelector('.import')) : null)

    const overlay = showCard ? (
        <div className="import-walkthrough-overlay" aria-hidden={!visible}>
            <WalkthroughSpotlight bounds={anchor.spotlight} />
            <WalkthroughArrow target={anchor.arrowTarget} headerBottom={anchor.headerBottom} viewport={anchor.viewport} />
            <section
                className="import-walkthrough-card"
                style={{ left: anchor.left, width: anchor.width, top: anchor.top, maxHeight: anchor.maxHeight }}
                aria-labelledby={titleId}
                aria-live="polite"
                role="region"
            >
                <div className="import-walkthrough-meta">
                    <span>Import guide · {step + 1} of {importWalkthroughSteps.length}</span>
                    <button type="button" onClick={finish}>Skip guide</button>
                </div>

                <h3 id={titleId}>{instruction.title}</h3>
                <p>{instruction.text}</p>

                <div className="import-walkthrough-actions">
                    {step > phase && (
                        <button type="button" onClick={() => goToStep(step - 1)}>Back</button>
                    )}
                    {isLastStep && (
                        <button type="button" onClick={finish}>Finish guide</button>
                    )}
                    {!isLastStep && canAdvanceManually && (
                        <button type="button" onClick={() => goToStep(step + 1)}>Next</button>
                    )}
                    {!isLastStep && !canAdvanceManually && (
                        <span>Continue using the highlighted control.</span>
                    )}
                </div>
            </section>
        </div>
    ) : null

    return (
        <>
            <div className="import-walkthrough-launcher">
                <button type="button" onClick={start}>Help / Start guide</button>
            </div>

            {overlay && (targetPortal ? createPortal(overlay, targetPortal) : overlay)}
        </>
    )
}
