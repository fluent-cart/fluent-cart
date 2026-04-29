let counter = 0;

export function generateCid() {
    return '_cid_' + (++counter);
}
