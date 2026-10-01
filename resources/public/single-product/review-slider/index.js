import {reviewTrans} from './reviewTrans';
import {wireHoverAutoplay} from './hoverAutoplay';

/**
 * The review list's slider view.
 *
 * Built in the browser rather than in PHP because the rows are replaced
 * wholesale on every sort, filter and page — server-rendered slider markup
 * would be thrown away on the first interaction. The list element itself
 * survives that (only its innerHTML is replaced), so its data-reviews-slider
 * is what says to rebuild and how many across.
 *
 * Everything the slider needs lives in this folder: Reviews.js hands it the
 * list element and keeps the instance it gets back, and knows nothing else
 * about Swiper.
 */

/**
 * The hover listeners currently live on each list, so the next build can take
 * them off again. Weak, so a list that leaves the page takes its entry with
 * it rather than pinning the element here forever.
 */
const hoverAutoplayControllers = new WeakMap();

/**
 * One of the slider's arrows.
 *
 * The chevron is the same path ReviewListRenderer::chevronSvg() draws for the
 * pager, so the two controls on one page are the same arrow — and an SVG
 * rather than a rotated border, which the theme's own button padding was
 * pulling out of shape.
 *
 * @param {'prev'|'next'} direction
 * @return {HTMLButtonElement}
 */
function navButton(direction) {
    const path = direction === 'prev' ? 'm15 18-6-6 6-6' : 'm9 18 6-6-6-6';
    const label = direction === 'prev'
        ? (reviewTrans().prev_page || 'Previous')
        : (reviewTrans().next_page || 'Next');

    const button = document.createElement('button');
    button.type = 'button';
    button.className = `fct-reviews-slider-nav fct-reviews-slider-${direction}`;
    button.setAttribute('aria-label', label);
    button.innerHTML = '<svg class="fct-nav-arrow-svg" width="1em" height="1em" viewBox="0 0 24 24"'
        + ' fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"'
        + ' stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="' + path + '"/></svg>';

    return button;
}

/**
 * The width at which each column becomes readable, in pixels.
 *
 * Matches the grid's own minimum track width, so the two views collapse at
 * the same places and a list that shows three in grid shows three in slider.
 */
const COLUMN_MIN_WIDTH = 260;
const COLUMN_GAP = 12;

/**
 * The same, for a card that carries a photograph and nothing else.
 *
 * 260px is the width a *review* needs — a name, stars and a sentence. A photo
 * strip has none of that: its card is the picture, and a picture is legible at
 * thumbnail size. Held to the review minimum, a strip asking for six never got
 * past two on any real product page, because six columns of 260 wants a 1620px
 * container and a review section is rarely half that. The strip came out as two
 * enormous photographs, which is the one thing a strip is not.
 */
const PHOTO_COLUMN_MIN_WIDTH = 120;

/**
 * Which minimum a list is held to, decided by what its cards actually carry.
 *
 * Read from the card class the photo-only presets set rather than from the
 * preset name, which nothing reads at render time.
 */
function columnMinWidth(listContainer) {
    return listContainer && listContainer.querySelector('.fct-review-photo-only')
        ? PHOTO_COLUMN_MIN_WIDTH
        : COLUMN_MIN_WIDTH;
}

/**
 * Swiper breakpoints for a given column count.
 *
 * One column until there is room for two, and so on up to what was asked
 * for. Keyed by container width, so the entry for the full count is the
 * width at which every column clears the readable minimum.
 *
 * @param {number} columns the column count the list asked for
 * @param {number} minWidth the width one column needs to be worth showing
 * @return {Object} breakpoints keyed by container width
 */
function columnBreakpoints(columns, minWidth = COLUMN_MIN_WIDTH) {
    const breakpoints = {};

    for (let count = 2; count <= columns; count++) {
        breakpoints[minWidth * count + COLUMN_GAP * (count - 1)] = {slidesPerView: count};
    }

    return breakpoints;
}

/**
 * Swiper's pagination options for one of the four types.
 *
 * Segmented is not a Swiper type. It is the 'custom' one with a renderer, the
 * same shape the product and media carousels use: a bar per page, the ones
 * behind the reader marked completed, and a counter after them.
 *
 * @param {HTMLElement} el the element the dots are drawn into
 * @param {string} type bullets | fraction | progressbar | segmented
 * @return {Object} Swiper's pagination options
 */
function paginationConfig(el, type) {
    if (type !== 'segmented') {
        return {el, clickable: true, type};
    }

    return {
        el,
        // Swiper's `clickable` wires its own bullets, and these are not its
        // bullets — they come out of renderCustom, so nothing would listen to
        // them. They are real buttons instead, wired below, which is also what
        // gets them a keyboard and a name.
        type: 'custom',
        renderCustom(swiper, current, total) {
            let html = '';

            for (let page = 1; page <= total; page++) {
                let className = 'swiper-pagination-segment';

                if (page < current) {
                    className += ' is-completed';
                } else if (page === current) {
                    className += ' is-active';
                }

                html += `<button type="button" class="${className}"`
                    + ` data-segment-page="${page}"`
                    + ` aria-label="${segmentLabel(page, total)}"`
                    + (page === current ? ' aria-current="true"' : '')
                    + '></button>';
            }

            return html + `<span class="segment-counter">${current} / ${total}</span>`;
        },
    };
}

/**
 * The name a segment answers to, for a reader who cannot see the bars.
 */
function segmentLabel(page, total) {
    const trans = reviewTrans();
    const pattern = trans.slider_page_of || 'Page %1$s of %2$s';

    return pattern.replace('%1$s', page).replace('%2$s', total);
}

/**
 * Make the segments move the slider.
 *
 * Delegated to the pagination element, because Swiper replaces its children
 * on every change: a listener bound to a segment would go with the segment it
 * was bound to, one slide later.
 *
 * @param {HTMLElement} el the pagination element
 * @param {Object} slider the Swiper instance
 */
function wireSegments(el, slider) {
    el.addEventListener('click', (event) => {
        const segment = event.target.closest('[data-segment-page]');

        if (!segment) {
            return;
        }

        const page = parseInt(segment.getAttribute('data-segment-page'), 10);

        if (!page) {
            return;
        }

        // Pagination counts snap points, while slideTo() takes a slide index.
        // With two or more cards visible those values differ, so resolve the
        // requested snap point back to the first slide in that page.
        const snap = slider.snapGrid?.[page - 1];
        const snapIndex = snap === undefined ? -1 : slider.slidesGrid?.indexOf(snap);
        const index = snapIndex >= 0 ? snapIndex : page - 1;

        if (typeof slider.slideToLoop === 'function' && slider.params.loop) {
            slider.slideToLoop(index);
            return;
        }

        slider.slideTo(index);
    });
}

/**
 * Drop the dots a previous build left after the slider.
 *
 * @param {HTMLElement} listContainer the [data-reviews-list] element
 */
function removeSliderPagination(listContainer) {
    const existing = listContainer.nextElementSibling;

    if (existing && existing.classList.contains('fct-reviews-slider-pagination-wrap')) {
        existing.remove();
    }
}

/**
 * Put the arrows where the setting asks for.
 *
 * All three live in a frame around the track rather than inside it: Swiper
 * gives the track overflow:hidden, so an arrow within it can only ever sit
 * fully over a card. The frame also reserves the room they need, so an arrow
 * never hangs off the page on a narrow screen.
 *
 * @param {HTMLElement} listContainer the [data-reviews-list] element
 * @param {HTMLElement} prev
 * @param {HTMLElement} next
 * @param {string} position overlap | outside | bottom
 * @param {string} size sm | md | lg
 */
function placeArrows(listContainer, prev, next, position, size) {
    const frame = sliderFrame(listContainer);
    frame.className = 'fct-reviews-slider-frame is-arrows-' + position + ' fct-arrows-' + size;

    if (position === 'bottom') {
        const row = document.createElement('div');
        row.className = 'fct-reviews-slider-nav-row';
        row.appendChild(prev);
        row.appendChild(next);
        frame.appendChild(row);
        return;
    }

    frame.appendChild(prev);
    frame.appendChild(next);
}

/**
 * Take the track back out of its frame, and the frame out of the document.
 *
 * @param {HTMLElement} listContainer the [data-reviews-list] element
 */
function unwrapSliderFrame(listContainer) {
    const parent = listContainer.parentElement;

    if (!parent || !parent.classList.contains('fct-reviews-slider-frame')) {
        return;
    }

    parent.replaceWith(listContainer);
}

/**
 * The element wrapping the track, reused across rebuilds so a page change
 * does not nest one frame inside another.
 */
function sliderFrame(listContainer) {
    const parent = listContainer.parentElement;

    if (parent && parent.classList.contains('fct-reviews-slider-frame')) {
        [...parent.querySelectorAll('.fct-reviews-slider-nav, .fct-reviews-slider-nav-row')].forEach(el => el.remove());
        return parent;
    }

    const frame = document.createElement('div');
    listContainer.replaceWith(frame);
    frame.appendChild(listContainer);

    return frame;
}

/**
 * Turn the rows into a Swiper, when the block asked for the slider view.
 *
 * @param {HTMLElement} listContainer the [data-reviews-list] element
 * @param {Object|null} previous the instance the last build returned, if any
 * @return {Object|null} the new instance, or null when there is no slider
 */
export function buildReviewSlider(listContainer, previous = null) {
    const slidesPerView = parseInt(listContainer.getAttribute('data-reviews-slider'), 10);

    // Before any of the guards below can return: whatever this list was
    // listening for on the last build stops now, whether or not there is a
    // build to replace it.
    wireHoverAutoplay(listContainer, null, hoverAutoplayControllers);

    // The dots live outside the slider, so the innerHTML reset further down
    // does not clear them the way it clears the arrows. Dropped here, beside
    // the hover teardown, so a list that stops being a slider at all does not
    // keep a row of dots under it.
    removeSliderPagination(listContainer);

    // Swiper's <script> is printed after the reviews module's, but that one is
    // a module and therefore deferred, so the classic bundle has already
    // executed by the time any of this runs. Still guarded: a slider that
    // cannot build leaves the rows as a plain readable stack.
    if (!slidesPerView || typeof window.Swiper !== 'function') {
        // And without its frame. A list that is no longer a slider, or one
        // whose slider cannot build, would otherwise keep the wrapper from
        // the last build — and in the beside placement that wrapper's padding
        // would narrow a grid that should have the full width.
        unwrapSliderFrame(listContainer);
        return null;
    }

    // Rebuilt from scratch each time — a page change replaces every row — so
    // destroy whatever the last render left behind. cleanupStyles is on
    // because the container is reused: Swiper's own classes and inline sizing
    // would otherwise stack up across pages and it would initialise against
    // its own leftovers.
    if (previous) {
        previous.destroy(true, true);
        listContainer.classList.remove('swiper', 'fct-arrows-sm', 'fct-arrows-md', 'fct-arrows-lg');
    }

    let settings = {};
    try {
        settings = JSON.parse(listContainer.getAttribute('data-slider-settings') || '{}');
    } catch (e) { /* invalid JSON — keep defaults */ }

    const showArrows = settings.arrows !== 'no';
    const arrowsPosition = ['overlap', 'outside', 'bottom'].includes(settings.arrowsPosition)
        ? settings.arrowsPosition
        : 'overlap';
    const showPagination = settings.pagination === 'yes';
    const paginationType = ['bullets', 'fraction', 'progressbar', 'segmented'].includes(settings.paginationType)
        ? settings.paginationType
        : 'bullets';
    const autoplayMode = settings.autoplay || 'no';
    // Content that starts moving on its own and keeps moving needs a way to
    // stop it (WCAG 2.2 SC 2.2.2) — and a reader who has asked their system
    // for less motion should not have to press it. 'hover' does not start on
    // its own, so neither applies to it.
    const reduceMotion = !!(window.matchMedia
        && window.matchMedia('(prefers-reduced-motion: reduce)').matches);

    const rows = Array.from(listContainer.children).filter(
        (child) => child.classList.contains('fct-review-item')
    );

    if (!rows.length) {
        // A filter that matched nothing. The arrows used to go with the rows,
        // because they were inside the track that Reviews.js empties; from
        // the frame they survive it, and would sit around the empty state
        // looking like they still page something.
        unwrapSliderFrame(listContainer);
        return null;
    }

    const wrapper = document.createElement('div');
    wrapper.className = 'swiper-wrapper';

    rows.forEach((row) => {
        const slide = document.createElement('div');
        slide.className = 'swiper-slide';
        slide.appendChild(row);
        wrapper.appendChild(slide);
    });

    listContainer.innerHTML = '';
    listContainer.appendChild(wrapper);
    // The carousel's own classes, so one stylesheet describes both arrows.
    listContainer.classList.add('swiper', `fct-arrows-${settings.arrowsSize || 'md'}`);

    let previousButton = null;
    let nextButton = null;

    if (showArrows) {
        previousButton = navButton('prev');
        nextButton = navButton('next');
        placeArrows(listContainer, previousButton, nextButton, arrowsPosition, settings.arrowsSize || 'md');
    }

    // Outside the slider, not inside it. The swiper element's height is the
    // height of its slides, and it clips what overflows, so dots appended
    // within it painted over the bottom of every card. As a sibling they sit
    // under the slider, where a pager belongs.
    // The carousels' own structure: a wrapper carrying the placement, with
    // Swiper's element inside it carrying the pill. Same class names, so the
    // two read identically and a change to one is visible against the other.
    let paginationEl = null;

    if (showPagination) {
        const paginationWrap = document.createElement('div');
        paginationWrap.className = 'fct-carousel-pagination fct-pagination-'
            + paginationType + ' fct-reviews-slider-pagination-wrap';

        paginationEl = document.createElement('div');
        paginationEl.className = 'swiper-pagination fct-reviews-slider-pagination';
        paginationWrap.appendChild(paginationEl);

        listContainer.insertAdjacentElement('afterend', paginationWrap);
    }

    const slider = new window.Swiper(listContainer, {
        slidesPerView: 1,
        spaceBetween: COLUMN_GAP,
        // Off, so every card in view is the height of the tallest and the row
        // has one baseline rather than a ragged edge. What made that painful
        // before was the content: a twenty-line review set the height for a
        // two-line one beside it. The content is clamped in this view now, so
        // the tallest card is a few lines taller than the shortest instead of
        // many.
        autoHeight: false,
        // Whole columns at a time, so a swipe never leaves a review
        // half-visible at the edge.
        slidesPerGroupAuto: true,
        observer: true,
        observeParents: true,
        loop: settings.infinite === 'yes',
        // stopOnLastSlide follows the same setting. Swiper's default is to
        // slideTo(0) on reaching the end, so autoplay would cycle whatever
        // Infinite Loop says — the reader watches it snap back to the first
        // review having been told it would not. `rewind` is the other option
        // and was not taken: it animates the jump rather than removing it,
        // which is the same contradiction, only smoother.
        autoplay: ['yes', 'hover'].includes(autoplayMode)
            ? {
                delay: Number(settings.autoplayDelay) || 3000,
                pauseOnMouseEnter: true,
                disableOnInteraction: false,
                stopOnLastSlide: settings.infinite !== 'yes',
            }
            : false,
        // Swiper's default is to lock itself when every slide already fits,
        // which hides the arrows and the dots with it. A page short enough to
        // hold all its reviews at once then looks like a list that lost its
        // slider rather than a slider with nowhere to go. Off, so the controls
        // stay and mark themselves disabled — the product carousel reads the
        // same way, and .swiper-button-disabled is already styled for it.
        watchOverflow: false,
        a11y: {enabled: true},
        keyboard: {enabled: true},
        navigation: showArrows ? {prevEl: previousButton, nextEl: nextButton} : false,
        pagination: paginationEl ? paginationConfig(paginationEl, paginationType) : false,
        on: {
            init(swiper) {
                // Hover mode starts stopped and runs only while pointed at,
                // the way the product carousel behaves. Continuous autoplay
                // also starts stopped when the reader has asked for less
                // motion — the control is there to start it if they want it.
                if ((autoplayMode === 'hover' || reduceMotion) && swiper.autoplay) {
                    swiper.autoplay.stop();
                }
            },
        },
        // Measured against the list itself, not the window. A slider in a
        // narrow theme column was showing four cramped reviews because the
        // browser was wide, and one in an editor canvas was showing two
        // because the panel beside it made the window narrow. Neither had
        // anything to do with the room the slides actually have.
        breakpointsBase: 'container',
        // The same steps the grid collapses through, and for the same reason:
        // a review under about 260px is a column of single words. Built from
        // the column count rather than fixed, so a list wide enough for what
        // was asked for shows exactly that and no width in between rounds
        // down to the two the old pair of breakpoints stopped at.
        breakpoints: columnBreakpoints(slidesPerView, columnMinWidth(listContainer)),
    });

    if (paginationEl && paginationType === 'segmented') {
        wireSegments(paginationEl, slider);
    }

    if (autoplayMode === 'hover') {
        wireHoverAutoplay(listContainer, slider, hoverAutoplayControllers);
    }

    return slider;
}

export default buildReviewSlider;
