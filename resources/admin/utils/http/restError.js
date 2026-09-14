// Rest.js rejects HTTP failures as { data, status_code } and network failures as { status: 0 }.
export function restStatus(error) {
    const status = Number(error?.status_code ?? error?.status);
    return Number.isFinite(status) ? status : 0;
}

export function restErrorMessage(error, fallback = '') {
    const direct = error?.message || error?.data?.message || error?.errors?.message;
    if (typeof direct === 'string' && direct.trim()) return direct;

    const errors = error?.data?.errors || error?.errors;
    if (errors && typeof errors === 'object') {
        for (const key of Object.keys(errors)) {
            const first = Array.isArray(errors[key]) ? errors[key][0] : (typeof errors[key] === 'object' && errors[key] ? Object.values(errors[key])[0] : errors[key]);
            if (typeof first === 'string' && first.trim()) return first;
        }
    }

    return fallback;
}
