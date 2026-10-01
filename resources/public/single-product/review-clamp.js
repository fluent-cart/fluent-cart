/**
 * The "Read more" control on a clamped review.
 *
 * In grid and slider views the content is cut to a few lines so that cards in a
 * row end level. Cutting it is only acceptable if the rest is reachable, so
 * every clamped review gets a control that reveals it — and only those that are
 * actually cut get one, since a two-line review has nothing to reveal.
 *
 * The clamp is applied here rather than in the stylesheet on purpose. With CSS
 * alone, a reader without JavaScript would get the cut text and no control to
 * undo it: content reachable in one browser and gone in another. Applied here,
 * the two arrive together or neither does, and the fallback is a ragged row
 * rather than a truncated review.
 */

/**
 * Whether a clamped element is actually hiding anything.
 *
 * Measured rather than guessed: four lines of a two-line review is not a clamp,
 * and offering to expand it is a control that does nothing. The tolerance is
 * there because sub-pixel line heights make these differ by a fraction on
 * plenty of perfectly unclamped text.
 *
 * @param {number} scrollHeight the content's full height
 * @param {number} clientHeight the height it is being shown at
 * @return {boolean}
 */
export function isTruncated(scrollHeight, clientHeight) {
    return (scrollHeight - clientHeight) > 1;
}

/**
 * What the toggle should read and announce in a given state.
 *
 * @param {boolean} expanded
 * @param {Object} trans
 * @return {{label: string, expanded: boolean}}
 */
/**
 * The first `limit` words of a review, or all of it when there are fewer.
 *
 * Returns null when nothing would be cut, which is how the caller knows not to
 * offer a control that opens nothing — the same question isTruncated() answers
 * for the line clamp.
 *
 * @param {string} text
 * @param {number} limit
 * @return {string|null}
 */
export function trimToWords(text, limit) {
    const max = parseInt(limit, 10);

    if (!max || max < 1) {
        return null;
    }

    const words = String(text).trim().split(/\s+/);

    return words.length <= max ? null : words.slice(0, max).join(' ') + '…';
}

export function expandToggleState(expanded, trans = {}) {
    const isExpanded = !!expanded;

    return {
        expanded: isExpanded,
        label: isExpanded
            ? (trans.show_less || 'Show less')
            : (trans.read_more || 'Read more'),
    };
}

/**
 * What to do with one review, given what it looks like right now.
 *
 * Separated out because the answer changes with the width. A review measured
 * across the full list fits in four lines; the same review in a third of that
 * width does not. Whichever way that goes, the control has to follow — a clamp
 * with no control hides the rest of the review, and a control on a review that
 * is not clamped opens nothing.
 *
 * @param {Object} state
 * @param {boolean} state.expanded  the reader has already opened this one
 * @param {boolean} state.truncated the clamp is currently hiding something
 * @param {boolean} state.hasToggle a control is already there
 * @return {'leave'|'add'|'remove'|'none'}
 */
export function clampDecision({expanded = false, truncated = false, hasToggle = false} = {}) {
    // Opened by the reader. Re-clamping it because the window moved would take
    // away what they just asked to see.
    if (expanded) {
        return 'leave';
    }

    if (truncated) {
        return hasToggle ? 'none' : 'add';
    }

    return hasToggle ? 'remove' : 'none';
}

/**
 * Clamp the reviews in a list and give the cut ones a way to open.
 *
 * @param {HTMLElement} listContainer the [data-reviews-list] element
 * @param {Object} trans localized strings
 * @param {Function} [onToggle] called after a toggle, for anything that has to
 *                              re-measure — the slider's track, for instance
 */
export function applyReviewClamps(listContainer, trans = {}, onToggle = null) {
    const contents = listContainer.querySelectorAll(
        '.fct-review-item-content, .wp-block-fluent-cart-review-item-content'
    );

    contents.forEach((content) => {
        const sibling = content.nextElementSibling;
        const existing = sibling && sibling.classList.contains('fct-review-readmore') ? sibling : null;
        const expanded = !!existing && existing.getAttribute('aria-expanded') === 'true';

        // A word limit set on the Review Content block. It replaces the line
        // clamp rather than joining it: the editor asked for a number of
        // words, and a line clamp on top would cut at whichever came first —
        // a limit that changes with the column width.
        //
        // The full review is kept on the element, because the text in the DOM
        // is about to be replaced by the short version and the toggle has to
        // put it back. Read once: after the first pass textContent is already
        // the trimmed copy.
        const wordLimit = parseInt(content.getAttribute('data-max-words'), 10) || 0;

        if (wordLimit) {
            if (content.dataset.fullText === undefined) {
                content.dataset.fullText = content.textContent;
            }

            const short = trimToWords(content.dataset.fullText, wordLimit);

            // Nothing to cut, so nothing to open. Any control left over from a
            // wider layout goes with it.
            if (short === null) {
                content.textContent = content.dataset.fullText;
                content.classList.remove('is-clamped');

                if (existing) {
                    existing.remove();
                }

                return;
            }

            // The line clamp stays off wherever a word limit is in charge, so
            // the cut is the editor's number and not whichever of the two
            // lands first. Nothing below reaches this element — the branch
            // returns before the clamp is applied — and this says so where it
            // would otherwise have to be inferred.
            content.classList.remove('is-clamped');

            const paintWords = (isOpen) => {
                const state = expandToggleState(isOpen, trans);
                content.textContent = state.expanded ? content.dataset.fullText : short;

                return state;
            };

            if (existing) {
                // Re-running after a layout change: the toggle is already
                // there and knows which state it is in.
                paintWords(expanded);

                return;
            }

            const wordToggle = document.createElement('button');
            wordToggle.type = 'button';
            wordToggle.className = 'fct-review-readmore';

            const paintToggle = (isOpen) => {
                const state = paintWords(isOpen);
                wordToggle.setAttribute('aria-expanded', state.expanded ? 'true' : 'false');
                wordToggle.textContent = state.label;
            };

            paintToggle(false);

            wordToggle.addEventListener('click', () => {
                paintToggle(wordToggle.getAttribute('aria-expanded') !== 'true');

                if (typeof onToggle === 'function') {
                    onToggle();
                }
            });

            content.insertAdjacentElement('afterend', wordToggle);

            return;
        }

        // Lists without an explicit word limit continue to show the full text.
        if (!listContainer.matches('.fct-reviews-list--grid, .fct-reviews-list--slider')) {
            return;
        }

        // Measured with the clamp on, at whatever width the element is now —
        // which is the point of running this again after a layout change.
        if (!expanded) {
            content.classList.add('is-clamped');
        }

        const decision = clampDecision({
            expanded,
            truncated: isTruncated(content.scrollHeight, content.clientHeight),
            hasToggle: !!existing,
        });

        if (decision === 'leave' || decision === 'none') {
            return;
        }

        if (decision === 'remove') {
            // It fits at this width. The clamp stays on and does nothing; the
            // control would be a button that opens nothing, so it goes.
            existing.remove();

            return;
        }

        const toggle = document.createElement('button');
        toggle.type = 'button';
        toggle.className = 'fct-review-readmore';

        const paint = (isOpen) => {
            const state = expandToggleState(isOpen, trans);
            content.classList.toggle('is-clamped', !state.expanded);
            toggle.setAttribute('aria-expanded', state.expanded ? 'true' : 'false');
            toggle.textContent = state.label;
        };

        paint(false);

        toggle.addEventListener('click', () => {
            paint(toggle.getAttribute('aria-expanded') !== 'true');

            if (typeof onToggle === 'function') {
                onToggle();
            }
        });

        content.insertAdjacentElement('afterend', toggle);
    });
}

export default applyReviewClamps;
