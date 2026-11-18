import {describe, expect, it, vi} from 'vitest';
import {h, nextTick} from 'vue';

/**
 * The "Connect with one URL" card of the MCP drawer has four states, decided by what
 * FluentHub reports (fluent-toolkit docs/mcp-product-connect.md).
 */

vi.mock('@/utils/translator/Translator', () => ({default: (text) => text}));
vi.mock('@/utils/Clipboard', () => ({default: {copy: vi.fn()}}));
vi.mock('@/Bits/Components/Badge.vue', () => ({default: {render() { return h('span', {class: 'badge'}, this.$slots.default && this.$slots.default()); }}}));

import McpHubConnect from '@/Bits/Components/Form/Components/McpHubConnect.vue';
import {FakeNode, renderer} from './support/fake-dom-renderer.js';

const hubState = (overrides = {}) => ({
    ready: true,
    user_can_connect: true,
    can_manage: true,
    endpoint_url: 'https://store.test/wp-json/fluent-kit/mcp',
    hub_reason: '',
    sign_in_reason: '',
    included: true,
    manage_url: 'https://store.test/wp-admin/admin.php?page=fluent-hub',
    profile_url: 'https://store.test/wp-admin/profile.php',
    clients: [
        {key: 'claude-code', label: 'Claude Code', note: 'Run this in a terminal.', snippet: 'claude mcp add fluent-store-test https://store.test/wp-json/fluent-kit/mcp'},
    ],
    products: [
        {slug: 'fluent-cart', name: 'FluentCart', served: true, can_enable: false},
        {slug: 'fluent-crm', name: 'FluentCRM', served: false, can_enable: true},
    ],
    ...overrides,
});

const mount = async (hub, onEnable = () => {}) => {
    const root = new FakeNode('div');
    const app = renderer.createApp({render: () => h(McpHubConnect, {hub, onEnable})});
    app.component('fct-button', {render() { return h('button', {class: 'btn'}, this.$slots.default && this.$slots.default()); }});
    app.component('fct-input', {props: ['modelValue'], render() { return h('input', {class: 'input', value: this.modelValue}, this.$slots.append && this.$slots.append()); }});
    app.component('el-tabs', {render() { return h('div', {class: 'tabs'}, this.$slots.default && this.$slots.default()); }});
    app.component('el-tab-pane', {props: ['label'], render() { return h('span', {class: 'tab'}, this.label); }});
    app.config.warnHandler = () => {};
    app.mount(root);
    await nextTick();
    return root;
};

const byTestId = (root, id) => root.findAll(node => node.attrs && node.attrs['data-testid'] === id)[0];
const text = (node) => node.textContent.replace(/\s+/g, ' ').trim();
const buttonWith = (root, label) => root.findAll(node => node.tagName === 'button' && text(node) === label)[0];

describe('MCP hub connect card', () => {
    it('ready: shows the snippet FluentHub gave and the plugins on the URL', async () => {
        const root = await mount(hubState());

        expect(text(byTestId(root, 'mcp-hub-snippet'))).toBe('claude mcp add fluent-store-test https://store.test/wp-json/fluent-kit/mcp');
        expect(text(byTestId(root, 'mcp-hub-ready'))).toContain('Run this in a terminal.');
        expect(byTestId(root, 'mcp-hub-url').value).toBe('https://store.test/wp-json/fluent-kit/mcp');
        expect(byTestId(root, 'mcp-hub-manage').attrs.href).toBe('https://store.test/wp-admin/admin.php?page=fluent-hub');
        expect(text(byTestId(root, 'mcp-product-fluent-cart'))).toMatch(/^FluentCart\s*On$/);
        expect(text(byTestId(root, 'mcp-hub-products'))).toContain('Mcp_Hub_Turn_On_Plugin');
    });

    it('ready: a plugin that is off and can be turned on emits its slug', async () => {
        const enabled = [];
        const root = await mount(hubState(), slug => enabled.push(slug));

        byTestId(root, 'mcp-product-fluent-crm').findAll(node => node.tagName === 'button')[0].listeners.click();

        expect(enabled).toEqual(['fluent-crm']);
    });

    it('ready, not an admin: no plugin list, and the link goes to the user\'s own apps', async () => {
        const root = await mount(hubState({can_manage: false}));

        expect(byTestId(root, 'mcp-hub-products')).toBeUndefined();
        expect(byTestId(root, 'mcp-hub-manage').attrs.href).toBe('https://store.test/wp-admin/profile.php');
        expect(text(byTestId(root, 'mcp-hub-ready'))).toContain('Mcp_Hub_Intro_User');
    });

    it('hub off, admin: one button that turns the hub on', async () => {
        const enabled = [];
        const root = await mount(hubState({ready: false, hub_reason: 'off'}), slug => enabled.push(slug));

        buttonWith(byTestId(root, 'mcp-hub-turn-on'), 'Turn on FluentHub sign-in').listeners.click();

        expect(enabled).toEqual(['hub']);
        expect(byTestId(root, 'mcp-hub-snippet')).toBeUndefined();
    });

    it('hub off, not an admin: a sentence, no button', async () => {
        const root = await mount(hubState({ready: false, hub_reason: 'off', can_manage: false}));

        const notice = byTestId(root, 'mcp-hub-not-ready');
        expect(text(notice)).toContain('The FluentHub MCP server is off. A site administrator can turn it on.');
        expect(notice.findAll(node => node.tagName === 'button')).toHaveLength(0);
    });

    it('no access: the user is not sent to FluentHub, which has no setting for it', async () => {
        const root = await mount(hubState({ready: true, user_can_connect: false}));

        expect(byTestId(root, 'mcp-hub-not-ready').findAll(node => node.tagName === 'a')).toHaveLength(0);
    });

    it.each([
        [{ready: false, hub_reason: 'adapter_outdated'}, 'FluentHub needs a newer MCP adapter for it.'],
        [{ready: false, included: false}, 'FluentCart is switched off in the FluentHub MCP server.'],
        [{ready: false, sign_in_reason: 'plain_permalinks'}, 'Sign-in needs pretty permalinks. Change them in Settings, Permalinks.'],
        [{ready: false, sign_in_reason: 'app_passwords_off'}, 'Mcp_Hub_Reason_App_Passwords'],
        [{ready: false, sign_in_reason: 'off'}, 'Sign-in is switched off in FluentHub.'],
        [{ready: false}, 'It is not ready on this site yet. Reload this page, or check FluentHub.'],
        [{ready: true, user_can_connect: false}, "Your account has no access to this plugin's AI tools."],
    ])('not usable: says why (%j)', async (overrides, sentence) => {
        const root = await mount(hubState(overrides));

        expect(text(byTestId(root, 'mcp-hub-not-ready'))).toContain(sentence);
        expect(byTestId(root, 'mcp-hub-snippet')).toBeUndefined();
    });
});
