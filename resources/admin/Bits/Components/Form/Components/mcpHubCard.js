// State rules of the "Connect with one URL" card, shared by McpHubConnect.vue and
// McpSettings.vue (fluent-toolkit docs/mcp-product-connect.md).

// none: no FluentHub. ready: the one URL works for this user. turn_on: the hub is off and
// this user may turn it on. not_ready: FluentHub is there, but the one URL is not usable.
export function hubCardMode(hub) {
    if (!hub || typeof hub !== 'object') {
        return 'none';
    }
    if (hub.ready && hub.user_can_connect) {
        return 'ready';
    }
    // Only the server being off is offered as a button. Sign-in switched off on its own was an admin's choice.
    if (hub.hub_reason === 'off' && hub.can_manage) {
        return 'turn_on';
    }
    return 'not_ready';
}

// The application-password steps fold behind a link while the card leads.
export function hubLeads(hub) {
    const mode = hubCardMode(hub);
    return mode === 'ready' || mode === 'turn_on';
}
