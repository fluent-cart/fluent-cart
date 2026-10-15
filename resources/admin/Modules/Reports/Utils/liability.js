import CurrencyFormatter from "@/utils/support/CurrencyFormatter"

export function isLiability(value) {
    return Number(value) > 0
}

export function liabilityClass(value) {
    return isLiability(value) ? "text-red-500" : ""
}

export function maybeLiability(value, liability = null, currency = '') {
    const shouldShowAsLiability = liability !== null ? liability : isLiability(value)

    return shouldShowAsLiability
        ? `(${CurrencyFormatter.scaled(value, true, false, currency)})`
        : CurrencyFormatter.scaled(value, true, false, currency)
}
