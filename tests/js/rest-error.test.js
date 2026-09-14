import {describe, it, expect} from 'vitest';
import {restStatus, restErrorMessage} from '@/utils/http/restError';

describe('restStatus', () => {
    it('reads the status_code Rest.js attaches to HTTP failures', () => {
        expect(restStatus({data: {message: 'Media not found'}, status_code: 404})).toBe(404);
        expect(restStatus({data: {}, status_code: 403})).toBe(403);
    });

    it('falls back to status for network failures and to 0 for junk', () => {
        expect(restStatus({status: 0, statusText: ''})).toBe(0);
        expect(restStatus(null)).toBe(0);
        expect(restStatus(new Error('boom'))).toBe(0);
    });
});

describe('restErrorMessage', () => {
    it('prefers the API message, then the first validation error, then the fallback', () => {
        expect(restErrorMessage({data: {message: 'Media not found'}, status_code: 404}, 'x')).toBe('Media not found');
        expect(restErrorMessage({message: 'Direct'}, 'x')).toBe('Direct');
        expect(restErrorMessage({data: {errors: {src: ['The src field is required.']}}, status_code: 422}, 'x')).toBe('The src field is required.');
        expect(restErrorMessage({data: {errors: {src: {required: 'Required'}}}, status_code: 422}, 'x')).toBe('Required');
        expect(restErrorMessage({data: {errors: {}}, status_code: 500}, 'Fallback')).toBe('Fallback');
        expect(restErrorMessage(undefined, 'Fallback')).toBe('Fallback');
    });
});
