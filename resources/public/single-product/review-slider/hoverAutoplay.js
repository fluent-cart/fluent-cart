/**
 * Start and stop autoplay while the pointer is over the slider.
 *
 * Its own module, and given its store rather than owning one, because the
 * thing that goes wrong here is invisible: the listeners sit on the list
 * element, which a page change refills rather than replaces, so a second
 * build adds a second pair rather than replacing the first. Nothing looks
 * wrong — the slider still starts and stops — while every page change leaves
 * another pair behind, each one holding a Swiper that was destroyed pages
 * ago. Written this way it can be asserted against a plain EventTarget.
 *
 * Call it on every build, with a slider when hover autoplay is wanted and
 * without one otherwise: either way it releases what the last build left.
 *
 * @param {EventTarget} target the element the pointer enters and leaves
 * @param {Object|null} slider the current Swiper, or null to only release
 * @param {WeakMap} store where each target's controller is kept between builds
 * @return {AbortController|null} the controller now holding the listeners
 */
export function wireHoverAutoplay(target, slider, store) {
    const previous = store.get(target);

    if (previous) {
        previous.abort();
        store.delete(target);
    }

    if (!slider || !slider.autoplay) {
        return null;
    }

    const controller = new AbortController();
    const options = {signal: controller.signal};

    target.addEventListener('mouseenter', () => slider.autoplay.start(), options);
    target.addEventListener('mouseleave', () => slider.autoplay.stop(), options);

    store.set(target, controller);

    return controller;
}

export default wireHoverAutoplay;
