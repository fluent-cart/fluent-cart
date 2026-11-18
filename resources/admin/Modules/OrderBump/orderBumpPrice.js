// The price is in cents and a fixed amount in currency units. The steps match the server (Helper::toCent),
// so a tie at half a cent rounds the same way, and the saving stays between zero and the price.
const toCent = value => Math.round(Math.round((Number(value) || 0) * 100) / 100 * 100);

export function applyDiscount(price, discount) {
    const cents = Number(price) || 0;
    const raw = discount?.discount_type === 'percentage'
        ? (cents * toCent(discount.discount_amount) / 100) / 100
        : toCent(discount?.discount_amount);
    const saving = Math.min(Math.max(Math.round(raw), 0), cents);

    return {saving, offer: cents - saving};
}

// An offer needs a discount above zero. Only a plain decimal counts: Number() would also take Infinity, 0x10 or 1e3.
export function hasDiscountAmount(discount) {
    const amount = String(discount?.discount_amount ?? '').trim();

    return /^(\d+\.?\d*|\.\d+)$/.test(amount) && Number.isFinite(Number(amount)) && Number(amount) > 0;
}
