/**
 * Clamp in JS, not CSS, so a reader without JavaScript sees the whole review
 * instead of cut text with no way to open it. Show the control only when text is hidden.
 */

/**
 * Whether the clamp hides text. The 1px tolerance ignores sub-pixel differences.
 *
 * @param {number} scrollHeight the content's full height
 * @param {number} clientHeight the height it is being shown at
 * @return {boolean}
 */
export function isTruncated(scrollHeight, clientHeight) {
    return (scrollHeight - clientHeight) > 1;
}

/**
 * Return the first `limit` words, or null for an invalid limit or uncut text.
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

/**
 * What the toggle should read and announce in a given state.
 *
 * @param {boolean} expanded
 * @param {Object} trans
 * @return {{label: string, expanded: boolean}}
 */
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
 * Keep the toggle in sync with truncation as the review's width changes.
 *
 * @param {Object} state
 * @param {boolean} state.expanded  the reader has already opened this one
 * @param {boolean} state.truncated the clamp is currently hiding something
 * @param {boolean} state.hasToggle a control is already there
 * @return {'leave'|'add'|'remove'|'none'}
 */
export function clampDecision({expanded = false, truncated = false, hasToggle = false} = {}) {
    // Resizing must not collapse text the reader has expanded.
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

        // Word limits replace line clamps so the cut is independent of width.
        // Cache the full text before replacing it; later passes see trimmed text.
        const wordLimit = parseInt(content.getAttribute('data-max-words'), 10) || 0;

        if (wordLimit) {
            if (content.dataset.fullText === undefined) {
                content.dataset.fullText = content.textContent;
            }

            const short = trimToWords(content.dataset.fullText, wordLimit);

            // Remove any stale toggle when no truncation is needed.
            if (short === null) {
                content.textContent = content.dataset.fullText;
                content.classList.remove('is-clamped');

                if (existing) {
                    existing.remove();
                }

                return;
            }

            // Clear any line clamp left by an earlier pass.
            content.classList.remove('is-clamped');

            const paintWords = (isOpen) => {
                const state = expandToggleState(isOpen, trans);
                content.textContent = state.expanded ? content.dataset.fullText : short;

                return state;
            };

            if (existing) {
                // Preserve the toggle's state after a layout change.
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

        // Measure truncation with the clamp applied at the current width.
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
            // The text fits; the toggle would have nothing to reveal.
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
