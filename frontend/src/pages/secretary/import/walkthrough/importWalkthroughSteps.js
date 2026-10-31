export const importWalkthroughSteps = [
    {
        target: '[data-import-guide="week"]',
        title: 'Choose a reporting week',
        text: 'Select This Week or Last Week. Attendance will use the date ranges shown here.',
    },
    {
        target: '[data-import-guide="gathering"]',
        title: 'Select a gathering',
        text: 'Choose All Gathering for PM, WS and TG, or select one gathering. Keep this selection consistent with your text.',
    },
    {
        target: '[data-import-guide="prepare"]',
        title: 'Prepare your attendance',
        text: 'Generate a format, then choose Use Format or copy it to fill elsewhere. You can also paste prepared attendance directly.',
    },
    {
        target: '[data-import-guide="preview"]',
        title: 'Fill in details and preview',
        text: 'Enter each student’s day, time and venue, then click Parse and See Preview. Regular attendance days must match the gathering range; future dates are not allowed.',
    },
    {
        target: '[data-import-guide="review"]',
        title: 'Review the preview',
        text: 'Check names, times, venues and days. Edit the preview directly, or choose Back to Raw Text to edit the original text.',
    },
    {
        target: '[data-import-guide="save"]',
        title: 'Save your attendance draft',
        text: 'Click Process Attendance when ready. Resolve any errors or confirmations that appear. This saves a draft; it does not submit it to the Program Head.',
    },
    {
        target: '[data-import-guide="success"]',
        title: 'Review and submit separately',
        text: 'Your draft is saved. Open Go to Attendance to review it and submit it to the Program Head when ready.',
    },
]

export function importWalkthroughPhase({ modal, isPreviewing, hasText }) {
    if (modal === 'select-week') return 0
    if (modal === 'success') return 6
    if (modal === 'generate') return 2
    if (isPreviewing) return 4
    return hasText ? 3 : 1
}

export function importWalkthroughPaused({ modal, busy }) {
    return busy || ['error', 'confirm-blank', 'confirm-override'].includes(modal)
}

export function importWalkthroughPosition(rect, viewport) {
    const width = Math.min(330, viewport.width - 24)
    const height = Math.min(300, viewport.height * .45)
    const gap = 12
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
