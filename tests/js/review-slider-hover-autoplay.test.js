import {beforeEach, describe, expect, it, vi} from 'vitest';
import {wireHoverAutoplay} from '../../resources/public/single-product/review-slider/hoverAutoplay';

/**
 * The slider is rebuilt on every page change, sort and filter, and the
 * listeners go on the list element — which those rebuilds refill rather than
 * replace. Left alone that stacks a pair per rebuild, each holding a Swiper
 * destroyed pages ago, and nothing about the page looks wrong while it
 * happens: the slider still starts and stops on hover.
 */
describe('slider hover autoplay', () => {
    let target;
    let store;

    const sliderSpy = () => ({autoplay: {start: vi.fn(), stop: vi.fn()}});

    beforeEach(() => {
        target = new EventTarget();
        store = new WeakMap();
    });

    it('starts and stops autoplay as the pointer comes and goes', () => {
        const slider = sliderSpy();
        wireHoverAutoplay(target, slider, store);

        target.dispatchEvent(new Event('mouseenter'));
        expect(slider.autoplay.start).toHaveBeenCalledTimes(1);

        target.dispatchEvent(new Event('mouseleave'));
        expect(slider.autoplay.stop).toHaveBeenCalledTimes(1);
    });

    it('leaves only the newest slider listening after repeated rebuilds', () => {
        const sliders = [sliderSpy(), sliderSpy(), sliderSpy()];
        sliders.forEach((slider) => wireHoverAutoplay(target, slider, store));

        target.dispatchEvent(new Event('mouseenter'));

        expect(sliders[2].autoplay.start).toHaveBeenCalledTimes(1);
        expect(sliders[0].autoplay.start).not.toHaveBeenCalled();
        expect(sliders[1].autoplay.start).not.toHaveBeenCalled();
    });

    it('does not stack a second pair for one slider rewired twice', () => {
        const slider = sliderSpy();
        wireHoverAutoplay(target, slider, store);
        wireHoverAutoplay(target, slider, store);

        target.dispatchEvent(new Event('mouseenter'));

        expect(slider.autoplay.start).toHaveBeenCalledTimes(1);
    });

    /* A build that returns early still has to release the last one. */
    it('releases the listeners when given no slider', () => {
        const slider = sliderSpy();
        wireHoverAutoplay(target, slider, store);

        expect(wireHoverAutoplay(target, null, store)).toBeNull();

        target.dispatchEvent(new Event('mouseenter'));
        expect(slider.autoplay.start).not.toHaveBeenCalled();
    });

    it('ignores a slider with no autoplay, and releases anyway', () => {
        const slider = sliderSpy();
        wireHoverAutoplay(target, slider, store);

        expect(wireHoverAutoplay(target, {}, store)).toBeNull();

        target.dispatchEvent(new Event('mouseenter'));
        expect(slider.autoplay.start).not.toHaveBeenCalled();
    });

    it('keeps two lists on one page apart', () => {
        const other = new EventTarget();
        const first = sliderSpy();
        const second = sliderSpy();

        wireHoverAutoplay(target, first, store);
        wireHoverAutoplay(other, second, store);

        target.dispatchEvent(new Event('mouseenter'));

        expect(first.autoplay.start).toHaveBeenCalledTimes(1);
        expect(second.autoplay.start).not.toHaveBeenCalled();
    });
});
