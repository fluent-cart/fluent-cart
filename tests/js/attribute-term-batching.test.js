import {describe, expect, it} from 'vitest';
import {
    TERM_CREATE_BATCH_SIZE,
    createTermsInBatches,
} from '@/Models/Product/attributeTermCreator';

/**
 * AttrTermRequest::rules() refuses a request carrying more than ten terms
 * ("Cannot create more than 10 terms at once."). The advanced-variation editor
 * posted every typed option value in one request, so an eleventh value failed
 * the whole save — and, because the cap message arrives in the 422 errors bag
 * rather than in `data.message`, the merchant saw only "Failed to create option
 * values" and never learned about the cap.
 *
 * See dev-docs/framework-update-2.12.6-qa.md finding 6.
 */

const titles = (count) => Array.from({length: count}, (_, index) => 'Value ' + (index + 1));

const recordingPost = (responder) => {
    const calls = [];
    const post = async (path, body) => {
        calls.push({path, titles: body.terms.map(term => term.title)});
        return responder ? responder(calls.length, body) : {data: body.terms.map((term, index) => ({
            id: calls.length * 100 + index,
            title: term.title,
        }))};
    };
    return {calls, post};
};

describe('createTermsInBatches', () => {
    it('sends eleven values as two requests instead of one over-cap request', async () => {
        const {calls, post} = recordingPost();

        await createTermsInBatches(post, 7, titles(11));

        expect(calls).toHaveLength(2);
        expect(calls[0].titles).toHaveLength(TERM_CREATE_BATCH_SIZE);
        expect(calls[1].titles).toEqual(['Value 11']);
        calls.forEach(call => {
            expect(call.titles.length).toBeLessThanOrEqual(TERM_CREATE_BATCH_SIZE);
        });
    });

    it('sends exactly ten values as a single request', async () => {
        const {calls, post} = recordingPost();

        await createTermsInBatches(post, 7, titles(10));

        expect(calls).toHaveLength(1);
    });

    it('sends nothing when there are no new values', async () => {
        const {calls, post} = recordingPost();

        const result = await createTermsInBatches(post, 7, []);

        expect(calls).toHaveLength(0);
        expect(result.created).toEqual([]);
        expect(result.error).toBeNull();
    });

    it('returns every created term across batches, in order', async () => {
        const {post} = recordingPost();

        const {created, error} = await createTermsInBatches(post, 7, titles(25));

        expect(error).toBeNull();
        expect(created.map(term => term.title)).toEqual(titles(25));
    });

    it('posts to the group it was given', async () => {
        const {calls, post} = recordingPost();

        await createTermsInBatches(post, 42, titles(1));

        expect(calls[0].path).toBe('options/attr/group/42/terms');
    });

    it('reports the failure and keeps the terms earlier batches created', async () => {
        // Those rows exist server-side once their batch succeeds. Discarding them
        // would let a retry re-send the same titles, because the editor's dedup
        // reads the terms it has adopted.
        const rejection = {status_code: 422, data: {terms: {0: 'Cannot create more than 10 terms at once.'}}};
        const {calls, post} = recordingPost((callNumber, body) => {
            if (callNumber === 2) {
                return Promise.reject(rejection);
            }
            return {data: body.terms.map((term, index) => ({id: index, title: term.title}))};
        });

        const {created, error} = await createTermsInBatches(post, 7, titles(15));

        expect(error).toBe(rejection);
        expect(created).toHaveLength(TERM_CREATE_BATCH_SIZE);
        expect(calls).toHaveLength(2);
    });

    it('stops after a failed batch rather than sending the rest', async () => {
        const {calls, post} = recordingPost((callNumber) => {
            if (callNumber === 1) {
                return Promise.reject(new Error('network down'));
            }
            return {data: []};
        });

        await createTermsInBatches(post, 7, titles(30));

        expect(calls).toHaveLength(1);
    });

    it('carries the last success message back for the toast', async () => {
        const {post} = recordingPost((callNumber, body) => ({
            message: 'Batch ' + callNumber + ' created',
            data: body.terms.map((term, index) => ({id: index, title: term.title})),
        }));

        const {message} = await createTermsInBatches(post, 7, titles(11));

        expect(message).toBe('Batch 2 created');
    });
});
