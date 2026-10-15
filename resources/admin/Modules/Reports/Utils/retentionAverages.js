// A month with no starting base has no defined rate; averaging its 0 would drag the period mean down.
export const averageRate = (items, rateKey, startKey) => {
    const qualifying = (items || []).filter((item) => parseFloat(item[startKey]) > 0);
    if (!qualifying.length) {
        return null;
    }

    const total = qualifying.reduce((sum, item) => sum + (parseFloat(item[rateKey]) || 0), 0);
    return total / qualifying.length;
};

export const formatAverage = (average) => (average === null ? '—' : `${average.toFixed(1)}%`);

export const LIFETIME_HORIZON_MONTHS = 120;

// 1 / churn explodes as retention nears 100%, so lifetimes beyond the horizon are reported as capped, not as a precise figure.
export const expectedLifetimeMonths = (avgRetentionDecimal) => {
    if (avgRetentionDecimal >= 1) {
        return {months: LIFETIME_HORIZON_MONTHS, capped: true};
    }

    const months = 1 / (1 - avgRetentionDecimal);
    if (months > LIFETIME_HORIZON_MONTHS) {
        return {months: LIFETIME_HORIZON_MONTHS, capped: true};
    }

    return {months, capped: false};
};
