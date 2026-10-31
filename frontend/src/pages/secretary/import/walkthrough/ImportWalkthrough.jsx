import { useEffect, useId, useState } from 'react'
import { createPortal } from 'react-dom'
import {
    importWalkthroughPhase,
    importWalkthroughPaused,
    importWalkthroughSteps,
    importWalkthroughPosition,
} from './importWalkthroughSteps'
import WalkthroughSpotlight from './WalkthroughSpotlight'
import './ImportWalkthrough.css'

const storageKey = 'gatrh.import-walkthrough.v1'
const shouldShowGuide = () => {
    try {
        return !localStorage.getItem(storageKey)
    } catch {
        return true
    }
}

export default function ImportWalkthrough({ modal, isPreviewing, hasText, busy, gatheringType }) {
    const [active, setActive] = useState(shouldShowGuide)
    const [manual, setManual] = useState(null)
    const [anchor, setAnchor] = useState(null)
    const titleId = useId()
    const phase = importWalkthroughPhase({ modal, isPreviewing, hasText })
    const context = `${modal}|${isPreviewing}|${hasText}|${gatheringType}`
    const step = manual?.context === context ? manual.step : phase
    const visible = active && !importWalkthroughPaused({ modal, busy })
    const instruction = importWalkthroughSteps[step]

    const finish = () => {
        setActive(false)
        try {
            localStorage.setItem(storageKey, 'dismissed')
        } catch {
            // The guide still works when browser storage is unavailable.
        }
    }

    const start = () => {
        setManual(null)
        setActive(true)
    }

    const goToStep = nextStep => {
        setManual({ context, step: nextStep })
    }

    useEffect(() => {
        if (!active) return undefined
        const onGatheringChange = event => {
            if (event.target.matches('[data-import-guide="gathering"] select')) {
                setManual({
                    context: `${modal}|${isPreviewing}|${hasText}|${event.target.value}`,
                    step: hasText ? 3 : 2,
                })
            }
        }
        document.addEventListener('change', onGatheringChange)
        return () => document.removeEventListener('change', onGatheringChange)
    }, [active, modal, isPreviewing, hasText])

    useEffect(() => {
        if (!visible) return undefined
        const selector = modal === 'generate' ? '[data-import-guide="format"]' : instruction.target
        const target = document.querySelector(selector)
        if (!target) return undefined
        target.setAttribute('data-import-guide-active', '')
        target.scrollIntoView({ block: 'nearest', inline: 'nearest', behavior: 'instant' })
        let frame
        const measure = () => {
            cancelAnimationFrame(frame)
            frame = requestAnimationFrame(() => {
                const rect = target.getBoundingClientRect()
                const spotlight = {
                    top: Math.max(0, Math.min(window.innerHeight, rect.top - 6)),
                    bottom: Math.max(0, Math.min(window.innerHeight, rect.bottom + 6)),
                    left: Math.max(0, Math.min(window.innerWidth, rect.left - 6)),
                    right: Math.max(0, Math.min(window.innerWidth, rect.right + 6)),
                }
                const viewport = { width: window.innerWidth, height: window.innerHeight }
                setAnchor({
                    context,
                    step,
                    spotlight,
                    ...importWalkthroughPosition(rect, viewport),
                })
            })
        }
        measure()
        const observer = new ResizeObserver(measure)
        observer.observe(target)
        window.addEventListener('resize', measure)
        window.addEventListener('scroll', measure, true)
        return () => {
            cancelAnimationFrame(frame)
            observer.disconnect()
            target.removeAttribute('data-import-guide-active')
            window.removeEventListener('resize', measure)
            window.removeEventListener('scroll', measure, true)
        }
    }, [visible, step, context, instruction.target, modal])

    const showCard = visible && anchor?.context === context && anchor.step === step
    const isLastStep = step === importWalkthroughSteps.length - 1
    const canAdvanceManually = step === 1 || step === 4

    return (
        <>
            <div className="import-walkthrough-launcher">
                <button type="button" onClick={start}>Help / Start guide</button>
            </div>

            {showCard && createPortal(
                <>
                    <WalkthroughSpotlight bounds={anchor.spotlight} />
                    <section
                        className="import-walkthrough-card"
                        style={{ left: anchor.left, width: anchor.width, top: anchor.top }}
                        aria-labelledby={titleId}
                        aria-live="polite"
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
                </>,
                document.body,
            )}
        </>
    )
}
