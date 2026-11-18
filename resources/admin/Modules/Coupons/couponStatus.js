// The status the coupon form shows for a coupon's dates. A coupon that is turned off stays
// off. "inactive" is turned off too: the MCP manage-coupon tool writes it, and the form has
// no option for it, so it is shown and saved as "disabled".
export function couponStatusForDates(status, startDate, endDate, now = new Date()) {
    if (status === 'disabled' || status === 'inactive') {
        return 'disabled';
    }
    if (!startDate && !endDate) {
        return 'active';
    }
    if (startDate && now < startDate) {
        return 'scheduled';
    }
    if (endDate && now > endDate) {
        return 'expired';
    }
    return 'active';
}
