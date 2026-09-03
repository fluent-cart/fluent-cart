// Dark color palette for avatar circles — all pass WCAG AA 4.5:1 contrast with white text
const AVATAR_COLORS = [
    '#4338ca', '#6d28d9', '#be185d', '#b91c1c',
    '#c2410c', '#a16207', '#15803d', '#0f766e',
    '#0e7490', '#1d4ed8',
];

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
    #filterRating = null;
    #filterHasMedia = false;
    #filterVerified = false;
    #showVerified = true;
    #showDate = true;
    #showReviewer = true;
    #showViewReply = true;
    #abortController = null;
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
        this.#showVerified = container.getAttribute('data-show-verified') !== '0';
        this.#showDate = container.getAttribute('data-show-date') !== '0';
        this.#showReviewer = container.getAttribute('data-show-reviewer') !== '0';
        this.#showViewReply = container.getAttribute('data-show-view-reply') !== '0';

        // Read default sort from the pre-selected dropdown or data attr
        const defaultSort = container.getAttribute('data-default-sort');
        if (defaultSort) {
            const [sortBy, sortOrder] = defaultSort.split('-');
            if (sortBy) this.#sortBy = sortBy;
            if (sortOrder) this.#sortOrder = sortOrder;
        }
    }

    init() {
        if (this.#container.getAttribute('data-api-available') === '0') {
            return;
        }
        this.#loadReviews();
        this.#initControls();

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

    #loadReviews(page = 1) {
        this.#currentPage = page;
        const listEl = this.#container.querySelector('[data-reviews-list]');
        const loadingEl = this.#container.querySelector('[data-reviews-loading]');

        // Abort any in-flight request to prevent out-of-order rendering
        if (this.#abortController) {
            this.#abortController.abort();
        }
        this.#abortController = new AbortController();

        if (loadingEl) {
            loadingEl.style.display = 'block';
        }

        const params = new URLSearchParams({
            page: page,
            per_page: this.#perPage,
            sort_by: this.#sortBy,
            sort_order: this.#sortOrder,
        });

        if (this.#filterRating) {
            params.set('rating', this.#filterRating);
        }
        if (this.#filterHasMedia) {
            params.set('has_media', '1');
        }
        if (this.#filterVerified) {
            params.set('verified_only', '1');
        }

        fetch(`${this.#restUrl}/public/reviews/${this.#postId}?${params}`, {
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
                if (loadingEl) {
                    loadingEl.style.display = 'none';
                }

                if (data.reviews && data.reviews.data) {
                    this.#renderReviews(data.reviews.data, listEl);
                    this.#renderPagination(data.reviews);
                }
            })
            .catch((err) => {
                if (err.name === 'AbortError') return;
                if (loadingEl) {
                    loadingEl.style.display = 'none';
                }
                if (listEl) {
                    const trans = window.fluentcart_review_vars?.trans || {};
                    listEl.textContent = '';
                    const p = document.createElement('p');
                    p.className = 'fct-reviews-error';
                    p.textContent = trans.load_error || 'Unable to load reviews. Please try again later.';
                    listEl.appendChild(p);
                }
            });
    }

    #renderReviews(reviews, container) {
        if (!reviews.length) {
            container.textContent = '';
            const p = document.createElement('p');
            p.className = 'fct-reviews-empty';
            p.textContent = window.fluentcart_review_vars?.trans?.no_reviews || 'No reviews yet. Be the first to write a review!';
            container.appendChild(p);
            return;
        }

        // Cache reviews for modal lookup
        this.#reviewsCache = reviews.reduce((acc, r) => { acc[r.id] = r; return acc; }, {});

        let html = '';
        for (const review of reviews) {
            html += this.#renderSingleReview(review);
        }
        container.innerHTML = html;

        // Wire up "View replies" buttons via event delegation
        container.querySelectorAll('[data-view-replies]').forEach((btn) => {
            btn.addEventListener('click', () => {
                const reviewId = btn.getAttribute('data-review-id');
                this.#openRepliesModal(reviewId);
            });
        });
    }

    #openRepliesModal(reviewId) {
        const review = this.#reviewsCache[reviewId];
        if (!review) return;

        const trans = window.fluentcart_review_vars?.trans || {};

        // Remove any existing modal
        const existing = document.querySelector('.fct-review-modal-overlay');
        if (existing) existing.remove();

        const overlay = document.createElement('div');
        overlay.className = 'fct-review-modal-overlay';
        overlay.setAttribute('role', 'dialog');
        overlay.setAttribute('aria-modal', 'true');
        overlay.setAttribute('aria-label', trans.review_thread || 'Review thread');

        // Replies are fetched on-demand via the dedicated endpoint
        const repliesHtml = `<div class="fct-review-modal-empty" data-replies-loading>${this.#escapeHtml(trans.loading_replies || 'Loading replies...')}</div>`;

        // Reply form (only for owner)
        let replyFormHtml = '';
        if (review.is_owner) {
            replyFormHtml = `
                <div class="fct-review-reply-form" data-review-reply-form data-review-id="${this.#escapeHtml(String(review.id))}">
                    <div class="fct-review-reply-box">
                        <textarea
                            class="fct-review-reply-input"
                            data-review-reply-input
                            rows="3"
                            placeholder="${this.#escapeHtml(trans.reply_placeholder || 'Write a reply...')}"
                            aria-label="${this.#escapeHtml(trans.reply_placeholder || 'Write a reply...')}"
                            maxlength="2000"
                        ></textarea>
                        <span class="fct-review-reply-counter" data-reply-counter aria-hidden="true">0/2000</span>
                    </div>
                    <button type="button" class="fct-review-reply-submit" data-review-reply-submit>
                        ${this.#escapeHtml(trans.send_reply || 'Send a Reply')}
                    </button>
                </div>
            `;
        }

        // Review header inside modal — match review item header style
        const reviewerName = this.#escapeHtml(review.reviewer_name || '');
        const reviewDate = review.created_at ? this.#getRelativeTime(review.created_at) : '';
        const stars = review.rating > 0 ? this.#getStarsHtml(review.rating) : '';
        const title = review.title
            ? `<div class="fct-review-modal-title">${this.#escapeHtml(review.title)}</div>`
            : '';
        const initial = this.#getAvatarInitial(review.reviewer_name || '');
        const avatarColor = this.#getAvatarColor(review.reviewer_name || '');

        // Media gallery for the original review
        let mediaHtml = '';
        if (review.media && review.media.length) {
            mediaHtml = '<div class="fct-review-modal-media">';
            for (const m of review.media) {
                if (m.type === 'video') {
                    mediaHtml += `<video class="fct-review-media-thumb" src="${this.#escapeHtml(m.url)}" data-media-url="${this.#escapeHtml(m.url)}" data-media-type="video"></video>`;
                } else {
                    mediaHtml += `<img class="fct-review-media-thumb" src="${this.#escapeHtml(m.url)}" alt="" data-media-url="${this.#escapeHtml(m.url)}" data-media-type="image" loading="lazy"/>`;
                }
            }
            mediaHtml += '</div>';
        }

        overlay.innerHTML = `
            <div class="fct-review-modal">
                <div class="fct-review-modal-header">
                    <h3 class="fct-review-modal-heading">${this.#escapeHtml(this.#productName ? `${this.#productName}'s Review` : (trans.review_thread || 'Review Thread'))}</h3>
                    <button type="button" class="fct-review-modal-close" data-modal-close aria-label="${this.#escapeHtml(trans.close || 'Close')}"><svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 14 14" fill="none" aria-hidden="true" focusable="false"><path d="M12.8337 1.16663L1.16699 12.8333M1.16699 1.16663L12.8337 12.8333" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg></button>
                </div>
                <div class="fct-review-modal-body">
                    <div class="fct-review-modal-original">
                        <div class="fct-review-modal-author-row">
                            <div class="fct-review-avatar-circle" style="background:${avatarColor}">${this.#escapeHtml(initial)}</div>
                            <div class="fct-review-modal-meta-text">
                                <div class="fct-review-modal-name-line">
                                    <span class="fct-review-modal-author">${reviewerName}</span>
                                    ${stars ? `<span class="fct-review-modal-stars">${stars}</span>` : ''}
                                </div>
                                <div class="fct-review-modal-date">${this.#escapeHtml(reviewDate)}</div>
                            </div>
                        </div>
                        ${title}
                        <div class="fct-review-modal-content">${this.#escapeHtml(review.content)}</div>
                        ${mediaHtml}
                    </div>
                    <div class="fct-review-modal-replies">
                        ${repliesHtml}
                    </div>
                </div>
                ${replyFormHtml ? `<div class="fct-review-modal-footer">${replyFormHtml}</div>` : ''}
            </div>
        `;

        document.body.appendChild(overlay);
        document.body.style.overflow = 'hidden';

        // Remember the element that opened the modal so we can restore focus on close
        const triggerElement = document.activeElement;
        const modalPanel = overlay.querySelector('.fct-review-modal');
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
            overlay.remove();
            document.body.style.overflow = '';
            document.removeEventListener('keydown', keyHandler);
            // Restore focus to the element that opened the modal
            if (triggerElement && typeof triggerElement.focus === 'function') {
                triggerElement.focus();
            }
        };

        // Keyboard: Escape closes; Tab is trapped inside the modal
        const keyHandler = (e) => {
            if (e.key === 'Escape') {
                closeModal();
                return;
            }
            if (e.key !== 'Tab') return;

            const focusable = overlay.querySelectorAll(
                'button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), a[href], [tabindex]:not([tabindex="-1"])'
            );
            if (!focusable.length) return;
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

        overlay.addEventListener('click', (e) => {
            if (e.target === overlay) closeModal();
        });
        overlay.querySelector('[data-modal-close]')?.addEventListener('click', closeModal);
        document.addEventListener('keydown', keyHandler);

        // Fetch the full reply thread (paginated, lightweight) and render
        this.#loadReplies(reviewId, overlay);

        // Wire up reply form
        const replyForm = overlay.querySelector('[data-review-reply-form]');
        if (replyForm) {
            const submitBtn = replyForm.querySelector('[data-review-reply-submit]');
            const input = replyForm.querySelector('[data-review-reply-input]');
            const counter = replyForm.querySelector('[data-reply-counter]');
            const repliesContainer = overlay.querySelector('.fct-review-modal-replies');
            if (counter) {
                input.addEventListener('input', () => {
                    counter.textContent = `${input.value.length}/2000`;
                });
            }
            submitBtn.addEventListener('click', () => {
                const content = input.value.trim();
                if (!content || submitBtn.disabled) {
                    input.focus();
                    return;
                }
                this.#submitReply(reviewId, content, submitBtn, input, () => {
                    // Append the new reply as a comment (review owner's own reply)
                    const emptyEl = repliesContainer.querySelector('.fct-review-modal-empty');
                    if (emptyEl) emptyEl.remove();
                    this.#ensureRepliesLabel(repliesContainer);
                    const reply = {
                        is_admin_reply: 0,
                        reviewer_name: review.reviewer_name || trans.you || 'You',
                        content,
                        created_at: new Date().toISOString().slice(0, 19).replace('T', ' '),
                    };
                    repliesContainer.appendChild(this.#buildCommentEl(reply));
                    const modalBody = overlay.querySelector('.fct-review-modal-body');
                    if (modalBody) modalBody.scrollTop = modalBody.scrollHeight;
                });
            });
        }
    }

    #loadReplies(reviewId, overlay) {
        const trans = window.fluentcart_review_vars?.trans || {};
        const repliesContainer = overlay.querySelector('.fct-review-modal-replies');
        if (!repliesContainer) return;

        // Abort any prior in-flight reply fetch to avoid out-of-order render
        if (this.#repliesAbortController) {
            this.#repliesAbortController.abort();
        }
        this.#repliesAbortController = new AbortController();

        fetch(`${this.#restUrl}/public/reviews/${this.#postId}/${reviewId}/replies?per_page=50`, {
            method: 'GET',
            headers: { 'X-WP-Nonce': this.#restNonce },
            signal: this.#repliesAbortController.signal,
        })
            .then((res) => {
                if (!res.ok) throw new Error('Failed to load replies');
                return res.json();
            })
            .then((data) => {
                const items = data.replies?.data || [];
                if (!items.length) {
                    repliesContainer.innerHTML = `<div class="fct-review-modal-empty">${this.#escapeHtml(trans.no_replies || 'No replies yet.')}</div>`;
                    return;
                }
                repliesContainer.innerHTML = '';
                repliesContainer.appendChild(this.#buildRepliesLabelEl());
                for (const reply of items) {
                    repliesContainer.appendChild(this.#buildCommentEl(reply));
                }
            })
            .catch((err) => {
                if (err.name === 'AbortError') return;
                repliesContainer.innerHTML = `<div class="fct-review-modal-empty">${this.#escapeHtml(trans.replies_load_error || 'Unable to load replies.')}</div>`;
            });
    }

    // Uppercase section label above the thread — deliberately count-free:
    // the reply list is paginated, so any number here could mislead.
    #buildRepliesLabelEl() {
        const trans = window.fluentcart_review_vars?.trans || {};
        const el = document.createElement('div');
        el.className = 'fct-review-replies-count';
        el.setAttribute('data-replies-label', '');
        el.textContent = trans.reply || 'Reply';
        return el;
    }

    #ensureRepliesLabel(repliesContainer) {
        if (!repliesContainer.querySelector('[data-replies-label]')) {
            repliesContainer.prepend(this.#buildRepliesLabelEl());
        }
    }

    #buildCommentEl(reply) {
        const trans = window.fluentcart_review_vars?.trans || {};
        const isAdmin = parseInt(reply.is_admin_reply) === 1;
        const senderName = isAdmin
            ? (trans.store_reply || 'Store')
            : (reply.reviewer_name || trans.reviewer || 'Reviewer');
        const initial = this.#getAvatarInitial(senderName);
        const bg = this.#getAvatarColor(senderName);
        const replyTime = reply.created_at ? this.#getRelativeTime(reply.created_at) : '';

        const el = document.createElement('div');
        el.className = 'fct-comment' + (isAdmin ? ' fct-comment--admin' : '');
        el.innerHTML = `
            <div class="fct-comment-avatar" style="background:${bg}">${this.#escapeHtml(initial)}</div>
            <div class="fct-comment-body">
                <div class="fct-comment-head">
                    <span class="fct-comment-author">${this.#escapeHtml(senderName)}</span>
                    ${replyTime ? `<span class="fct-comment-time">${this.#escapeHtml(replyTime)}</span>` : ''}
                </div>
                <div class="fct-comment-text"></div>
            </div>
        `;
        el.querySelector('.fct-comment-text').textContent = reply.content || '';
        return el;
    }

    #submitReply(reviewId, content, submitBtn, input, onSuccess) {
        const trans = window.fluentcart_review_vars?.trans || {};
        submitBtn.disabled = true;
        const origText = submitBtn.textContent;
        submitBtn.textContent = trans.sending || 'Sending...';

        fetch(`${this.#restUrl}/public/reviews/${this.#postId}/${reviewId}/reply`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-WP-Nonce': this.#restNonce,
            },
            body: JSON.stringify({ content }),
        })
            .then((res) => {
                if (!res.ok) {
                    return res.json().then((err) => { throw new Error(err.message || 'Reply failed'); });
                }
                return res.json();
            })
            .then((res) => {
                input.value = '';
                // Sync the live character counter with the cleared input
                input.dispatchEvent(new Event('input'));
                // The server decides whether a reply publishes immediately or waits for
                // moderation. Only render it into the thread when it came back approved —
                // appending a pending reply shows the author something that vanishes on
                // the next load.
                const status = res?.data?.reply?.status ?? res?.reply?.status ?? 'approved';
                if (status === 'approved') {
                    if (typeof onSuccess === 'function') onSuccess();
                } else {
                    const message = res?.data?.message
                        ?? res?.message
                        ?? trans.reply_pending
                        ?? 'Your reply has been submitted and is pending approval.';
                    let noticeEl = submitBtn.parentElement.querySelector('.fct-review-reply-notice');
                    if (!noticeEl) {
                        noticeEl = document.createElement('span');
                        noticeEl.className = 'fct-review-reply-notice';
                        noticeEl.setAttribute('role', 'status');
                        submitBtn.parentElement.appendChild(noticeEl);
                    }
                    noticeEl.textContent = message;
                }
                this.#loadReviews(this.#currentPage);
            })
            .catch((err) => {
                let errorEl = submitBtn.parentElement.querySelector('.fct-review-reply-error');
                if (!errorEl) {
                    errorEl = document.createElement('span');
                    errorEl.className = 'fct-review-reply-error';
                    errorEl.setAttribute('role', 'alert');
                    submitBtn.parentElement.appendChild(errorEl);
                }
                errorEl.textContent = err.message || trans.reply_failed || 'Reply failed';
                setTimeout(() => {
                    if (errorEl.parentNode) errorEl.remove();
                }, 4000);
            })
            .finally(() => {
                submitBtn.disabled = false;
                submitBtn.textContent = origText;
            });
    }

    #renderSingleReview(review) {
        const trans = window.fluentcart_review_vars?.trans || {};
        const stars = review.rating > 0 ? this.#getStarsHtml(review.rating) : '';

        const verifiedBadge = (this.#showVerified && review.is_verified)
            ? `<span class="fct-review-verified">${trans.verified_purchase || 'Verified Purchase'}</span>`
            : '';

        const title = review.title
            ? `<div class="fct-review-item-title">${this.#escapeHtml(review.title)}</div>`
            : '';

        // Avatar initials circle
        const reviewerName = review.reviewer_name || '';
        const initial = this.#getAvatarInitial(reviewerName);
        const avatarColor = this.#getAvatarColor(reviewerName);
        const avatarHtml = `<div class="fct-review-avatar-circle" style="background:${avatarColor}">${this.#escapeHtml(initial)}</div>`;

        // Meta: name line (author + stars + verified badge) with the date beneath
        let nameLineParts = [];
        if (this.#showReviewer && reviewerName) {
            nameLineParts.push(`<span class="fct-review-item-author">${this.#escapeHtml(reviewerName)}</span>`);
        }
        if (stars) {
            nameLineParts.push(`<span class="fct-review-item-stars">${stars}</span>`);
        }
        if (verifiedBadge) {
            nameLineParts.push(verifiedBadge);
        }

        let metaTextParts = [];
        if (nameLineParts.length) {
            metaTextParts.push(`<div class="fct-review-item-name-line">${nameLineParts.join('')}</div>`);
        }
        if (this.#showDate && review.created_at) {
            const date = this.#getRelativeTime(review.created_at);
            metaTextParts.push(`<span class="fct-review-item-date">${this.#escapeHtml(date)}</span>`);
        }

        const headerLeft = `<div class="fct-review-item-header-left">
            ${avatarHtml}
            <div class="fct-review-item-meta-text">${metaTextParts.join('')}</div>
        </div>`;


        // Media gallery (PRO)
        let mediaHtml = '';
        if (review.media && review.media.length) {
            mediaHtml = '<div class="fct-review-media-gallery">';
            for (const m of review.media) {
                if (m.type === 'video') {
                    mediaHtml += `<video class="fct-review-media-thumb" src="${this.#escapeHtml(m.url)}" data-media-url="${this.#escapeHtml(m.url)}" data-media-type="video"></video>`;
                } else {
                    mediaHtml += `<img class="fct-review-media-thumb" src="${this.#escapeHtml(m.url)}" alt="" data-media-url="${this.#escapeHtml(m.url)}" data-media-type="image" loading="lazy"/>`;
                }
            }
            mediaHtml += '</div>';
        }

        // "View replies" trigger — opens modal that fetches the full thread
        const replyCount = parseInt(review.reply_count) || 0;
        const replyIcon = '<svg class="fct-review-reply-icon" xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="9 17 4 12 9 7"/><path d="M20 18v-2a4 4 0 0 0-4-4H4"/></svg>';
        let viewRepliesHtml = '';
        if (this.#showViewReply) {
            let replyBtnLabel;
            if (replyCount > 0) {
                replyBtnLabel = replyCount === 1
                    ? (trans.view_reply || 'View 1 reply')
                    : (trans.view_replies || 'View %d replies').replace('%d', replyCount);
            } else {
                replyBtnLabel = trans.reply || 'Reply';
            }
            viewRepliesHtml = `
                <button type="button" class="fct-review-view-replies" data-view-replies aria-haspopup="dialog" data-review-id="${this.#escapeHtml(String(review.id))}">
                    ${replyIcon}<span>${this.#escapeHtml(replyBtnLabel)}</span>
                </button>
            `;
        }

        return `
            <div class="fct-review-item"
                 data-review-id="${this.#escapeHtml(String(review.id))}"
                 data-helpful-count="${parseInt(review.helpful_count) || 0}"
                 data-not-helpful-count="${parseInt(review.not_helpful_count) || 0}"
            >
                <div class="fct-review-item-header">
                    ${headerLeft}
                </div>
                ${title}
                <div class="fct-review-item-content">${this.#escapeHtml(review.content)}</div>
                ${mediaHtml}
                ${viewRepliesHtml ? `<div class="fct-review-footer">${viewRepliesHtml}</div>` : ''}
            </div>
        `;
    }

    #renderPagination(paginationData) {
        const paginationEl = this.#container.querySelector('[data-reviews-pagination]');
        if (!paginationEl || paginationData.last_page <= 1) {
            if (paginationEl) paginationEl.innerHTML = '';
            return;
        }

        const last = paginationData.last_page;
        const current = this.#currentPage;
        const trans = window.fluentcart_review_vars?.trans || {};

        // Build a windowed page list: 1 ... current-1 current current+1 ... last
        const pages = new Set();
        pages.add(1);
        pages.add(last);
        for (let i = Math.max(2, current - 1); i <= Math.min(last - 1, current + 1); i++) {
            pages.add(i);
        }
        const sorted = [...pages].sort((a, b) => a - b);

        let html = '<div class="fct-reviews-pagination-inner">';

        // Prev button
        html += `<button class="fct-reviews-page-btn fct-reviews-page-nav" data-page="${current - 1}" ${current <= 1 ? 'disabled' : ''} aria-label="${trans.prev_page || 'Previous page'}">&lsaquo;</button>`;

        let prev = 0;
        for (const page of sorted) {
            if (page - prev > 1) {
                html += '<span class="fct-reviews-page-ellipsis">&hellip;</span>';
            }
            const activeClass = page === current ? ' active' : '';
            const ariaCurrent = page === current ? ' aria-current="page"' : '';
            html += `<button class="fct-reviews-page-btn${activeClass}" data-page="${page}"${ariaCurrent}>${page}</button>`;
            prev = page;
        }

        // Next button
        html += `<button class="fct-reviews-page-btn fct-reviews-page-nav" data-page="${current + 1}" ${current >= last ? 'disabled' : ''} aria-label="${trans.next_page || 'Next page'}">&rsaquo;</button>`;

        html += '</div>';
        paginationEl.innerHTML = html;

        paginationEl.querySelectorAll('[data-page]').forEach(btn => {
            if (btn.disabled) return;
            btn.addEventListener('click', () => {
                this.#loadReviews(parseInt(btn.dataset.page));
                this.#container.scrollIntoView({behavior: 'smooth', block: 'start'});
            });
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
        // Reset all special filters
        this.#filterRating = null;
        this.#filterHasMedia = false;
        this.#filterVerified = false;

        if (value === 'all') {
            // no filters
        } else if (value === 'has_media') {
            this.#filterHasMedia = true;
        } else if (value === 'verified') {
            this.#filterVerified = true;
        } else {
            // Numeric rating
            this.#filterRating = parseInt(value);
        }

        // Update chip active states and ARIA
        this.#container.querySelectorAll('[data-filter-chip]').forEach(chip => {
            const isActive = chip.dataset.filterChip === value;
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
                if (starsEl) {
                    let starsHtml = '';
                    const full = Math.floor(summary.average);
                    const half = (summary.average - full) >= 0.5;
                    const empty = 5 - full - (half ? 1 : 0);
                    for (let i = 0; i < full; i++) starsHtml += '<span class="fct-star fct-star-filled" aria-hidden="true">&#9733;</span>';
                    if (half) starsHtml += '<span class="fct-star fct-star-half" aria-hidden="true"><span class="fct-star-half-empty">&#9733;</span><span class="fct-star-half-fill">&#9733;</span></span>';
                    for (let i = 0; i < empty; i++) starsHtml += '<span class="fct-star fct-star-empty" aria-hidden="true">&#9733;</span>';
                    starsEl.innerHTML = starsHtml;
                    const trans = window.fluentcart_review_vars?.trans || {};
                    const ratedLabel = (trans.rated_out_of || 'Rated %d out of 5').replace('%d', summary.average);
                    starsEl.setAttribute('aria-label', ratedLabel);
                }

                // Update total count in summary and section title
                const totalEl = this.#container.querySelector('[data-reviews-total]');
                if (totalEl) totalEl.textContent = totalEl.textContent.replace(/[\d,]+/, summary.total.toLocaleString());
                const titleCountEl = this.#container.querySelector('[data-reviews-total-count]');
                if (titleCountEl) titleCountEl.textContent = summary.total.toLocaleString();

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

    #getAvatarInitial(name) {
        if (!name) return '?';
        const parts = name.trim().split(/\s+/);
        if (parts.length >= 2) {
            return (parts[0].charAt(0) + parts[parts.length - 1].charAt(0)).toUpperCase();
        }
        return name.charAt(0).toUpperCase();
    }

    #getAvatarColor(name) {
        if (!name) return AVATAR_COLORS[0];
        let hash = 0;
        for (let i = 0; i < name.length; i++) {
            hash = name.charCodeAt(i) + ((hash << 5) - hash);
        }
        const index = Math.abs(hash) % AVATAR_COLORS.length;
        return AVATAR_COLORS[index];
    }

    #parseUtcDate(dateStr) {
        if (dateStr instanceof Date) return dateStr;
        if (typeof dateStr !== 'string') return new Date(dateStr);
        const hasTz = /[zZ]|[+-]\d{2}:?\d{2}$/.test(dateStr);
        const isMysql = /^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2}/.test(dateStr);
        if (isMysql && !hasTz) {
            return new Date(dateStr.replace(' ', 'T') + 'Z');
        }
        return new Date(dateStr);
    }

    #getRelativeTime(dateStr) {
        const trans = window.fluentcart_review_vars?.trans || {};
        const date = this.#parseUtcDate(dateStr);
        const now = new Date();
        const diffMs = now - date;
        const diffSec = Math.floor(diffMs / 1000);
        const diffMin = Math.floor(diffSec / 60);
        const diffHr = Math.floor(diffMin / 60);
        const diffDay = Math.floor(diffHr / 24);
        if (diffSec < 60) return trans.just_now || 'Just now';
        if (diffMin < 60) return `${diffMin}m`;
        if (diffHr < 24) return `${diffHr}h`;
        if (diffDay < 7) return `${diffDay}d`;
        return date.toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' });
    }

    #getStarsHtml(rating) {
        const trans = window.fluentcart_review_vars?.trans || {};
        const label = (trans.rated_out_of || 'Rated %d out of 5').replace('%d', rating);
        let html = `<span class="fct-sr-only">${this.#escapeHtml(label)}</span>`;
        for (let i = 1; i <= 5; i++) {
            html += `<span class="fct-star ${i <= rating ? 'fct-star-filled' : 'fct-star-empty'}" aria-hidden="true">&#9733;</span>`;
        }
        return html;
    }

    #escapeHtml(str) {
        if (!str) return '';
        const div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }
}

// Auto-initialize on DOM ready
document.addEventListener('DOMContentLoaded', () => {
    const containers = document.querySelectorAll('[data-fluent-cart-reviews]');
    containers.forEach(container => {
        const reviews = new FluentCartProductReviews(container);
        reviews.init();
    });
});
