export function normalizeSelectValue(value) {
    if (value === null || value === undefined || value === '') {
        return ''
    }
    return String(value)
}
