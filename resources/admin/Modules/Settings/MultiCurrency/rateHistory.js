import {ref} from 'vue';

/**
 * Rate-history dialog state (GET settings/multi-currency/rates).
 *
 * @param {(code: string) => Promise<{rates: Array}>} fetchRates
 * @param {(errors: *) => void} onError
 */
export function useRateHistory(fetchRates, onError = () => {}) {
    const history = ref({currency: '', rates: [], loading: false, visible: false});
    // A slower answer for a currency opened earlier must not fill this dialog
    let latestRequest = 0;

    const showHistory = (code) => {
        const request = ++latestRequest;
        const isCurrent = () => request === latestRequest;
        history.value = {currency: code, rates: [], loading: true, visible: true};

        return fetchRates(code)
            .then(response => {
                if (isCurrent()) {
                    history.value.rates = response.rates;
                }
            })
            .catch(errors => {
                if (isCurrent()) {
                    onError(errors);
                }
            })
            .finally(() => {
                if (isCurrent()) {
                    history.value.loading = false;
                }
            });
    };

    return {history, showHistory};
}

/**
 * How a rate moved from the one before it, or null when there is nothing to
 * compare (the first rate, or an unusable value).
 *
 * @param {string|number} rate
 * @param {string|number|undefined} previous
 * @returns {{direction: 'up'|'down'|'same', percent: string}|null}
 */
export function rateChange(rate, previous) {
    const current = parseFloat(rate);
    const before = parseFloat(previous);
    if (!Number.isFinite(current) || !Number.isFinite(before) || before <= 0) {
        return null;
    }

    const change = ((current - before) / before) * 100;
    if (change === 0) {
        return {direction: 'same', percent: '0%'};
    }

    const sign = change > 0 ? '+' : '-';
    const size = Math.abs(change);

    return {
        direction: change > 0 ? 'up' : 'down',
        // A real move must not read as 0.00%
        percent: size < 0.005 ? `${sign}<0.01%` : `${sign}${size.toFixed(2)}%`
    };
}
