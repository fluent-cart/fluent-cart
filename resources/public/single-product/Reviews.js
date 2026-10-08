import Lightbox from "./lightbox/lightbox";
import {buildReviewSlider} from "./review-slider";
import {applyReviewClamps} from "./review-clamp";
import {emptyChipState, isChipActive, toggleChip} from "./review-filter-chips";
import {reviewTrans} from "./review-slider/reviewTrans";

/**
 * The one lightbox every reviews widget on the page opens photos in.
 *
 * The Lightbox class builds its overlay markup once per page, binds a set
 * of handlers onto it per instance, and its close never really removes
 * it. Left to itself, a second opener — another reviews widget, or the
 * product gallery — would bind a second set of handlers onto the same
 * markup and each arrow click would move two images. So a review photo
 * gets a fresh instance and fresh markup every time it opens, and on close
 * the markup is retired: its ids come off so the next build starts clean,
 * whoever builds it, and the nodes are dropped once their fade is over.
 * Anything a stale instance does afterwards — a late image load, a
 * leftover key handler — lands on nodes no longer in the page.
 *
 * The class also leaves the keyboard alone while an image loads and has
 * no focus trap or dialog semantics of its own, so those are added here:
 * Escape closes at any moment, Tab cycles within the photo, the container
 * is named as a dialog, and the close control is labelled.
 */
// The strings the script writes into the page. At module scope rather than
// inside the lightbox, because the gallery rewrites a label of its own.
const trans = () => (window.fluentcart_review_vars && window.fluentcart_review_vars.trans) || {};

const reviewPhotoLightbox = (() => {
    let current = null;
    let showing = false;
    let pendingTearDown = null;
    // Nodes of a closed lightbox still fading out, removed once it is over.
    let fading = [];
    // What the page had before the photo locked the scroll — "hidden" under
    // an open thread modal, nothing on the bare page — put back on close.
    let bodyOverflowBefore = '';

    const root = () => (current ? document.getElementById(current.classList.lightbox) : null);

    const liveNodes = (lb) => [lb.classList.lightbox, lb.classList.lightboxOverlay]
        .map((id) => document.getElementById(id))
        .filter(Boolean);

    // Put a lightbox's markup out of the next build's reach without
    // removing it yet: the ids come off, so ensureTemplateExist() creates
    // fresh markup for whoever opens next, while these nodes finish fading.
    const retire = (lb) => {
        liveNodes(lb).forEach((el) => {
            el.removeAttribute('id');
            // The resize animation ends by focusing the backdrop from a
            // closure no override can reach; without a tabindex neither
            // node can take focus, so the call is a no-op while they fade.
            el.removeAttribute('tabindex');
            fading.push(el);
        });
        Lightbox.isBuiltOnce = false;
    };

    const tearDown = () => {
        fading.forEach((el) => el.remove());
        fading = [];
    };

    // An instance no longer in use keeps nothing on the document, and
    // nothing it does late does anything: an image still loading would
    // otherwise reach showImage(), which puts its key handler back on the
    // document and moves focus to its backdrop. The load's callback is
    // replaced on the instance, so the late work simply does not run.
    const discard = (lb) => {
        lb.disableKeyboardNav();
        window.removeEventListener('resize', lb.boundSizeOverlay);
        // Every step of the pipeline a load or a resize animation could
        // still reach: the load's callback, the resize's completion, and
        // showImage() itself, which is what rebinds the keys and moves
        // focus. A close during the resize animation lands on the last
        // two, so all three are replaced.
        lb.onImageLoaded = () => {};
        lb.sizeContainer = () => {};
        lb.showImage = () => {};
        lb.enableKeyboardNav = () => {};
        lb.sizeOverlay = () => {};
    };

    const focusables = () => {
        const el = root();
        if (!el) return [];
        return [...el.querySelectorAll('a[role="button"], button, [tabindex="0"]')]
            .filter((node) => node.offsetParent !== null);
    };

    // Capture phase: runs before the thread modal's own handler, which
    // steps aside while a photo is showing anyway.
    const onKeydown = (e) => {
        if (!showing || !current) return;

        if (e.key === 'Escape') {
            e.preventDefault();
            // The photo is closed now; the same keypress must not go on to
            // close the thread modal beneath it.
            e.stopPropagation();
            current.end();
            return;
        }
        if (e.key !== 'Tab') return;
        e.stopPropagation();

        const el = root();
        const items = focusables();
        if (!el) return;
        if (!items.length) {
            e.preventDefault();
            el.focus();
            return;
        }
        const first = items[0];
        const last = items[items.length - 1];
        // From outside, or from the dialog root itself where focus starts,
        // Tab lands on the first control and Shift+Tab on the last.
        const atEdge = !el.contains(document.activeElement) || document.activeElement === el;
        if (e.shiftKey && (atEdge || document.activeElement === first)) {
            e.preventDefault();
            last.focus();
        } else if (!e.shiftKey && (atEdge || document.activeElement === last)) {
            e.preventDefault();
            first.focus();
        }
    };

    // What the markup lacks as a dialog, added once it is built.
    const decorate = (lb) => {
        const el = document.getElementById(lb.classList.lightbox);
        if (!el) return;
        el.setAttribute('role', 'dialog');
        el.setAttribute('aria-modal', 'true');
        el.setAttribute('aria-label', trans().photo_preview || 'Photo preview');
        const close = el.querySelector('.' + lb.classList.lbClose);
        if (close) close.setAttribute('aria-label', trans().close || 'Close');
        el.focus();
        // The class moves focus to its backdrop once the image is sized,
        // an element with nothing to announce. Focus goes back to the
        // dialog whenever that happens. The nodes are fresh per open, so
        // this listener goes with them.
        const overlay = document.getElementById(lb.classList.lightboxOverlay);
        if (overlay) {
            overlay.addEventListener('focus', () => el.focus());
        }
    };

    // Wraps the instance's end(): the one moment the class does not
    // report, and the only place focus goes back and the scroll lock is
    // restored. A second call, or a call from an instance already
    // replaced, does nothing but drop that instance's key handler.
    const close = (lb) => {
        if (lb !== current || !showing) {
            lb.disableKeyboardNav();
            return;
        }
        showing = false;
        current = null;
        document.removeEventListener('keydown', onKeydown, true);
        lb.endLightbox();
        document.body.style.overflow = bodyOverflowBefore;
        retire(lb);
        discard(lb);
        clearTimeout(pendingTearDown);
        pendingTearDown = setTimeout(tearDown, lb.options.fadeDuration);
        if (lb.opener) lb.opener.focus();
    };

    return {
        isShowing() {
            return showing;
        },

        /**
         * @param {HTMLElement} opener the thumbnail, given focus back on close
         * @param {Array<{link: string, alt: string, title: string}>} album
         * @param {number} index
         */
        open(opener, album, index) {
            clearTimeout(pendingTearDown);

            // A photo already up — another widget's — hands over: its
            // instance is retired without the closing fade.
            if (showing && current) {
                const previous = current;
                showing = false;
                current = null;
                document.removeEventListener('keydown', onKeydown, true);
                retire(previous);
                discard(previous);
            } else {
                bodyOverflowBefore = document.body.style.overflow;
            }
            tearDown();

            // Markup left standing by the gallery — its close never removes
            // it — goes too, so the build below is fresh and ours alone.
            // The gallery rebinds on its next open (its own close resets its
            // handlers flag), so nothing of its is lost.
            [...document.querySelectorAll('.fct-lightbox, .fct-lightboxOverlay')].forEach((el) => el.remove());
            Lightbox.isBuiltOnce = false;

            const lb = new Lightbox({ wrapAround: true }, []);
            lb.endLightbox = lb.end.bind(lb);
            lb.end = () => close(lb);
            lb.opener = opener;
            current = lb;
            showing = true;
            document.addEventListener('keydown', onKeydown, true);
            lb.setAlbum(album);
            lb.start(index, () => decorate(lb));
        },
    };
})();

export default class FluentCartProductReviews {
    #container;
    #postId;
    #productName;
    #restUrl;
    #restNonce;
    #perPage;
    #currentPage = 1;
    #sortBy = 'created_at';
    #sortOrder = 'DESC';
    #chips = emptyChipState();
    #minRating = 0;
    // A list the page asked to narrow to reviews with photos. Not a chip: the
    // chips are the reader's choice, this one is the block's or the
    // shortcode's and every request carries it.
    #onlyWithPhotos = false;
    #showVerified = true;
    #showDate = true;
    #showReviewer = true;
    #showViewReply = true;
    #showAvatar = true;
    #showTitle = true;
    #showContent = true;
    #showPhotos = true;
    #showFooter = true;
    #showVariation = true;
    // The Swiper driving the slider view, or null in list and grid view.
    #slider = null;
    #closeModal = null;
    #clampResizeBound = false;
    // Which pager the block chose. Sent with every request because the server
    // re-renders the pager into the response — without it the first page
    // change would swap the chosen pager for the default one.
    #paginationType = 'numbers';
    #maxWords = 0;
    #mediaVisible = 0;
    #mediaWidth = 0;
    #mediaHeight = 0;
    #mediaFullWidth = false;
    #mediaFlush = false;
    #mediaBackdrop = false;
    #photosFirst = false;
    #ratingFirst = false;
    #badgeLast = false;
    #showMeta = true;
    #itemClass = '';
    #mediaMore = 'overlay';
    // The Review List block's own id, so a sort, filter or page is rendered
    // with the same blocks the page was built from rather than the fixed row.
    #blockClientId = '';
    #ratingToken = '';
    #abortController = null;
    #modalShellMarkup = null;

    #repliesAbortController = null;
    #eventAbort = new AbortController();
    #reviewsCache = {};

    constructor(container) {
        this.#container = container;
        this.#postId = container.getAttribute('data-post-id');
        this.#productName = container.getAttribute('data-product-name') || '';
        this.#restUrl = container.getAttribute('data-rest-url');
        this.#restNonce = container.getAttribute('data-rest-nonce');
        this.#perPage = parseInt(container.getAttribute('data-per-page')) || 10;
        const minRating = Number(container.getAttribute('data-min-rating'));
        this.#minRating = Number.isInteger(minRating) && minRating >= 1 && minRating <= 5 ? minRating : 0;
        this.#showVerified = container.getAttribute('data-show-verified') !== '0';
        this.#showDate = container.getAttribute('data-show-date') !== '0';
        this.#showReviewer = container.getAttribute('data-show-reviewer') !== '0';
        this.#showViewReply = container.getAttribute('data-show-view-reply') !== '0';
        this.#showAvatar = container.getAttribute('data-show-avatar') !== '0';
        this.#showTitle = container.getAttribute('data-show-title') !== '0';
        this.#showContent = container.getAttribute('data-show-content') !== '0';
        this.#showPhotos = container.getAttribute('data-show-photos') !== '0';
        this.#showFooter = container.getAttribute('data-show-footer') !== '0';
        this.#showVariation = container.getAttribute('data-show-variation') !== '0';
        this.#paginationType = container.getAttribute('data-pagination-type') || 'numbers';
        this.#maxWords = parseInt(container.getAttribute('data-max-words'), 10) || 0;
        this.#mediaVisible = parseInt(container.getAttribute('data-media-visible'), 10) || 0;
        this.#mediaWidth = parseInt(container.getAttribute('data-media-width'), 10) || 0;
        this.#mediaHeight = parseInt(container.getAttribute('data-media-height'), 10) || 0;
        this.#mediaFullWidth = container.getAttribute('data-media-full-width') === '1';
        this.#mediaFlush = container.getAttribute('data-media-flush') === '1';
        this.#mediaBackdrop = container.getAttribute('data-media-backdrop') === '1';
        this.#photosFirst = container.getAttribute('data-photos-first') === '1';
        this.#ratingFirst = container.getAttribute('data-rating-first') === '1';
        this.#badgeLast = container.getAttribute('data-badge-last') === '1';
        this.#showMeta = container.getAttribute('data-show-meta') !== '0';
        this.#itemClass = container.getAttribute('data-item-class') || '';
        this.#mediaMore = container.getAttribute('data-media-more') || 'overlay';
        this.#onlyWithPhotos = container.getAttribute('data-only-with-photos') === '1';
        // On the rows element: the block that composed the row owns the id
        // and draws that element, which the script refills rather than
        // replaces.
        this.#ratingToken = container.getAttribute('data-rating-token') || '';
        this.#blockClientId = container.querySelector('[data-reviews-list]')?.getAttribute('data-client-id') || '';

        // Read default sort from the pre-selected dropdown or data attr
        const defaultSort = container.getAttribute('data-default-sort');
        if (defaultSort) {
            const [sortBy, sortOrder] = defaultSort.split('-');
            if (sortBy) this.#sortBy = sortBy;
            if (sortOrder) this.#sortOrder = sortOrder;
        }
    }

    init() {
        if (this.#container.hasAttribute('data-fluent-cart-review-summary')) {
            document.addEventListener('fct-review-submitted', (event) => {
                if (String(event.detail?.postId) === String(this.#postId)) this.#refreshSummary();
            }, {signal: this.#eventAbort.signal});
            return;
        }

        // The first page arrives server-rendered, so there is nothing to
        // fetch until the visitor sorts, filters or paginates — the rows are
        // wired as they stand and the pagination drawn from the last-page
        // attribute the renderer printed. A server-rendered EMPTY state
        // ([data-reviews-empty]) counts too: a product without reviews must
        // keep its no-fetch path and its message, not swap it for a request.
        const listEl = this.#container.querySelector('[data-reviews-list]');
        const serverRendered = listEl && listEl.querySelector('[data-review-id], [data-reviews-empty]');

        if (serverRendered) {
            this.#wireRowEvents(listEl);

            const paginationContainer = this.#container.querySelector('[data-reviews-pagination]');
            if (paginationContainer) {
                this.#wirePaginationEvents(paginationContainer);
            }
        } else {
            this.#loadReviews();
        }

        this.#initControls();
        this.#initMediaLightbox();

        // Deep link (e.g. the customer dashboard's "Write a Review" button
        // targets {product_url}#fct-product-reviews): the hash is only a
        // marker — no element carries that id. This scrolls the
        // [data-fluent-cart-reviews] container into view once layout settles.
        if (window.location.hash === '#fct-product-reviews') {
            const reduceMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
            requestAnimationFrame(() => {
                this.#container.scrollIntoView({ behavior: reduceMotion ? 'auto' : 'smooth', block: 'start' });
            });
        }

        // Listen for review submissions from the separate Review Form block (removable via AbortController)
        document.addEventListener('fct-review-submitted', (e) => {
            if (String(e.detail?.postId) === String(this.#postId)) {
                this.#loadReviews(1);
                this.#refreshSummary();
            }
        }, { signal: this.#eventAbort.signal });
    }

    /**
     * Let go of everything this instance holds.
     *
     * A page builder replaces a widget's markup wholesale, which leaves the
     * instance behind: the document-level review-submitted listener and the
     * window resize listener keep it alive, so it answers submissions by
     * fetching and rendering into elements that are no longer on the page,
     * and every further render adds another. Calling this first is what makes
     * re-initialising a container safe to do repeatedly.
     */
    destroy() {
        if (this.#closeModal) {
            this.#closeModal();
        }

        this.#eventAbort.abort();

        if (this.#abortController) {
            this.#abortController.abort();
            this.#abortController = null;
        }

        if (this.#repliesAbortController) {
            this.#repliesAbortController.abort();
            this.#repliesAbortController = null;
        }

        if (this.#slider) {
            this.#slider.destroy(true, true);
            this.#slider = null;
        }

        this.#clampResizeBound = false;
        this.#reviewsCache = {};
    }

    #loadReviews(page = 1) {
        this.#currentPage = page;
        const listEl = this.#container.querySelector('[data-reviews-list]');
        const loadingEl = this.#container.querySelector('[data-reviews-loading]');

        // Abort any in-flight request to prevent out-of-order rendering
        if (this.#abortController) {
            this.#abortController.abort();
        }
        this.#abortController = new AbortController();

        this.#setListLoading(listEl, loadingEl, true);

        const params = new URLSearchParams({
            page: page,
            per_page: this.#perPage,
            sort_by: this.#sortBy,
            sort_order: this.#sortOrder,
            // Display flags are block attributes only the client knows; the
            // server renders the rows with them.
            show_reviewer: this.#showReviewer ? '1' : '0',
            show_date: this.#showDate ? '1' : '0',
            show_verified: this.#showVerified ? '1' : '0',
            show_view_reply: this.#showViewReply ? '1' : '0',
            show_avatar: this.#showAvatar ? '1' : '0',
            show_title: this.#showTitle ? '1' : '0',
            show_content: this.#showContent ? '1' : '0',
            show_photos: this.#showPhotos ? '1' : '0',
            show_footer: this.#showFooter ? '1' : '0',
            show_variation: this.#showVariation ? '1' : '0',
            pagination_type: this.#paginationType,
            max_words: this.#maxWords,
            media_visible: this.#mediaVisible,
            media_width: this.#mediaWidth,
            media_height: this.#mediaHeight,
            media_full_width: this.#mediaFullWidth ? '1' : '0',
            media_flush: this.#mediaFlush ? '1' : '0',
            media_backdrop: this.#mediaBackdrop ? '1' : '0',
            photos_first: this.#photosFirst ? '1' : '0',
            rating_first: this.#ratingFirst ? '1' : '0',
            badge_last: this.#badgeLast ? '1' : '0',
            show_meta: this.#showMeta ? '1' : '0',
            item_class: this.#itemClass,
            media_more: this.#mediaMore,
        });

        if (this.#ratingToken) {
            params.set('rating_token', this.#ratingToken);
        }
        if (this.#blockClientId) {
            params.set('client_id', this.#blockClientId);
        }
        if (this.#chips.ratings.length) {
            params.set('ratings', this.#chips.ratings.join(','));
        }
        if (this.#chips.hasMedia || this.#onlyWithPhotos) {
            params.set('has_media', '1');
        }
        if (this.#chips.verified) {
            params.set('verified_only', '1');
        }

        fetch(`${this.#restUrl}/public/reviews/${this.#postId}${this.#restUrl.includes('?') ? '&' : '?'}${params}`, {
            method: 'GET',
            headers: {
                'X-WP-Nonce': this.#restNonce,
            },
            signal: this.#abortController.signal,
        })
            .then(res => {
                if (!res.ok) {
                    throw new Error(res.statusText);
                }
                return res.json();
            })
            .then(data => {
                this.#setListLoading(listEl, loadingEl, false);

                if (data.reviews && data.reviews.data) {
                    this.#renderReviews(data.reviews.data, listEl, data.reviews_html || '');
                    this.#renderPagination(data.pagination_html || '');
                    const titleCountEl = this.#container.querySelector('[data-reviews-total-count]');
                    if (titleCountEl) titleCountEl.textContent = data.reviews.total.toLocaleString();
                }
            })
            .catch((err) => {
                if (err.name === 'AbortError') return;
                this.#setListLoading(listEl, loadingEl, false);
                if (listEl) {
                    listEl.textContent = '';
                    // Takes the slider down with the rows it was built from,
                    // and the frame holding its arrows with it.
                    this.#slider = buildReviewSlider(listEl, this.#slider);
                }
                const errorMessageEl = this.#container.querySelector('[data-reviews-error]');
                if (errorMessageEl) {
                    errorMessageEl.hidden = false;
                }
            });
    }

    /**
     * The loading state, as a class rather than an inline display: the
     * stylesheet floats the spinner over the rows and dims them, so the
     * rows keep their place and the page keeps its height until the new
     * rows land. aria-busy tells assistive tech the list is changing.
     */
    #setListLoading(listEl, loadingEl, isLoading) {
        if (loadingEl) {
            // The rows stay as they are until the response lands, so where
            // they are is known now. The loader is not always right above
            // them - in a composed block the count, chips and sort sit in
            // between - so the stylesheet is told where the rows start
            // relative to the loader, and their size, and centres the
            // spinner on them from there (capped, so a long list keeps the
            // spinner near the top).
            // Shown first: a hidden element has no box to measure from.
            loadingEl.classList.toggle('is-visible', isLoading);
            if (isLoading && listEl) {
                const rowsBox = listEl.getBoundingClientRect();
                const loaderBox = loadingEl.getBoundingClientRect();
                loadingEl.style.setProperty('--fct-reviews-rows-top', Math.round(rowsBox.top - loaderBox.top) + 'px');
                loadingEl.style.setProperty('--fct-reviews-rows-center-x', Math.round(rowsBox.left - loaderBox.left + rowsBox.width / 2) + 'px');
                loadingEl.style.setProperty('--fct-reviews-rows-height', Math.round(rowsBox.height) + 'px');
            }
        }
        if (listEl) {
            listEl.classList.toggle('is-loading', isLoading);
            if (isLoading) {
                listEl.setAttribute('aria-busy', 'true');
            } else {
                listEl.removeAttribute('aria-busy');
            }
        }
    }

    #renderReviews(reviews, listContainer, rowsHtml) {
        // Cache reviews for modal lookup
        this.#reviewsCache = reviews.reduce((acc, r) => { acc[r.id] = r; return acc; }, {});

        const errorMessageEl = this.#container.querySelector('[data-reviews-error]');
        if (errorMessageEl) {
            errorMessageEl.hidden = true;
        }

        // The rows arrive rendered from the server — empty state included —
        // the same way the shop grid and the thread modal already work, so
        // the markup lives in one place, PHP.
        listContainer.innerHTML = rowsHtml;

        this.#wireRowEvents(listContainer);
    }

    #wireRowEvents(listContainer) {
        this.#slider = buildReviewSlider(listContainer, this.#slider);

        // After the slider, not before. A review measured across the full list
        // fits in four lines; the same review in a third of that width does
        // not, and measuring first would clamp it and offer nothing to open it
        // with. Clamping changes the card heights, so the slider is asked to
        // measure again afterwards.
        this.#clampReviews(listContainer);
        this.#watchWidthForClamps();

        listContainer.querySelectorAll('[data-view-replies]').forEach((viewRepliesButton) => {
            viewRepliesButton.addEventListener('click', () => {
                const reviewId = viewRepliesButton.getAttribute('data-review-id');
                this.#openRepliesModal(reviewId);
            });
        });
    }

    /**
     * Clamp the rows and give the cut ones a control, at the width they are
     * actually being shown at. Word limits apply in every layout; automatic
     * line clamping is restricted to grids and sliders by applyReviewClamps.
     */
    #clampReviews(listContainer) {
        if (!listContainer) {
            return;
        }

        applyReviewClamps(listContainer, reviewTrans(), () => this.#slider && this.#slider.update());

        if (this.#slider) {
            this.#slider.update();
        }
    }

    /**
     * Re-measure when the width changes.
     *
     * Columns come and go with the window — the grid reflows, and the slider
     * crosses its breakpoints — and each of those changes how much of a review
     * fits in four lines. Bound once per widget however many times the rows are
     * replaced, and on the frame after the resize settles so the measurement is
     * of the layout that resulted from it.
     */
    #watchWidthForClamps() {
        if (this.#clampResizeBound) {
            return;
        }

        this.#clampResizeBound = true;

        let frame = null;
        // Same signal as the document listeners, so destroy() takes this with
        // it. Without one a replaced container left a listener recomputing
        // clamps for a tree nobody can see.
        window.addEventListener('resize', () => {
            if (frame) {
                cancelAnimationFrame(frame);
            }

            frame = requestAnimationFrame(() => {
                frame = null;
                this.#clampReviews(this.#container.querySelector('[data-reviews-list]'));
            });
        }, {signal: this.#eventAbort.signal});
    }

    #openRepliesModal(reviewId) {
        if (this.#closeModal) {
            this.#closeModal();
        }

        // The shell is printed hidden by ProductReviewRenderer — the same
        // split the cart drawer uses for its loader. The script only
        // reveals it and swaps in the panel fetched from the modal
        // endpoint, so no modal markup is built here.
        const overlay = this.#container.querySelector('[data-review-modal-shell]')
            || document.querySelector('[data-review-modal-shell]');
        if (!overlay) return;

        // Reset to the loading state in case a previous thread left markup.
        const shellBody = overlay.querySelector('[data-review-modal-body]');
        if (shellBody && this.#modalShellMarkup === null) {
            this.#modalShellMarkup = overlay.innerHTML;
        }
        if (this.#modalShellMarkup !== null) {
            overlay.innerHTML = this.#modalShellMarkup;
        }
        overlay.removeAttribute('hidden');

        document.body.style.overflow = 'hidden';

        // Remember the element that opened the modal so we can restore focus on close
        const triggerElement = document.activeElement;
        const modalPanel = overlay.querySelector('[data-review-modal]');
        if (modalPanel) {
            modalPanel.setAttribute('tabindex', '-1');
            // Move focus into the modal so screen readers announce it and Tab
            // is trapped properly — onto the panel, not the close button, so
            // the dialog does not open with a focus ring on the X.
            requestAnimationFrame(() => {
                modalPanel.focus();
            });
        }

        // Close handlers
        const closeModal = () => {
            // The shell is reused, so its listeners have to be removed here —
            // they no longer die with a discarded node.
            overlay.removeEventListener('click', backdropHandler);
            overlay.setAttribute('hidden', '');
            if (this.#modalShellMarkup !== null) {
                overlay.innerHTML = this.#modalShellMarkup;
            }
            document.body.style.overflow = '';
            document.removeEventListener('keydown', keyHandler);
            // Restore focus to the element that opened the modal
            if (triggerElement && typeof triggerElement.focus === 'function') {
                triggerElement.focus();
            }

            if (this.#repliesAbortController) {
                this.#repliesAbortController.abort();
                this.#repliesAbortController = null;
            }

            if (this.#closeModal === closeModal) {
                this.#closeModal = null;
            }
        };

        this.#closeModal = closeModal;

        // Keyboard: Escape closes; Tab is trapped inside the modal
        const keyHandler = (e) => {
            // A photo opened from the thread sits on top of it and takes
            // the keyboard: its Escape closes the photo, and the thread
            // stays where it was.
            if (reviewPhotoLightbox.isShowing()) return;
            if (e.key === 'Escape') {
                closeModal();
                return;
            }
            if (e.key !== 'Tab') return;

            const focusable = overlay.querySelectorAll(
                'button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), a[href], [tabindex]:not([tabindex="-1"])'
            );

            // The loading shell holds no focusable children, so without this
            // Tab would walk into the page behind an open aria-modal dialog
            // until the view arrives.
            if (!focusable.length) {
                e.preventDefault();
                overlay.querySelector('[data-review-modal]')?.focus();
                return;
            }
            const first = focusable[0];
            const last = focusable[focusable.length - 1];
            if (e.shiftKey && document.activeElement === first) {
                e.preventDefault();
                last.focus();
            } else if (!e.shiftKey && document.activeElement === last) {
                e.preventDefault();
                first.focus();
            }
        };

        const backdropHandler = (e) => {
            if (e.target === overlay) closeModal();
        };

        overlay.addEventListener('click', backdropHandler);
        document.addEventListener('keydown', keyHandler);

        // Swap in the server-rendered panel, then announce the modal so
        // extensions bind to markup that already exists.
        this.#loadModalView(reviewId, overlay, closeModal);
    }

    #loadModalView(reviewId, overlay, closeModal) {
        const panel = overlay.querySelector('[data-review-modal]');
        if (!panel) return;

        // Abort any prior in-flight modal fetch to avoid out-of-order render
        if (this.#repliesAbortController) {
            this.#repliesAbortController.abort();
        }
        this.#repliesAbortController = new AbortController();

        fetch(`${this.#restUrl}/public/reviews/${this.#postId}/${reviewId}/modal`, {
            method: 'GET',
            headers: { 'X-WP-Nonce': this.#restNonce },
            signal: this.#repliesAbortController.signal,
        })
            .then((res) => {
                if (!res.ok) throw new Error('Failed to load review');
                return res.json();
            })
            .then((data) => {
                if (!data.view) throw new Error('Empty view');

                overlay.innerHTML = data.view;

                const newPanel = overlay.querySelector('[data-review-modal]');
                if (newPanel) {
                    newPanel.setAttribute('tabindex', '-1');
                    requestAnimationFrame(() => newPanel.focus());
                }

                overlay.querySelector('[data-modal-close]')?.addEventListener('click', closeModal);

                // Extension point: Pro's threaded replies (and any third
                // party) enhance the opened thread modal through this event.
                // Fired after the server markup lands so listeners bind to
                // the footer PHP rendered for them. The originating container
                // rides along so listeners target the right product on pages
                // holding several review widgets.
                document.dispatchEvent(new CustomEvent('fct-review-modal-opened', {
                    detail: { overlay, review: this.#reviewsCache[reviewId], reviewId, container: this.#container },
                }));
            })
            .catch((err) => {
                if (err.name === 'AbortError') return;
                const body = overlay.querySelector('[data-review-modal-body]') || overlay.querySelector('[data-review-modal]');
                const errorTemplate = overlay.querySelector('[data-modal-error-template]');
                if (body && errorTemplate) {
                    body.replaceChildren(errorTemplate.content.cloneNode(true));
                    body.querySelector('[data-modal-close]')?.addEventListener('click', closeModal);
                }
            });
    }

    #renderPagination(paginationHtml) {
        const paginationContainer = this.#container.querySelector('[data-reviews-pagination]');
        if (!paginationContainer) {
            return;
        }

        // The markup arrives rendered from the server; a single page comes
        // through as an empty string and clears the strip.
        paginationContainer.innerHTML = paginationHtml || '';

        this.#wirePaginationEvents(paginationContainer);
    }

    #wirePaginationEvents(paginationContainer) {
        paginationContainer.querySelectorAll('[data-page]').forEach((pageButton) => {
            if (pageButton.disabled) return;
            pageButton.addEventListener('click', () => {
                this.#loadReviews(parseInt(pageButton.dataset.page));
                this.#container.scrollIntoView({behavior: 'smooth', block: 'start'});
            });
        });
    }

    // ─── Photo preview ───────────────────────────────────────

    /**
     * A review photo opens in the page's shared photo lightbox with the
     * review's other photos as its album, so a visitor pages through them
     * with the arrows and keys they already know. Thumbnails are
     * server-rendered (list rows and the thread modal alike) and can arrive
     * after a fetch, so the click is delegated to the widget.
     */
    #initMediaLightbox() {
        const galleryOf = (el) =>
            el.closest('.fct-review-media-gallery, [data-review-media]') || el.parentElement;

        // Every photo in the review, the ones past the visible limit included:
        // they are in the markup already, hidden rather than absent, so the
        // album the + tile opens into is the whole set.
        const photosIn = (gallery) =>
            [...gallery.querySelectorAll('.fct-review-media-thumb[data-media-type="image"]')];

        // opener is where focus returns when the lightbox closes, so it is
        // the control the visitor actually pressed — the thumbnail, or the +
        // tile when that is what opened it.
        const openAt = (gallery, index, opener) => {
            const thumbs = photosIn(gallery);
            if (!thumbs.length) return;

            const album = thumbs.map((el) => ({
                link: el.getAttribute('data-media-url'),
                alt: el.getAttribute('alt') || '',
                title: '',
            }));

            reviewPhotoLightbox.open(opener, album, Math.min(Math.max(0, index), album.length - 1));
        };

        const open = (thumb) => {
            const gallery = galleryOf(thumb);
            openAt(gallery, photosIn(gallery).indexOf(thumb), thumb);
        };

        // The + opens the lightbox on the first photo it stands for, leaving
        // the row as it is — the photos it hides stay hidden and the + stays
        // where it is, so closing the lightbox returns the visitor to the
        // gallery they left rather than a taller one.
        //
        // Hidden videos are the exception. The lightbox shows photos, and a
        // video plays where it sits, so a video left hidden is one nothing can
        // reach — while the count on this badge says it covers them. They come
        // out, and the count is rewritten to what is still behind it.
        const openOverflow = (badge) => {
            const gallery = galleryOf(badge);
            const hiddenTiles = () => [...gallery.querySelectorAll('.fct-review-media-thumb[hidden]')];

            const videos = hiddenTiles().filter((el) => el.getAttribute('data-media-type') === 'video');
            videos.forEach((el) => { el.hidden = false; });

            const photos = photosIn(gallery);
            const firstHiddenPhoto = photos.find((el) => el.hidden);

            if (firstHiddenPhoto) {
                openAt(gallery, photos.indexOf(firstHiddenPhoto), badge);
            }

            const remaining = hiddenTiles().length;

            if (!remaining) {
                // Nothing left for it to stand for. Photos are never revealed,
                // so reaching here means the overflow was all video and no
                // lightbox opened — the badge is still holding focus, and
                // removing it would drop a keyboard visitor to <body>. Focus
                // goes to the first video it just revealed instead.
                const revealed = videos[0] && videos[0].querySelector('.fct-review-media-play');
                if (revealed) {
                    revealed.focus();
                }

                badge.remove();
                return;
            }

            if (videos.length) {
                setBadgeCount(badge, remaining);
            }
        };

        // What the badge says, after the videos it was covering came out.
        const setBadgeCount = (badge, remaining) => {
            const label = badge.querySelector('span');

            badge.setAttribute('data-media-more-count', String(remaining));
            if (label) label.textContent = '+' + remaining;

            const template = trans().view_more_attachments;
            if (template) {
                badge.setAttribute('aria-label', template.replace('%s', String(remaining)));
            } else {
                badge.removeAttribute('aria-label');
            }
        };

        // A video plays where it sits, and from the moment it does the video
        // owns every click inside the tile: seeking, volume and fullscreen are
        // its own controls, and a handler here would swallow them.
        const play = (control) => {
            const tile = control.closest('.fct-review-media-video');
            const video = tile && tile.querySelector('video');

            if (!video) return;

            tile.classList.add('is-playing');
            video.controls = true;

            const started = video.play();
            if (started && typeof started.catch === 'function') {
                // Autoplay policies can refuse a programmatic play; the
                // controls are showing either way, so the visitor still has
                // one to press.
                started.catch(() => {});
            }
        };

        const act = (target) => {
            const more = target.closest('.fct-review-media-more');
            if (more) {
                openOverflow(more);
                return true;
            }

            // Only the play overlay, never the video itself — once it is
            // playing its controls are inside this tile, and claiming their
            // clicks would break seeking, volume and fullscreen.
            const playControl = target.closest('.fct-review-media-play');
            if (playControl) {
                play(playControl);
                return true;
            }

            const thumb = target.closest('.fct-review-media-thumb[data-media-type="image"]');
            if (thumb && thumb.getAttribute('data-media-url')) {
                open(thumb);
                return true;
            }

            return false;
        };

        this.#container.addEventListener('click', (e) => {
            if (act(e.target)) e.preventDefault();
        });

        // The photo thumbnail is a button to the keyboard (role/tabindex from
        // the markup), so Enter and Space open it as a click would. The + tile
        // and the video tile are real buttons and get their click from the
        // browser; only the image needs this.
        this.#container.addEventListener('keydown', (e) => {
            if (e.key !== 'Enter' && e.key !== ' ') return;
            const thumb = e.target.closest('.fct-review-media-thumb[data-media-type="image"]');
            if (!thumb || !thumb.getAttribute('data-media-url')) return;
            e.preventDefault();
            open(thumb);
        });
    }

    #initControls() {
        // Sort dropdown
        const sortSelect = this.#container.querySelector('[data-reviews-sort]');
        if (sortSelect) {
            sortSelect.addEventListener('change', () => {
                const [sortBy, sortOrder] = sortSelect.value.split('-');
                this.#sortBy = sortBy;
                this.#sortOrder = sortOrder;
                this.#loadReviews(1);
            });
        }

        // Filter chips — use event delegation so Pro-injected chips also work
        const chipsContainer = this.#container.querySelector('[data-reviews-filter-chips]');
        if (chipsContainer) {
            chipsContainer.addEventListener('click', (e) => {
                const chip = e.target.closest('[data-filter-chip]');
                if (chip) {
                    this.#onChipClick(chip.dataset.filterChip);
                }
            });
        }
    }

    #onChipClick(value) {
        const next = toggleChip(this.#chips, value, this.#minRating);

        // A chip below the list's floor, or one this script doesn't know, changes nothing.
        if (next === this.#chips) return;

        this.#chips = next;

        this.#container.querySelectorAll('[data-filter-chip]').forEach(chip => {
            const isActive = isChipActive(this.#chips, chip.dataset.filterChip);
            chip.classList.toggle('active', isActive);
            chip.setAttribute('aria-pressed', isActive ? 'true' : 'false');
        });

        this.#loadReviews(1);
    }

    #refreshSummary(isRetry = false) {
        fetch(`${this.#restUrl}/public/reviews/${this.#postId}/summary`, {
            method: 'GET',
            headers: { 'X-WP-Nonce': this.#restNonce },
        })
            .then(res => res.ok ? res.json() : Promise.reject(new Error('summary request failed')))
            .then(data => {
                if (!data?.summary) return;
                const summary = data.summary;

                // Update average number
                const avgEl = this.#container.querySelector('[data-reviews-average]');
                if (avgEl) avgEl.textContent = summary.average;

                // Update star display
                const starsEl = this.#container.querySelector('[data-reviews-stars]');
                if (starsEl && data.stars_html) {
                    // Rendered by the server, swapped in whole.
                    starsEl.innerHTML = data.stars_html;
                    if (data.stars_label) {
                        starsEl.setAttribute('aria-label', data.stars_label);
                    }
                }

                // The summary counts all reviews; the list response owns its filtered count.
                const totalEl = this.#container.querySelector('[data-reviews-total]');
                if (totalEl) totalEl.textContent = totalEl.textContent.replace(/[\d,]+/, summary.total.toLocaleString());

                // Update breakdown bars
                if (summary.breakdown) {
                    for (const star of [5, 4, 3, 2, 1]) {
                        const count = summary.breakdown[star] || 0;
                        const pct = summary.total > 0 ? Math.round((count / summary.total) * 100) : 0;
                        const rows = this.#container.querySelectorAll('[data-reviews-bar-row]');
                        const row = rows[5 - star];
                        if (row) {
                            const fill = row.querySelector('[data-reviews-bar-fill]');
                            const countEl = row.querySelector('[data-reviews-bar-count]');
                            if (fill) fill.style.width = pct + '%';
                            if (countEl) countEl.textContent = count.toLocaleString();
                        }
                    }
                }
            })
            .catch(() => {
                // Background refresh of an already-rendered summary: an error
                // banner would be noise (the visible data is stale, not
                // wrong). Retry once for transient failures, then leave the
                // pre-refresh numbers standing.
                if (!isRetry) {
                    setTimeout(() => this.#refreshSummary(true), 2000);
                }
            });
    }

}

const REVIEW_CONTAINERS = '[data-fluent-cart-reviews], [data-fluent-cart-review-summary]';

/**
 * Every container this module has bound, and the instance holding it.
 *
 * A plain Set, not a Weak one: the point is to reach instances whose
 * container has left the page, and a weak collection would have let go of
 * exactly those. Entries are removed as they are retired, so it stays the
 * size of what is actually on the page.
 */
const boundContainers = new Map();

/**
 * Retire instances whose container is no longer in the document.
 *
 * A page builder does not tell anyone it is about to replace a widget; the
 * old markup simply stops being connected. Sweeping on the way in is what
 * turns that into a teardown, so listeners, requests and sliders go with the
 * markup that owned them rather than piling up over an editing session.
 */
function retireDetachedContainers() {
    boundContainers.forEach((instance, container) => {
        if (container.isConnected) {
            return;
        }

        instance.destroy();
        boundContainers.delete(container);
    });
}

/**
 * Bind every review container inside a root that is not bound already.
 *
 * Page load is not the only moment reviews appear. A page builder replaces a
 * widget's markup when its settings change, and that markup arrives long
 * after DOMContentLoaded with nothing listening to it — a slider that never
 * builds, a sort control that does nothing. Exposed on window so a builder
 * integration can hand us the element it just drew.
 *
 * Idempotent: a container already bound is left alone, so a handler that
 * fires more than once for the same element costs nothing.
 *
 * @param {Document|Element} root where to look
 */
export function initReviewContainers(root = document) {
    const scope = root && typeof root.querySelectorAll === 'function' ? root : document;

    retireDetachedContainers();

    const containers = [...scope.querySelectorAll(REVIEW_CONTAINERS)];

    // The root can be the container rather than its parent, which is how an
    // element_ready handler hands over a widget with nothing wrapping it.
    if (typeof scope.matches === 'function' && scope.matches(REVIEW_CONTAINERS)) {
        containers.unshift(scope);
    }

    containers.forEach((container) => {
        if (boundContainers.has(container)) {
            return;
        }

        const instance = new FluentCartProductReviews(container);
        instance.init();
        boundContainers.set(container, instance);
    });
}

/**
 * Tear down the containers inside a root, for a caller that knows it is about
 * to replace them. Everything else is swept on the next init.
 *
 * @param {Document|Element} root where to look
 */
export function destroyReviewContainers(root = document) {
    const scope = root && typeof root.querySelectorAll === 'function' ? root : document;
    const containers = [...scope.querySelectorAll(REVIEW_CONTAINERS)];

    if (typeof scope.matches === 'function' && scope.matches(REVIEW_CONTAINERS)) {
        containers.unshift(scope);
    }

    containers.forEach((container) => {
        const instance = boundContainers.get(container);

        if (!instance) {
            return;
        }

        instance.destroy();
        boundContainers.delete(container);
    });
}

FluentCartProductReviews.reInit = initReviewContainers;
FluentCartProductReviews.destroy = destroyReviewContainers;

window.FluentCartProductReviews = FluentCartProductReviews;
// Kept for the builder integration that already calls it by this name.
window.fluentCartInitReviews = initReviewContainers;

// Auto-initialize on DOM ready
document.addEventListener('DOMContentLoaded', () => initReviewContainers(document));
