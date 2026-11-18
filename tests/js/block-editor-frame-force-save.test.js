import {afterEach, describe, expect, it, vi} from 'vitest';

/**
 * Save Template asks the editor to save and waits for its answer. These tests run the component's real
 * forceAutosave() and handleEditorMessage() against an iframe that answers later, as a real one does.
 */

vi.hoisted(() => {
    globalThis.window = globalThis.window || {};
    globalThis.window.addEventListener = globalThis.window.addEventListener || (() => {});
    globalThis.window.removeEventListener = globalThis.window.removeEventListener || (() => {});
});

import BlockEditorFrame from '@/Bits/Components/BlockEditorFrame.vue';

const {methods} = BlockEditorFrame;
const ORIGIN = 'https://shop.test';

// `answer(frame, message)` is what the iframe does after it gets FORCE_SAVE.
const makeFrame = (answer) => {
    const frame = {
        pendingSaves: {},
        forceSaveId: 0,
        editorOrigin: ORIGIN,
        dataSent: true,
        editorContentReady: true,
        editorData: {content: 'old'},
        events: [],
        _timers: [],
        _setTimeout(fn, delay) {
            const id = setTimeout(fn, delay);
            this._timers.push(id);
            return id;
        },
        $emit(name, value) {
            this.events.push([name, value]);
        },
    };
    frame.forceAutosave = methods.forceAutosave.bind(frame);
    frame.handleEditorMessage = methods.handleEditorMessage.bind(frame);
    frame.editorFrame = {
        contentWindow: {
            postMessage: vi.fn((message, origin) => answer && answer(frame, message, origin)),
        },
    };

    return frame;
};

const reply = (frame, id, extra = {}, origin = ORIGIN) => {
    frame.handleEditorMessage({origin, data: {action: 'FORCE_SAVE_DONE', id, content: 'latest', saved: true, ...extra}});
};

describe('Block editor frame: forceAutosave', () => {
    afterEach(() => {
        vi.useRealTimers();
    });

    it('asks the editor to save with an id, and does not resolve until the editor answers', async () => {
        const frame = makeFrame((f, message) => setTimeout(() => reply(f, message.id), 30));
        let settled = false;

        const promise = frame.forceAutosave({requireAck: true}).then((result) => { settled = true; return result; });
        await new Promise(resolve => setTimeout(resolve, 5));

        expect(frame.editorFrame.contentWindow.postMessage).toHaveBeenCalledWith({action: 'FORCE_SAVE', id: 1}, ORIGIN);
        expect(settled).toBe(false);
        expect(await promise).toEqual({acknowledged: true, saved: true, content: 'latest'});
    });

    it('puts the latest content in the model before it resolves, so the caller never reads an older copy', async () => {
        const frame = makeFrame((f, message) => setTimeout(() => reply(f, message.id), 10));
        let seenWhenResolved = null;

        await frame.forceAutosave({requireAck: true}).then(() => { seenWhenResolved = frame.events.slice(); });

        expect(seenWhenResolved).toEqual([['update:modelValue', 'latest'], ['contentUpdated', 'latest']]);
        expect(frame.editorData.content).toBe('latest');
    });

    it('passes on an editor save that failed, with the latest content', async () => {
        const frame = makeFrame((f, message) => setTimeout(() => reply(f, message.id, {saved: false}), 10));

        expect(await frame.forceAutosave({requireAck: true})).toEqual({acknowledged: true, saved: false, content: 'latest'});
    });

    it('ignores an answer from another origin and waits for the real one', async () => {
        const frame = makeFrame((f, message) => {
            setTimeout(() => reply(f, message.id, {content: 'forged'}, 'https://evil.test'), 5);
            setTimeout(() => reply(f, message.id), 20);
        });

        expect(await frame.forceAutosave({requireAck: true})).toEqual({acknowledged: true, saved: true, content: 'latest'});
        expect(frame.events.some(([, value]) => value === 'forged')).toBe(false);
    });

    it('ignores an answer for a save it did not ask for', async () => {
        const frame = makeFrame((f, message) => {
            setTimeout(() => reply(f, message.id + 99, {content: 'another save'}), 5);
            setTimeout(() => reply(f, message.id), 20);
        });

        expect(await frame.forceAutosave({requireAck: true})).toEqual({acknowledged: true, saved: true, content: 'latest'});
        expect(frame.events.some(([, value]) => value === 'another save')).toBe(false);
    });

    it('gives each of two saves its own answer', async () => {
        const frame = makeFrame((f, message) => setTimeout(() => reply(f, message.id, {content: 'save ' + message.id}), 10));

        const [first, second] = await Promise.all([frame.forceAutosave({requireAck: true}), frame.forceAutosave({requireAck: true})]);

        expect(first.content).toBe('save 1');
        expect(second.content).toBe('save 2');
        expect(frame.pendingSaves).toEqual({});
    });

    it('resolves as not acknowledged when the editor never answers, so an older editor does not hang the page', async () => {
        vi.useFakeTimers();
        const frame = makeFrame();

        const promise = frame.forceAutosave({requireAck: true});
        await vi.advanceTimersByTimeAsync(5000);

        expect(await promise).toEqual({acknowledged: false, saved: false});
        expect(frame.pendingSaves).toEqual({});
    });

    it('resolves as not acknowledged when the editor frame is not there yet', async () => {
        const frame = makeFrame();
        frame.editorFrame = null;

        expect(await frame.forceAutosave({requireAck: true})).toEqual({acknowledged: false, saved: false});
    });

    it('keeps the answer after the timeout from doing anything', async () => {
        vi.useFakeTimers();
        const frame = makeFrame();

        const promise = frame.forceAutosave({requireAck: true});
        await vi.advanceTimersByTimeAsync(5000);
        await promise;
        reply(frame, 1);

        expect(frame.events).toEqual([]);
    });

    it('sends the save and resolves at once for a caller that does not need the answer, so an older editor never stalls it', async () => {
        const frame = makeFrame();

        expect(await frame.forceAutosave()).toEqual({acknowledged: false, saved: false});
        expect(frame.editorFrame.contentWindow.postMessage).toHaveBeenCalledWith({action: 'FORCE_SAVE'}, ORIGIN);
        expect(frame.pendingSaves).toEqual({});
    });

    it('settles a waiting save as not acknowledged when the editor is removed', async () => {
        const frame = makeFrame();
        frame.isFullscreen = false;
        frame.editorFrame = {contentWindow: {postMessage: vi.fn()}};
        frame.$refs = {iframeContainer: {removeChild: vi.fn()}};

        const promise = frame.forceAutosave({requireAck: true});
        BlockEditorFrame.beforeUnmount.call(frame);

        expect(await promise).toEqual({acknowledged: false, saved: false});
        expect(frame.pendingSaves).toEqual({});
    });
});
