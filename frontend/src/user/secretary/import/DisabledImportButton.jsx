import './DisabledImportButton.css'

export default function DisabledImportButton({ disabledReason, hintClassName = '', disabled, children, ...props }) {
    const button = <button {...props} disabled={disabled}>{children}</button>
    if (!disabled || !disabledReason) return button

    return (
        <span className={`import-disabled-hint ${hintClassName}`} title={disabledReason}>
            {button}
        </span>
    )
}
