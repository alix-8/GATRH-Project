export function handleModalFormEnter(event) {
    const target = event.target
    const isEditableField = target instanceof HTMLInputElement || target instanceof HTMLSelectElement
    const unsupportedInputTypes = ['button', 'checkbox', 'file', 'hidden', 'radio', 'reset', 'submit']

    if (
        event.key !== 'Enter'
        || !isEditableField
        || event.nativeEvent.isComposing
        || (target instanceof HTMLInputElement && unsupportedInputTypes.includes(target.type))
    ) return

    event.preventDefault()

    const form = event.currentTarget
    const fields = [...form.querySelectorAll('input:not([disabled]):not([type="hidden"]), select:not([disabled]), textarea:not([disabled])')]
        .filter(field => field instanceof HTMLElement && field.offsetParent !== null)
    const currentIndex = fields.indexOf(target)
    const nextField = fields[currentIndex + 1]

    if (nextField) {
        nextField.focus()
        return
    }

    if (form.checkValidity()) {
        const submitButton = form.querySelector('button[type="submit"]:not([disabled])')
        if (submitButton) form.requestSubmit(submitButton)
        return
    }

    form.reportValidity()
}

export function isBackdropClick(event) {
    return event.target === event.currentTarget
}
