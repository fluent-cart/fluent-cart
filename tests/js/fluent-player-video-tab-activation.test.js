import {beforeEach, describe, expect, it, vi} from 'vitest';
import {renderToString} from 'vue/server-renderer';
import {createSSRApp, h} from 'vue';

/**
 * The Video tab of the product gallery dialog when FluentPlayer is not active.
 *
 * Activating used to call window.location.reload() the moment the install
 * request resolved. The dialog is holding unsaved product edits at that point,
 * so the reload threw them away. The tab now stays put and asks the admin to
 * reload when their edits are safe.
 *
 * Rendered through vue/server-renderer: @vue/test-utils is not a dependency and
 * the vitest environment is `node`, so the SSR pass is the way an SFC renders
 * here (same approach as variation-selector-price-units.test.js). serverPrefetch
 * runs after setup() and before the render, which is where these tests drive the
 * component's own activation handler and let the mocked REST call settle — so
 * what is asserted is the markup the admin would actually see afterwards.
 */

const rest = vi.hoisted(() => ({
    post: vi.fn(),
    get: vi.fn(async () => ({})),
}));

const notify = vi.hoisted(() => ({
    success: vi.fn(),
    error: vi.fn(),
    info: vi.fn(),
}));

// ElMessageBox.confirm resolves when the admin confirms and rejects when they
// cancel, which is how the component tells the two apart.
const confirmBox = vi.hoisted(() => vi.fn(async () => 'confirm'));

vi.mock('element-plus', () => ({ElMessageBox: {confirm: (...args) => confirmBox(...args)}}));
vi.mock('@/utils/http/Rest', () => ({default: rest}));
vi.mock('@/utils/Notify', () => ({default: notify}));
vi.mock('@/utils/translator/Translator', () => ({default: (value) => value}));
vi.mock('@/Bits/Components/Icons/DynamicIcon.vue', () => ({default: {name: 'DynamicIcon', render: () => null}}));

const Tab = (await import('@/Modules/Products/parts/FluentPlayerVideoTab.vue')).default;

// el-button is registered globally by the admin app, so it resolves to nothing
// here and its subtree would render as a comment — the labels and data- hooks
// under test live inside it.
const ButtonStub = {
    name: 'ElButtonStub',
    inheritAttrs: false,
    setup(props, {attrs, slots}) {
        return () => h('button', attrs, slots.default ? slots.default() : []);
    },
};

const reload = vi.fn();

// Lets the pending Rest promise and the .then/.finally chained onto it settle
// before the component renders.
const settle = () => new Promise(resolve => setTimeout(resolve, 0));

/**
 * @param {Function|null} drive receives the component's own setup bindings.
 */
const renderTab = (config, drive = null) => {
    const app = createSSRApp({render: () => h(Tab, {config})});
    app.component('el-button', ButtonStub);
    if (drive) {
        app.mixin({
            async serverPrefetch() {
                const state = this.$ && this.$.setupState;
                if (state && 'installFluentPlayer' in state) await drive(state);
            },
        });
    }
    return renderToString(app);
};

const activate = async (state) => {
    state.installFluentPlayer();
    await settle();
};

const INACTIVE = {active: false, installed: false, canInstall: true};
const INSTALLED = {active: false, installed: true, canInstall: true};

const REQUIRED_NOTICE = 'FluentPlayer is required to add videos to this product.';
const READY_NOTICE = 'FluentPlayer is ready. Save any changes to this product, then reload the page to add videos.';
// SSR escapes the ampersand in the "Install & Activate FluentPlayer" label.
const INSTALL_LABEL = 'Install &amp; Activate FluentPlayer';

describe('FluentPlayer video tab — activation', () => {
    beforeEach(() => {
        rest.post.mockResolvedValue({message: 'FluentPlayer installed successfully.'});
        confirmBox.mockResolvedValue('confirm');
        vi.stubGlobal('window', {location: {reload}});
    });

    it('offers to install FluentPlayer when it is not on the site', async () => {
        const html = await renderTab(INACTIVE);

        expect(html).toContain(REQUIRED_NOTICE);
        expect(html).toContain(INSTALL_LABEL);
        expect(html).toContain('data-fct-fp-video-install');
        expect(html, 'nothing to reload before FluentPlayer has been activated').not.toContain('data-fct-fp-video-reload');
    });

    it('offers to activate FluentPlayer when it is installed but switched off', async () => {
        const html = await renderTab(INSTALLED);

        expect(html).toContain('Activate FluentPlayer');
        expect(html).not.toContain(INSTALL_LABEL);
    });

    it('asks the admin to reload once activation succeeded', async () => {
        const html = await renderTab(INSTALLED, activate);

        expect(rest.post).toHaveBeenCalledWith('integration/feed/install-plugin', {addon: 'fluent-player'});
        expect(html, 'the notice must say the tab is waiting on a reload, not that FluentPlayer is missing').toContain(READY_NOTICE);
        expect(html).not.toContain(REQUIRED_NOTICE);
        expect(html).toContain('Reload Page');
        expect(html).toContain('data-fct-fp-video-reload');
        expect(html, 'the activate button has done its job and must not invite a second install request').not.toContain('data-fct-fp-video-install');
    });

    it('does not reload the page by itself when activation succeeds', async () => {
        await renderTab(INSTALLED, activate);

        expect(
            reload,
            'the gallery dialog is holding unsaved product edits; reloading here discards them'
        ).not.toHaveBeenCalled();
    });

    it('reloads when the admin presses Reload Page and confirms', async () => {
        await renderTab(INSTALLED, async (state) => {
            await activate(state);
            await state.reloadPage();
        });

        expect(confirmBox).toHaveBeenCalledTimes(1);
        expect(reload).toHaveBeenCalledTimes(1);
    });

    it('warns that a reload discards unsaved product edits before taking it', async () => {
        await renderTab(INSTALLED, async (state) => {
            await activate(state);
            await state.reloadPage();
        });

        const [message, title] = confirmBox.mock.calls[0];
        expect(message, 'the admin must be told what a reload costs them').toContain('unsaved changes');
        expect(title).toBe('Reload this page?');
    });

    it('does not reload when the admin cancels the confirmation', async () => {
        confirmBox.mockRejectedValue('cancel');

        await renderTab(INSTALLED, async (state) => {
            await activate(state);
            await state.reloadPage();
        });

        expect(
            reload,
            'cancelling must leave the product editor exactly as it was'
        ).not.toHaveBeenCalled();
    });

    it('does not offer a button the admin lacks the WordPress capability to use', async () => {
        const html = await renderTab({active: false, installed: true, canInstall: false});

        expect(html).toContain(REQUIRED_NOTICE);
        expect(html).toContain('Ask an administrator to activate it for you.');
        expect(
            html,
            'integrations/manage does not imply activate_plugins; the request would come back 403'
        ).not.toContain('data-fct-fp-video-install');
    });

    it('tells a user who cannot install that an administrator must do it', async () => {
        const html = await renderTab({active: false, installed: false, canInstall: false});

        expect(html).toContain('Ask an administrator to install it for you.');
        expect(html).not.toContain(INSTALL_LABEL);
    });

    it('keeps the activate button when activation fails', async () => {
        rest.post.mockRejectedValue({response: {status: 500}});

        const html = await renderTab(INSTALLED, activate);

        expect(notify.error).toHaveBeenCalled();
        expect(html, 'a failed activation leaves nothing to reload into').toContain(REQUIRED_NOTICE);
        expect(html).toContain('data-fct-fp-video-install');
        expect(html).not.toContain('data-fct-fp-video-reload');
        expect(reload).not.toHaveBeenCalled();
    });
});
