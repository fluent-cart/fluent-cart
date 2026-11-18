import {afterEach, describe, expect, it, vi} from 'vitest';
import fs from 'node:fs';
import CheckoutHelper from '../../resources/public/checkout/CheckoutHelper.js';

const viewport = {width: 1440, height: 900};
const size = {width: 190, height: 120};

describe('line-item tooltip position', () => {
    it('opens below the hint, end-aligned like the in-flow tooltip', () => {
        const hintRect = {left: 1000, right: 1014, top: 300, bottom: 314};
        expect(CheckoutHelper.getTooltipPosition(hintRect, size, viewport)).toEqual({left: 824, top: 322});
    });

    it('start-aligns the unit-price rounding tooltip, and mirrors both in RTL', () => {
        const hintRect = {left: 1000, right: 1014, top: 300, bottom: 314};
        expect(CheckoutHelper.getTooltipPosition(hintRect, size, viewport, {alignToHintStart: true})).toEqual({left: 1000, top: 322});
        expect(CheckoutHelper.getTooltipPosition(hintRect, size, viewport, {isRtl: true})).toEqual({left: 1000, top: 322});
        expect(CheckoutHelper.getTooltipPosition(hintRect, size, viewport, {alignToHintStart: true, isRtl: true})).toEqual({left: 824, top: 322});
    });

    it('opens above the last item when there is no room below', () => {
        const hintRect = {left: 1000, right: 1014, top: 820, bottom: 834};
        expect(CheckoutHelper.getTooltipPosition(hintRect, size, viewport).top).toBe(820 - 8 - 120);
    });

    it('stays inside the window when it fits neither below nor above', () => {
        const hintRect = {left: 1000, right: 1014, top: 150, bottom: 164};
        const top = CheckoutHelper.getTooltipPosition(hintRect, {width: 190, height: 180}, {width: 800, height: 340}).top;
        expect(top).toBe(340 - 180 - 8);
    });

    it('stays inside the window on a phone', () => {
        const hintRect = {left: 20, right: 34, top: 300, bottom: 314};
        expect(CheckoutHelper.getTooltipPosition(hintRect, size, {width: 375, height: 700}).left).toBe(8);
        const hintNearWindowEnd = {left: 360, right: 374, top: 300, bottom: 314};
        expect(CheckoutHelper.getTooltipPosition(hintNearWindowEnd, size, {width: 375, height: 700}, {alignToHintStart: true}).left).toBe(375 - 190 - 8);
    });

    it('leaves the CSS tooltip alone where the Popover API is missing', () => {
        const fakeDocument = {addEventListener: vi.fn()};
        const fakeWindow = {HTMLElement: function () {}, addEventListener: vi.fn()};
        CheckoutHelper.initLineItemTooltips(fakeDocument, fakeWindow);
        expect(fakeDocument.addEventListener).not.toHaveBeenCalled();
        expect(fakeWindow.addEventListener).not.toHaveBeenCalled();
    });
});

function fakeLineItemList(scrollHeight, clientHeight, scrollbarWidth = 0, borderWidth = 0) {
    const attributes = {};
    const classNames = new Set();
    return {
        scrollHeight,
        clientHeight,
        clientWidth: 600,
        offsetWidth: 600 + scrollbarWidth + 2 * borderWidth,
        borderWidth,
        classList: {toggle: (name, enabled) => (enabled ? classNames.add(name) : classNames.delete(name)), contains: name => classNames.has(name)},
        scrollTop: 0,
        focus: vi.fn(),
        get tabIndex() { return 'tabindex' in attributes ? Number(attributes.tabindex) : -1; },
        set tabIndex(value) { attributes.tabindex = String(value); },
        hasAttribute: name => name in attributes,
        removeAttribute: name => { delete attributes[name]; }
    };
}

describe('line-item list focus', () => {
    afterEach(() => {
        vi.unstubAllGlobals();
    });

    const stubBorders = () => vi.stubGlobal('getComputedStyle', element => ({
        borderLeftWidth: element.borderWidth + 'px',
        borderRightWidth: element.borderWidth + 'px'
    }));

    it('is a Tab stop only while the list scrolls', () => {
        stubBorders();
        const long = fakeLineItemList(2000, 480);
        CheckoutHelper.syncLineItemsScrollState(long);
        expect(long.tabIndex).toBe(0);

        const short = fakeLineItemList(232, 232);
        short.tabIndex = 0;
        CheckoutHelper.syncLineItemsScrollState(short);
        expect(short.hasAttribute('tabindex')).toBe(false);
    });

    it('makes room for the scroll bar only where it floats over the prices', () => {
        stubBorders();
        const overlay = fakeLineItemList(2000, 480, 0);
        CheckoutHelper.syncLineItemsScrollState(overlay);
        expect(overlay.classList.contains('has-overlay-scrollbar')).toBe(true);

        const classic = fakeLineItemList(2000, 480, 8);
        CheckoutHelper.syncLineItemsScrollState(classic);
        expect(classic.classList.contains('has-overlay-scrollbar')).toBe(false);

        const short = fakeLineItemList(232, 232, 0);
        CheckoutHelper.syncLineItemsScrollState(short);
        expect(short.classList.contains('has-overlay-scrollbar')).toBe(false);

        // A theme border is not a scroll bar.
        const bordered = fakeLineItemList(2000, 480, 0, 1);
        CheckoutHelper.syncLineItemsScrollState(bordered);
        expect(bordered.classList.contains('has-overlay-scrollbar')).toBe(true);
    });
});

describe('summary fragment refresh', () => {
    afterEach(() => {
        vi.useRealTimers();
        vi.unstubAllGlobals();
    });

    it('keeps the list scrolled where it was and keeps its focus', () => {
        vi.useFakeTimers();
        const oldList = fakeLineItemList(2000, 480);
        oldList.scrollTop = 880;
        const newList = fakeLineItemList(2000, 480);
        let current = oldList;
        const wrapper = {
            querySelector: selector => (selector === '.fct_line_items' ? current : null),
            set innerHTML(html) { current = newList; }
        };

        vi.stubGlobal('document', {querySelector: () => wrapper, activeElement: oldList});
        const dispatchEvent = vi.fn();
        vi.stubGlobal('window', {dispatchEvent});
        vi.stubGlobal('getComputedStyle', () => ({borderLeftWidth: '0px', borderRightWidth: '0px'}));

        CheckoutHelper.handleFragments([{selector: '[data-fluent-cart-checkout-item-wrapper]', type: 'replace', content: '<div></div>'}]);

        expect(newList.scrollTop).toBe(880);
        expect(newList.tabIndex).toBe(0);
        expect(newList.focus).toHaveBeenCalledWith({preventScroll: true});

        // handleFragments announces the replaced selectors from a 10 ms timer.
        vi.advanceTimersByTime(10);
        expect(dispatchEvent.mock.calls.map(([event]) => event.type)).toContain('fluentCartFragmentsReplaced');
    });
});

// A tiny DOM: hint > button + tooltip, inside a line-item list. Enough for the tooltip wiring.
class FakeElement {
    constructor(classNames, parent = null) {
        this.classNames = new Set(classNames);
        this.parent = parent;
        this.attributes = {};
        this.style = {};
        this.state = {open: false, hover: false, focusWithin: false};
        this.rect = {left: 1000, right: 1014, top: 300, bottom: 314};
        this.offsetWidth = 190;
        this.offsetHeight = 120;
        this.isConnected = true;
        this.classList = {contains: className => this.classNames.has(className)};
    }
    closest(selector) {
        const [ancestorSelector, ownSelector] = selector.split(' ').length > 1 ? selector.split(' ') : [null, selector];
        for (let element = this; element; element = element.parent) {
            if (element.classNames.has(ownSelector.slice(1))) {
                if (!ancestorSelector) return element;
                for (let ancestorElement = element.parent; ancestorElement; ancestorElement = ancestorElement.parent) {
                    if (ancestorElement.classNames.has(ancestorSelector.slice(1))) return element;
                }
                return null;
            }
        }
        return null;
    }
    contains(otherElement) {
        for (let element = otherElement; element; element = element.parent) {
            if (element === this) return true;
        }
        return false;
    }
    matches(selector) {
        return {':popover-open': this.state.open, ':hover': this.state.hover, ':focus-within': this.state.focusWithin}[selector];
    }
    querySelector(selector) { return this.children?.find(child => child.classNames.has(selector.slice(1))) || null; }
    hasAttribute(name) { return name in this.attributes; }
    setAttribute(name, value) { this.attributes[name] = value; }
    getBoundingClientRect() { return this.rect; }
    showPopover() { this.state.open = true; }
    hidePopover() { this.state.open = false; }
}

function buildTooltipDom() {
    const listeners = {document: {}, window: {}};
    const listenOn = target => (type, listener) => { (listeners[target][type] ||= []).push(listener); };
    const lineItems = new FakeElement(['fct_line_items']);
    lineItems.rect = {left: 0, right: 1440, top: -100, bottom: 900};
    const makeHint = () => {
        const hint = new FakeElement(['fct_item_tax_hint'], lineItems);
        const button = new FakeElement(['fct_item_tax_hint_button'], hint);
        const tooltip = new FakeElement(['fct_item_tax_tooltip'], hint);
        hint.children = [button, tooltip];
        return {hint, button, tooltip};
    };
    const first = makeHint();
    const second = makeHint();
    const rowBody = new FakeElement(['fct_line_item_body'], lineItems);

    function HTMLElement() {}
    HTMLElement.prototype.showPopover = () => {};
    const fakeDocument = {
        addEventListener: listenOn('document'),
        documentElement: {clientWidth: 1440},
        activeElement: null,
        querySelectorAll: () => [first.tooltip, second.tooltip]
    };
    const fakeWindow = {
        HTMLElement, Element: FakeElement, innerHeight: 900,
        addEventListener: listenOn('window'),
        getComputedStyle: () => ({direction: 'ltr'}),
        setTimeout: (callback, delayMs) => setTimeout(callback, delayMs),
        clearTimeout: id => clearTimeout(id),
        requestAnimationFrame: callback => { callback(); return 1; }
    };
    CheckoutHelper.initLineItemTooltips(fakeDocument, fakeWindow);
    const dispatch = (target, type, event = {}) => (listeners[target][type] || []).forEach(listener => listener(event));
    return {...first, second, rowBody, fakeDocument, dispatch};
}

describe('line-item tooltip wiring', () => {
    afterEach(() => {
        vi.useRealTimers();
    });

    it('opens on hover in the top layer and closes after the pointer leaves', () => {
        vi.useFakeTimers();
        const {hint, button, tooltip, rowBody, dispatch} = buildTooltipDom();
        dispatch('document', 'pointerover', {target: button});
        expect(tooltip.state.open).toBe(true);
        expect(tooltip.attributes.popover).toBe('manual');
        expect(tooltip.style).toEqual({left: '824px', top: '322px'});

        dispatch('document', 'pointerout', {target: button, relatedTarget: rowBody, pointerType: 'mouse'});
        vi.advanceTimersByTime(300);
        expect(tooltip.state.open).toBe(false);
        expect(hint.state.open).toBe(false);
    });

    it('stays open after the mouse leaves while the button keeps keyboard focus', () => {
        vi.useFakeTimers();
        const {hint, button, tooltip, rowBody, dispatch} = buildTooltipDom();
        dispatch('document', 'focusin', {target: button});
        hint.state.focusWithin = true;
        dispatch('document', 'pointerover', {target: button});
        dispatch('document', 'pointerout', {target: button, relatedTarget: rowBody, pointerType: 'mouse'});
        vi.advanceTimersByTime(300);
        expect(tooltip.state.open).toBe(true);
    });

    it('stays open when a click inside the tooltip takes focus off the button', () => {
        const {button, tooltip, rowBody, dispatch} = buildTooltipDom();
        dispatch('document', 'focusin', {target: button});
        // The browser order: pointer onto the tooltip, then focus moves to the focusable list.
        dispatch('document', 'pointerover', {target: tooltip});
        dispatch('document', 'focusout', {target: button, relatedTarget: rowBody.parent});
        expect(tooltip.state.open).toBe(true);
    });

    it('closes on Escape', () => {
        const {button, tooltip, dispatch} = buildTooltipDom();
        dispatch('document', 'focusin', {target: button});
        dispatch('document', 'keydown', {key: 'Escape'});
        expect(tooltip.state.open).toBe(false);
    });

    it('follows the hint on resize instead of closing (mobile toolbars resize the window)', () => {
        const {hint, button, tooltip, dispatch} = buildTooltipDom();
        dispatch('document', 'pointerover', {target: button});
        hint.rect = {left: 1000, right: 1014, top: 200, bottom: 214};
        dispatch('window', 'resize');
        expect(tooltip.state.open).toBe(true);
        expect(tooltip.style.top).toBe('222px');
    });

    it('marks every line-item tooltip as a popover up front, so CSS hover never shows the clipped one', () => {
        const {tooltip, second} = buildTooltipDom();
        expect(tooltip.attributes.popover).toBe('manual');
        expect(second.tooltip.attributes.popover).toBe('manual');
    });

    it('does not open on a touch that may start a scroll; a tap opens it', () => {
        const {button, tooltip, dispatch} = buildTooltipDom();
        dispatch('document', 'pointerover', {target: button, pointerType: 'touch'});
        expect(tooltip.state.open).toBe(false);
        dispatch('document', 'click', {target: button});
        expect(tooltip.state.open).toBe(true);
    });

    it('brings back the focused hint\'s tooltip after a hovered one closes', () => {
        vi.useFakeTimers();
        const {button, tooltip, second, rowBody, fakeDocument, dispatch} = buildTooltipDom();
        dispatch('document', 'focusin', {target: button});
        fakeDocument.activeElement = button;
        dispatch('document', 'pointerover', {target: second.button});
        expect(second.tooltip.state.open).toBe(true);
        expect(tooltip.state.open).toBe(false);

        dispatch('document', 'pointerout', {target: second.button, relatedTarget: rowBody, pointerType: 'mouse'});
        vi.advanceTimersByTime(300);
        expect(second.tooltip.state.open).toBe(false);
        expect(tooltip.state.open).toBe(true);
    });

    it('closes once the page scrolls the hint out of the window', () => {
        const {hint, button, tooltip, dispatch} = buildTooltipDom();
        dispatch('document', 'focusin', {target: button});
        hint.rect = {left: 1000, right: 1014, top: -60, bottom: -46};
        dispatch('window', 'scroll');
        expect(tooltip.state.open).toBe(false);
    });

    it('closes once the list scrolls the hint out of view', () => {
        const {hint, button, tooltip, dispatch} = buildTooltipDom();
        dispatch('document', 'pointerover', {target: button});
        hint.rect = {left: 1000, right: 1014, top: 950, bottom: 964};
        dispatch('window', 'scroll');
        expect(tooltip.state.open).toBe(false);
    });
});

// The block that follows `opener` in the stylesheet, braces matched.
function cssBlock(css, opener) {
    const start = css.indexOf(opener);
    if (start === -1) {
        return null;
    }
    let depth = 0;
    for (let i = css.indexOf('{', start); i < css.length; i++) {
        depth += css[i] === '{' ? 1 : css[i] === '}' ? -1 : 0;
        if (depth === 0) {
            return css.slice(start, i + 1);
        }
    }
    return null;
}

describe('line-item list height cap', () => {
    const css = fs.readFileSync(new URL('../../resources/public/checkout/style/checkout.scss', import.meta.url), 'utf8');

    it('scrolls only where popovers can lift the item tooltips out of the list', () => {
        const baseRule = cssBlock(css, '\n.fct_line_items {');
        expect(baseRule).not.toBeNull();
        expect(baseRule).not.toMatch(/max-height|overflow-y/);

        const gated = cssBlock(css, '@supports selector(:popover-open)');
        expect(gated).not.toBeNull();
        expect(gated).toMatch(/\.fct_line_items\s*\{[^}]*max-height:\s*min\(480px, 60svh\)/);
        expect(gated).toMatch(/overflow-y:\s*auto/);
    });
});
