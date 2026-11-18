import {afterEach, beforeEach, describe, expect, it, vi} from 'vitest';

let createPendingSave;

beforeEach(async () => {
    vi.useFakeTimers();
    globalThis.wp = {element: {useEffect: () => {}, useRef: () => ({}), useState: (value) => [value, () => {}]}};
    ({createPendingSave} = await import('@/BlockEditor/ProductSwatches/Components/SwatchCountSetting.jsx'));
});

afterEach(() => {
    vi.useRealTimers();
});

// The Show up to box saves a typed number once typing pauses. A value changed meanwhile (an undo)
// cancels the waiting save, or the old number would overwrite it.
describe('Show up to: the save waiting for typing to pause', () => {
    it('saves once, with the last number, after the pause', () => {
        const saved = [];
        const pending = createPendingSave(400);
        pending.schedule(() => saved.push(1));
        vi.advanceTimersByTime(200);
        pending.schedule(() => saved.push(10));
        vi.advanceTimersByTime(399);
        expect(saved).toEqual([]);
        vi.advanceTimersByTime(1);
        expect(saved).toEqual([10]);
    });

    it('never saves a number typed before the value changed elsewhere', () => {
        const saved = [];
        const pending = createPendingSave(400);
        pending.schedule(() => saved.push(7));
        // Undo restores another count 100ms later: the component cancels the waiting save.
        vi.advanceTimersByTime(100);
        pending.cancel();
        vi.advanceTimersByTime(1000);
        expect(saved).toEqual([]);
    });
});
