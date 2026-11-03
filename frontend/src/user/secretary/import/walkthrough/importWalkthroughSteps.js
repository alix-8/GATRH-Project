export const importWalkthroughSteps = [
    {
        id: 'week',
        advance: 'next',
        target: '[data-import-guide="change-week"]',
        spotlight: '[data-import-guide="week-summary"]',
        anchorToTarget: true,
        placement: 'below-section',
        selectWeekModalTarget: '[data-import-guide="week"]',
        title: 'Validate your reporting week',
        text: 'Check that the selected week and the PM, WS and TG date ranges match the attendance you will record. Use Change week if needed, then click Next once the week is correct.',
    },
    {
        id: 'gathering',
        advance: 'next',
        target: '[data-import-guide="gathering"]',
        title: 'Select a gathering',
        text: 'Choose All Gathering for PM, WS and TG, or select one gathering. Keep this selection consistent with your text.',
    },
    {
        id: 'prepare',
        advance: 'when-text',
        target: '[data-import-guide="prepare"]',
        title: 'Prepare your attendance',
        text: 'Generate a format, then choose Use Format or copy it to fill elsewhere. You can also paste prepared attendance directly.',
    },
    {
        id: 'verify-gathering',
        advance: 'next',
        target: '[data-import-guide="format-gathering"]',
        title: 'Validate your gathering type',
        text: 'Validate that the gathering type matches your attendance text. If it does not, select the correct gathering type or edit your text.',
    },
    {
        id: 'copy-format',
        modalOnly: 'generate',
        advance: 'next',
        target: '[data-import-guide="copy"]',
        title: 'Copy the format',
        text: 'Click Copy to share the format through Messenger or your assigned platform. Click Next to see the other option.',
    },
    {
        id: 'format-actions',
        modalOnly: 'generate',
        target: '[data-import-guide="format"]',
        title: 'Close or use the format',
        text: 'Choose Cancel to close after copying, or Use Format to place the format directly in the attendance text area.',
    },
    {
        id: 'preview',
        target: '[data-import-guide="preview"]',
        spotlight: '[data-import-guide-area="attendance-text"]',
        title: 'Paste attendance and preview',
        text: 'Paste the filled in attendance, then click Parse and See Preview. Regular attendance days must match the gathering range; future dates are not allowed.',
    },
    {
        id: 'review',
        advance: 'next',
        target: '[data-import-guide="review"]',
        clearTarget: '[data-import-guide="preview"]',
        title: 'Review the preview',
        text: 'Check names, times, venues and days. Edit the preview directly, or choose Back to Raw Text to edit the original text.',
    },
    {
        id: 'save',
        target: '[data-import-guide="save"]',
        title: 'Save your attendance draft',
        text: 'Click Process Attendance when ready. Resolve any errors or confirmations that appear. This saves a draft; it does not submit it to the Program Head.',
    },
    {
        id: 'success',
        target: '[data-import-guide="success"]',
        title: 'Review and submit separately',
        text: 'Your draft is saved. Open Go to Attendance to review it and submit it to the Program Head when ready.',
    },
]

export function importWalkthroughStepIndex(id, steps = importWalkthroughSteps) {
    const index = steps.findIndex(step => step.id === id)
    if (index === -1) throw new Error(`Unknown import walkthrough step: ${id}`)
    return index
}

export function importWalkthroughPhase({ modal, isPreviewing, hasText }, steps = importWalkthroughSteps) {
    let id = hasText ? 'preview' : 'week'
    if (isPreviewing) id = 'review'
    if (modal === 'generate') id = 'verify-gathering'
    if (modal === 'success') id = 'success'
    if (modal === 'select-week') id = 'week'
    return importWalkthroughStepIndex(id, steps)
}

export function importWalkthroughCanAdvance(step, { modal, hasText }) {
    if (modal === 'select-week') return false
    if (modal === 'generate') {
        return step.advance === 'next' && (step.id === 'verify-gathering' || step.modalOnly === 'generate')
    }
    return step.advance === 'next' || (step.advance === 'when-text' && hasText)
}

export function importWalkthroughPaused({ modal, busy, screenWidth, isSmallScreen }) {
    if (isSmallScreen || (typeof screenWidth === 'number' && screenWidth <= 900)) return true
    return busy || ['error', 'confirm-blank', 'confirm-override'].includes(modal)
}

export function importWalkthroughPosition(rect, viewport, options = {}) {
    const width = Math.min(330, viewport.width - 24)
    const height = Math.min(300, viewport.height * .45)
    const gap = 12
    if (options.belowSection) {
        const minUsableHeight = Math.min(140, Math.max(80, viewport.height - gap * 2))
        let top = Math.max(gap, options.belowSection.bottom + gap)
        let maxHeight = Math.min(height, viewport.height - top - gap)

        // On short viewports or when the section sits low, check if placing above is better
        if (maxHeight < minUsableHeight && options.belowSection.top !== undefined) {
            const spaceAbove = options.belowSection.top - gap * 2
            if (spaceAbove >= minUsableHeight) {
                const aboveHeight = Math.min(height, spaceAbove)
                top = Math.max(gap, options.belowSection.top - gap - aboveHeight)
                maxHeight = aboveHeight
            }
        }

        // Clamp top and maxHeight so the card stays within the viewport and avoids being cut off
        if (top + minUsableHeight > viewport.height - gap) {
            top = Math.max(gap, viewport.height - minUsableHeight - gap)
            maxHeight = Math.max(0, viewport.height - top - gap)
        } else {
            maxHeight = Math.max(0, maxHeight)
        }

        return {
            width,
            left: Math.max(gap, Math.min(viewport.width - width - gap, rect.right - width)),
            top,
            maxHeight,
        }
    }
    let left = rect.left
    let top = rect.bottom + gap
    if (viewport.width - rect.right >= width + gap * 2) {
        left = rect.right + gap
        top = rect.top
    } else if (rect.left >= width + gap * 2) {
        left = rect.left - width - gap
        top = rect.top
    } else if (viewport.height - rect.bottom < height + gap * 2) {
        top = rect.top - height - gap
    }
    return {
        width,
        left: Math.max(gap, Math.min(viewport.width - width - gap, left)),
        top: Math.max(gap, Math.min(viewport.height - height - gap, top)),
    }
}
