import {defineComponent} from 'vue';

/**
 * Shared runtime parser for add-on supplied Vue component strings.
 *
 * An add-on (pro storage drivers, the pro saved-payment-methods section) ships
 * an UNCOMPILED `.vue` file and hands it to a host SPA as a plain string. The
 * host compiles it at runtime with its own Vue instance — which is why both
 * bundles alias `vue` to the esm-bundler build.
 *
 * This is a constrained runtime, NOT a true sandbox. Two layers:
 *   1. the script body is rejected outright if it so much as mentions a
 *      blocked global (`findBlockedGlobal`), and
 *   2. every blocked global is shadowed inside the factory, so even an
 *      obfuscated reference resolves to `undefined`.
 *
 * Anything a template legitimately needs (HTTP, notifications, Stripe) is
 * handed in explicitly through `services`, so the host app stays in control of
 * the capability instead of the template reaching for a global.
 *
 * Shared deliberately: a second copy of the blocklist is a second copy that
 * can drift, and the copy that drifts is the one that stops blocking.
 */

export const BLOCKED_SCRIPT_PATTERNS = [
    {pattern: /\balert\s*\(/, label: 'alert()'},
    {pattern: /\bconfirm\s*\(/, label: 'confirm()'},
    {pattern: /\bprompt\s*\(/, label: 'prompt()'},
    {pattern: /\bsetTimeout\s*\(/, label: 'setTimeout()'},
    {pattern: /\bsetInterval\s*\(/, label: 'setInterval()'},
    {pattern: /\brequestAnimationFrame\s*\(/, label: 'requestAnimationFrame()'},
    {pattern: /\bcancelAnimationFrame\s*\(/, label: 'cancelAnimationFrame()'},
    {pattern: /\bqueueMicrotask\s*\(/, label: 'queueMicrotask()'},
    {pattern: /\bfetch\s*\(/, label: 'fetch()'},
    {pattern: /\bXMLHttpRequest\b/, label: 'XMLHttpRequest'},
    {pattern: /\bWebSocket\b/, label: 'WebSocket'},
    {pattern: /\bWorker\b/, label: 'Worker'},
    {pattern: /\bSharedWorker\b/, label: 'SharedWorker'},
    {pattern: /\bEventSource\b/, label: 'EventSource'},
    {pattern: /\bMessageChannel\b/, label: 'MessageChannel'},
    {pattern: /\bBroadcastChannel\b/, label: 'BroadcastChannel'},
    {pattern: /\bwindow\b/, label: 'window'},
    {pattern: /\bdocument\b/, label: 'document'},
    {pattern: /\bnavigator\.(?:serviceWorker|sendBeacon|geolocation|mediaDevices|bluetooth|usb|serial|hid|credentials|locks|wakeLock)\b/, label: 'navigator privileged API'},
    {pattern: /\bglobalThis\b/, label: 'globalThis'},
    {pattern: /\bself\b/, label: 'self'},
    {pattern: /\bparent\b/, label: 'parent'},
    {pattern: /\btop\b/, label: 'top'},
    {pattern: /\bframes\b/, label: 'frames'},
    {pattern: /\blocalStorage\b/, label: 'localStorage'},
    {pattern: /\bsessionStorage\b/, label: 'sessionStorage'},
    {pattern: /\bindexedDB\b/, label: 'indexedDB'},
    {pattern: /\bpostMessage\s*\(/, label: 'postMessage()'},
    {pattern: /\bopen\s*\(/, label: 'open()'},
    {pattern: /\bFileReader\b/, label: 'FileReader'},
    {pattern: /\bBlob\b/, label: 'Blob'},
    {pattern: /\bURL\.createObjectURL\b/, label: 'URL.createObjectURL'},
    {pattern: /\bMutationObserver\b/, label: 'MutationObserver'},
    {pattern: /\bResizeObserver\b/, label: 'ResizeObserver'},
    {pattern: /\bIntersectionObserver\b/, label: 'IntersectionObserver'},
    {pattern: /\bhistory\.(?:pushState|replaceState|go|back|forward)\s*\(/, label: 'history navigation'},
    {pattern: /\blocation\.(?:assign|replace|reload)\s*\(/, label: 'location navigation'},
    {pattern: /\blocation\.href\s*=/, label: 'location.href assignment'},
    {pattern: /\bFunction\b/, label: 'Function'},
    {pattern: /\beval\s*\(/, label: 'eval()'},
    {pattern: /\bnew\s+Function\b/, label: 'new Function'},
    {pattern: /\brequire\s*\(/, label: 'require()'},
    {pattern: /\bimport\s*\(/, label: 'import()'},
    {pattern: /\bReflect\b/, label: 'Reflect'},
    {pattern: /\bProxy\b/, label: 'Proxy'},
    {pattern: /\bprocess\b/, label: 'process'},
    {pattern: /\bmodule\b/, label: 'module'},
    {pattern: /\bexports\b/, label: 'exports'},
    {pattern: /\b__proto__\b/, label: '__proto__'},
    {pattern: /\b__defineGetter__\b/, label: '__defineGetter__'},
    {pattern: /\b__defineSetter__\b/, label: '__defineSetter__'}
];

/**
 * Globals shadowed to `undefined` inside every parsed template. A host may
 * override any of these through `services` when it wants to hand over a safe
 * stand-in instead (see `safeNavigator` below).
 */
const SHADOWED_GLOBALS = [
    'alert', 'confirm', 'prompt',
    'setTimeout', 'setInterval', 'requestAnimationFrame', 'cancelAnimationFrame', 'queueMicrotask',
    'fetch', 'XMLHttpRequest', 'WebSocket', 'Worker', 'SharedWorker', 'EventSource',
    'MessageChannel', 'BroadcastChannel',
    'window', 'document', 'navigator', 'globalThis', 'self', 'parent', 'top', 'frames',
    'localStorage', 'sessionStorage', 'indexedDB',
    'postMessage', 'open', 'FileReader', 'Blob', 'URL',
    'MutationObserver', 'ResizeObserver', 'IntersectionObserver',
    'history', 'location',
    'Function', 'Reflect', 'Proxy', 'process', 'module', 'exports', 'require'
];

/**
 * The read-only slice of `navigator` a template may keep — clipboard and
 * locale, never the privileged device APIs.
 */
export function safeNavigator() {
    // `window?.` still throws on an UNDECLARED identifier, so guard with typeof.
    const nav = typeof window !== 'undefined' ? window?.navigator : null;

    return Object.freeze({
        clipboard: nav?.clipboard,
        userAgent: nav?.userAgent,
        language: nav?.language,
        languages: nav?.languages,
        onLine: nav?.onLine,
        platform: nav?.platform
    });
}

/**
 * @param {string} rawScript
 * @returns {string|null} the label of the first blocked global found, or null
 */
export function findBlockedGlobal(rawScript) {
    for (const rule of BLOCKED_SCRIPT_PATTERNS) {
        if (rule.pattern.test(rawScript)) {
            return rule.label;
        }
    }

    return null;
}

/**
 * Pull the OUTERMOST <template> out of the component string. Counts nesting so
 * an inner <template #reference> / <template v-if> does not terminate early.
 *
 * @returns {string|null}
 */
function extractTemplate(componentString) {
    const templateStartMatch = componentString.match(/<template(?:\s[^>]*)?>|<template>/i);

    if (!templateStartMatch) {
        return null;
    }

    const templateStartIndex = templateStartMatch.index + templateStartMatch[0].length;

    let templateDepth = 1;
    let currentIndex = templateStartIndex;
    let templateEndIndex = -1;

    while (currentIndex < componentString.length && templateDepth > 0) {
        const nextOpenTag = componentString.indexOf('<template', currentIndex);
        const nextCloseTag = componentString.indexOf('</template>', currentIndex);

        if (nextCloseTag === -1) {
            return null;
        }

        if (nextOpenTag !== -1 && nextOpenTag < nextCloseTag) {
            templateDepth++;
            currentIndex = nextOpenTag + 9; // length of '<template'
        } else {
            templateDepth--;
            if (templateDepth === 0) {
                templateEndIndex = nextCloseTag;
            }
            currentIndex = nextCloseTag + 11; // length of '</template>'
        }
    }

    if (templateEndIndex === -1) {
        return null;
    }

    return componentString.substring(templateStartIndex, templateEndIndex).trim();
}

/**
 * Compile an add-on supplied `.vue` string into a runtime component.
 *
 * Only `<template>` and `<script>` are read — a `<style>` block is IGNORED, so
 * the host app owns the CSS for every add-on section it renders.
 *
 * The script must be Options API (`export default { ... }`); `<script setup>`
 * cannot work here because there is no build step to compile it.
 *
 * @param {string} componentString the raw contents of a `.vue` file
 * @param {Object} services name -> value handed to the template as in-scope
 *                          variables (e.g. `{Vue, Rest, Notify, translate}`)
 * @returns {{component: (Object|null), error: string}}
 */
export function parseStringComponent(componentString, services = {}) {
    try {
        if (typeof componentString !== 'string' || !componentString) {
            return {component: null, error: 'Component source is not a string.'};
        }

        const template = extractTemplate(componentString);

        if (template === null) {
            return {component: null, error: 'No <template> block found.'};
        }

        const scriptMatch = componentString.match(/<script>([\s\S]*?)<\/script>/i);

        let componentOptions = {};

        if (scriptMatch) {
            const rawScript = scriptMatch[1].trim();

            if (!/export\s+default/.test(rawScript)) {
                return {component: null, error: 'The <script> block must "export default" a component.'};
            }

            const blocked = findBlockedGlobal(rawScript);

            if (blocked) {
                return {
                    component: null,
                    /* translators note: developer-facing, never shown verbatim to a customer */
                    error: 'The <script> block references a blocked global: ' + blocked
                };
            }

            // Strip "export default" so the body can be returned from the factory.
            const cleanScript = rawScript.replace(/export\s+default/, 'return');

            const injected = Object.assign({navigator: safeNavigator()}, services);

            const argNames = Object.keys(injected);
            const argValues = Object.values(injected);

            SHADOWED_GLOBALS.forEach((name) => {
                if (argNames.indexOf(name) === -1) {
                    argNames.push(name);
                    argValues.push(void 0);
                }
            });

            const factory = new Function(...argNames, `"use strict";\n${cleanScript}`);

            componentOptions = factory(...argValues) || {};
        }

        return {
            component: defineComponent({
                ...componentOptions,
                template
            }),
            error: ''
        };
    } catch (e) {
        return {component: null, error: e && e.message ? e.message : 'Could not compile the component.'};
    }
}
