import './PasswordRequirements.css'

const newPasswordRules = [
    { id: 'length', label: 'At least 8 characters', passes: password => password.length >= 8 },
    { id: 'mixed-case', label: 'At least one uppercase and lowercase letter', passes: password => /[A-Z]/.test(password) && /[a-z]/.test(password) },
    { id: 'number', label: 'At least one number', passes: password => /\d/.test(password) },
    { id: 'symbol', label: 'At least one special character', passes: password => /[^A-Za-z0-9]/.test(password) },
    { id: 'different', label: 'Different from current password', passes: (password, currentPassword) => Boolean(password) && password !== currentPassword },
]

function PasswordRequirements({ password, currentPassword, confirmation, visible }) {
    if (!visible) return null

    const rules = [
        ...newPasswordRules.map(rule => ({
            ...rule,
            passes: rule.passes(password, currentPassword),
        })),
        {
            id: 'confirmation',
            label: 'Passwords match',
            passes: Boolean(confirmation) && password === confirmation,
        },
    ]

    return <ul className="password-requirements" aria-label="Password requirements">
        {rules.map(rule => <li className={rule.passes ? 'is-met' : ''} key={rule.id}>
            <span aria-hidden="true" />
            {rule.label}
        </li>)}
    </ul>
}

export default PasswordRequirements
