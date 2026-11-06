export function sanitizePersonName(value) {
    return value.replace(/[^\p{L}\s'’-]/gu, '')
}

export function sanitizeProgramName(value) {
    return value.replace(/[^\p{L}\s]/gu, '')
}

export function sanitizeProgramCode(value) {
    return value.replace(/[^A-Za-z0-9]/g, '').toUpperCase()
}
