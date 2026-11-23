import {describe, expect, it} from 'vitest';
import {hubCardMode, hubLeads} from '@/Bits/Components/Form/Components/mcpHubCard.js';

// The card's states and the fold of the application-password steps
// (fluent-toolkit docs/mcp-product-connect.md, "The four states of the card").
describe('MCP hub card state', () => {
    it.each([
        [null, 'none'],
        [{ready: true, user_can_connect: true}, 'ready'],
        [{ready: false, hub_reason: 'off', can_manage: true}, 'turn_on'],
        [{ready: false, hub_reason: 'off', can_manage: false}, 'not_ready'],
        [{ready: true, user_can_connect: false}, 'not_ready'],
        [{ready: false, sign_in_reason: 'off', can_manage: true}, 'not_ready'],
    ])('mode of %j is %s', (hub, mode) => {
        expect(hubCardMode(hub)).toBe(mode);
    });

    it('folds the application-password steps when the hub leads or offers to turn on', () => {
        expect(hubLeads({ready: true, user_can_connect: true})).toBe(true);
        expect(hubLeads({ready: false, hub_reason: 'off', can_manage: true})).toBe(true);
    });

    it('opens the application-password steps when the one URL cannot be used', () => {
        expect(hubLeads(null)).toBe(false);
        expect(hubLeads({ready: false, sign_in_reason: 'plain_permalinks', can_manage: true})).toBe(false);
        expect(hubLeads({ready: true, user_can_connect: false})).toBe(false);
    });
});
