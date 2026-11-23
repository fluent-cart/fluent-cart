// Block attributes come from saved post content: any text can sit there, so only a plain positive number may reach a REST path.
// Returns the digits as text: store ids are BIGINT, and a JS number would round past Number.MAX_SAFE_INTEGER.
// An invalid id returns '0', which never names a real product.
export default function safeProductId(value) {
    const text = String(value ?? '');

    return /^[1-9][0-9]*$/.test(text) ? text : '0';
}
