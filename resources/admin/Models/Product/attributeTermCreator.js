/**
 * Creates attribute terms for a group, in batches the API will accept.
 *
 * AttrTermRequest::rules() rejects a request carrying more than
 * TERM_CREATE_BATCH_SIZE terms ("Cannot create more than 10 terms at once."),
 * so a merchant who typed an eleventh option value had the whole save refused —
 * and, because the cap message arrives in the 422 errors bag rather than in
 * `data.message`, the advanced-variation editor showed only a generic "Failed to
 * create option values" and never said why.
 *
 * Batching is sequential on purpose: term slugs are auto-suffixed against
 * already-persisted rows, so concurrent requests into the same group could
 * collide.
 *
 * Partial success is reported rather than thrown away. When a later batch fails,
 * the terms earlier batches created already exist server-side; the caller needs
 * them so its dedup (which reads the group's loaded terms) does not re-send the
 * same titles on a retry.
 */

/**
 * Maximum terms per request. Mirrors the cap in AttrTermRequest::rules().
 */
export const TERM_CREATE_BATCH_SIZE = 10;

/**
 * @param {Function} post Transport: (path, body) => Promise resolving the API response.
 * @param {number|string} groupId Attribute group the terms belong to.
 * @param {string[]} titles Term titles to create.
 * @returns {Promise<{created: Array, message: string, error: *}>} `error` is null
 *          on full success; `created` always holds the terms that were persisted.
 */
export const createTermsInBatches = async (post, groupId, titles) => {
    const created = [];
    let message = '';

    for (let offset = 0; offset < titles.length; offset += TERM_CREATE_BATCH_SIZE) {
        const batch = titles.slice(offset, offset + TERM_CREATE_BATCH_SIZE);

        try {
            const response = await post('options/attr/group/' + groupId + '/terms', {
                terms: batch.map(title => ({title})),
            });

            created.push(...(response?.data || []));
            message = response?.message || message;
        } catch (error) {
            return {created, message, error};
        }
    }

    return {created, message, error: null};
};
