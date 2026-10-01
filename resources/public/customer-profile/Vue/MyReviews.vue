<template>
    <div
        class="fct-customer-my-reviews fct-customer-dashboard-layout-width"
        role="region"
        :aria-labelledby="myReviewsTitleId"
        :aria-busy="activeTab === 'history' ? loading : pendingLoading"
    >
        <!-- Where a fetched review form is mounted. Not inside the list: the
             overlay is fixed-position and the list re-renders as rows go. -->
        <div ref="formHolder" class="fct-my-reviews-form-holder"></div>

        <div class="fct-customer-dashboard-header">
            <h4 :id="myReviewsTitleId" class="fct-customer-dashboard-title">
                {{ $t('My Reviews') }}
            </h4>
        </div>

        <el-tabs v-model="activeTab" @tab-change="onTabChange">
            <el-tab-pane name="to-review" :label="pendingTabLabel">
                <div v-if="pendingLoading" aria-live="polite">
                    <el-skeleton :rows="3" animated/>
                </div>

                <div v-else aria-live="polite">
                    <ul v-if="pendingProducts.length" class="fct-my-reviews-list">
                        <li v-for="product in pendingProducts" :key="product.post_id" class="fct-my-reviews-item">
                            <div class="fct-product-info-card">
                                <div class="fct-product-info-card-inner">
                                    <div class="fct-media">
                                        <a :href="product.url" aria-hidden="true" tabindex="-1">
                                            <img :src="product.thumbnail || placeholderImage" alt="" loading="lazy"/>
                                        </a>
                                    </div>
                                    <div class="product-info-content">
                                        <div class="title">
                                            <a :href="product.url">{{ product.title }}</a>
                                        </div>
                                        <div v-if="product.variation_title" class="variation-title">
                                            {{ product.variation_title }}
                                        </div>
                                        <div v-if="product.last_purchased_at" class="variation-title">
                                            {{
                                                /* translators: %1$s: the date the product was last purchased */
                                                $t('Purchased on %1$s', formatDate(product.last_purchased_at))
                                            }}
                                        </div>
                                    </div>

                                    <!-- Inside the card's inner row, not after it: that row is
                                         already a flex line whose content column takes the
                                         slack, so the action lands hard right and level with
                                         the product without a layout of its own. -->
                                    <div class="fct-my-reviews-item-action">
                                        <button
                                            type="button"
                                            class="fct-order-item-review-btn"
                                            :disabled="openingPostId !== null"
                                            :aria-busy="openingPostId === product.post_id ? 'true' : undefined"
                                            :aria-label="$t('Write a review for') + ' ' + product.title"
                                            @click="openReviewForm(product)"
                                        >
                                            {{ $t('Write a Review') }} <span aria-hidden="true">&#9997;&#xFE0E;</span>
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </li>
                    </ul>

                    <EmptyState
                        v-else-if="pendingLoaded"
                        :title="$t('Nothing to review')"
                        :text="$t('Products you purchase will wait here for your review!')"
                    />
                </div>
            </el-tab-pane>

            <el-tab-pane name="history" :label="historyTabLabel">
                <div v-if="loading && !historyLoaded" aria-live="polite">
                    <el-skeleton :rows="3" animated/>
                </div>

                <div v-else v-loading="loading" aria-live="polite">
                    <ul v-if="reviews.length" class="fct-my-reviews-list">
                        <li v-for="review in reviews" :key="review.id" class="fct-my-reviews-item">
                            <!-- Head: what was reviewed, how it was rated, and when. -->
                            <div class="fct-my-reviews-head">
                                <a class="fct-my-reviews-thumb" :href="review.product_url" aria-hidden="true" tabindex="-1">
                                    <img :src="review.product_thumbnail || placeholderImage" alt="" loading="lazy"/>
                                </a>
                                <div class="fct-my-reviews-head-text">
                                    <!-- The variation sits with the product, not under the
                                         rating: together they name one thing, and the review
                                         is about that thing rather than about the product. -->
                                    <div class="fct-my-reviews-title-line">
                                        <a class="fct-my-reviews-product" :href="review.product_url">{{ review.product_title }}</a>
                                        <span v-if="review.item_label" class="fct-my-reviews-item-label">{{ review.item_label }}</span>
                                    </div>
                                    <div class="fct-my-reviews-head-meta">
                                        <span
                                            class="fct-my-reviews-stars"
                                            role="img"
                                            :aria-label="$t('Rated %1$s out of 5', review.rating)"
                                        >
                                            <svg
                                                v-for="star in 5"
                                                :key="star"
                                                :class="['fct-my-reviews-star', { 'is-filled': star <= review.rating }]"
                                                width="1em" height="1em" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"
                                            >
                                                <path d="M12 2.25l2.955 6.377 6.98.638-5.268 4.62 1.558 6.865L12 17.155 5.775 20.75l1.558-6.865-5.268-4.62 6.98-.638L12 2.25z"/>
                                            </svg>
                                        </span>
                                        <span class="fct-my-reviews-reviewed-on">
                                            {{ $t('Reviewed %1$s', formatDate(review.created_at)) }}
                                        </span>
                                        <span
                                            class="fct-my-reviews-status"
                                            :class="review.status === 'approved' ? 'is-approved' : 'is-pending'"
                                        >
                                            <svg v-if="review.status === 'approved'" width="14" height="14" viewBox="0 0 24 24"
                                                 fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round"
                                                 stroke-linejoin="round" aria-hidden="true"><path d="M20 6 9 17l-5-5"/></svg>
                                            <svg v-else width="14" height="14" viewBox="0 0 24 24" fill="none"
                                                 stroke="currentColor" stroke-width="2.5" stroke-linecap="round"
                                                 stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>
                                            {{ review.status === 'approved' ? $t('Approved') : $t('Pending') }}
                                        </span>
                                    </div>
                                </div>

                                <!-- The one thing the customer can do with a finished row,
                                     level with the product it belongs to. Wears the shared
                                     button — the same outline, hover fill and focus ring as
                                     Write a Review beside it and the storefront's own CTA —
                                     with fct-my-reviews-edit adjusting only its placement.

                                     can_edit is the server's answer, not a guess from the row:
                                     a review reached by email alone has no account behind it
                                     and updateReview would refuse it. -->
                                <button
                                    v-if="review.can_edit"
                                    type="button"
                                    class="fct-order-item-review-btn fct-my-reviews-edit"
                                    :disabled="openingPostId !== null"
                                    :aria-busy="openingPostId === review.post_id ? 'true' : undefined"
                                    :aria-label="$t('Edit your review of') + ' ' + review.product_title"
                                    @click="openReviewForm({post_id: review.post_id, item_id: review.item_id})"
                                >
                                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                                         stroke="currentColor" stroke-width="2" stroke-linecap="round"
                                         stroke-linejoin="round" width="15" height="15"
                                         aria-hidden="true" focusable="false">
                                        <path d="M12 20h9"/>
                                        <path d="M16.376 3.622a1 1 0 0 1 3.002 3.002L7.368 18.635a2 2 0 0 1-.855.506l-2.872.838a.5.5 0 0 1-.62-.62l.838-2.872a2 2 0 0 1 .506-.854z"/>
                                    </svg>
                                    {{ $t('Edit your review') }}
                                </button>
                            </div>

                            <strong v-if="review.title" class="fct-my-reviews-review-title">{{ review.title }}</strong>
                            <p v-if="review.content" class="fct-my-reviews-review-text">{{ visibleContent(review) }}</p>

                            <!-- Shown only when there is more to show. The text is trimmed in
                                 JS rather than clamped in CSS so the two can never disagree —
                                 a clamp that happens not to bite would leave a control that
                                 reveals nothing. -->
                            <button
                                v-if="isTruncated(review)"
                                type="button"
                                class="fct-my-reviews-readmore"
                                :aria-expanded="isExpanded(review) ? 'true' : 'false'"
                                @click="toggleExpanded(review)"
                            >
                                {{ isExpanded(review) ? $t('Show less') : $t('Read more') }}
                            </button>

                            <!-- The photos the review carries, as the product page shows them.
                                 Only present when the store has photo reviews on and PRO is
                                 attaching them, so the row simply has none otherwise. -->
                            <template v-if="reviewPhotos(review).length">
                                <span class="fct-my-reviews-photo-count">
                                    {{ $t('%1$s photos attached', reviewPhotos(review).length) }}
                                </span>
                                <ul class="fct-my-reviews-media">
                                    <li v-for="item in reviewPhotos(review)" :key="item.id || item.url">
                                        <video
                                            v-if="item.type === 'video'"
                                            class="fct-my-reviews-media-thumb"
                                            :src="item.url"
                                            controls
                                            preload="metadata"
                                        ></video>
                                        <!-- el-image's own viewer is the lightbox: click or Enter
                                             opens the full photo, Escape or a click on the backdrop
                                             closes it, and the arrows walk this review's photos.

                                             preview-teleported is not optional here: the dashboard's
                                             panels are transformed containers, and the docs are
                                             explicit that an unteleported viewer mispositions inside
                                             one. -->
                                        <el-image
                                            class="fct-my-reviews-media-thumb"
                                            :src="item.url"
                                            :alt="''"
                                            fit="cover"
                                            loading="lazy"
                                            :preview-src-list="photoUrls(review)"
                                            :initial-index="photoUrls(review).indexOf(item.url)"
                                            preview-teleported
                                            hide-on-click-modal
                                            :infinite="false"
                                            :z-index="3000"
                                            v-else
                                        >
                                            <!-- A photo whose file has gone (deleted from the Media
                                                 Library) falls back to the same placeholder the
                                                 product thumbnails use, not a broken-image glyph. -->
                                            <template #error>
                                                <img
                                                    class="fct-my-reviews-media-fallback"
                                                    :src="placeholderImage"
                                                    alt=""
                                                    loading="lazy"
                                                />
                                            </template>
                                        </el-image>
                                    </li>
                                </ul>
                            </template>
                        </li>
                    </ul>

                    <EmptyState
                        v-else-if="historyLoaded"
                        :title="$t('No reviews yet')"
                        :text="$t('Reviews you write will be shown here!')"
                    />
                </div>

                <Pagination
                    v-if="paginate && paginate.total !== 0"
                    :hide_on_single="true"
                    :pagination="paginate"
                    @fetch="fetchReviews"
                />
            </el-tab-pane>
        </el-tabs>
    </div>
</template>

<script type="text/babel">
import {markRaw} from 'vue';
import EmptyState from './EmptyState.vue';
import Pagination from "./parts/Pagination.vue";

export default {
    name: 'MyReviews',
    components: {
        EmptyState,
        Pagination
    },
    data() {
        return {
            myReviewsTitleId: 'my-reviews-title',
            placeholderImage: window.fluentcart_customer_profile_vars.placeholder_image,
            activeTab: 'to-review',
            pendingProducts: [],
            // The product whose form is being fetched, so the row's button
            // can show it and a second click cannot open a second modal.
            openingPostId: null,
            // The mounted form's controller, destroyed when its markup goes.
            // Held raw: the class keeps private fields, which a reactive
            // proxy cannot reach, so a proxied destroy() would throw.
            reviewFormController: null,
            // Products reviewed while their modal is still open. Their rows
            // hold the button the modal returns focus to, so they leave the
            // list only once the overlay has closed.
            submittedPostIds: [],
            pendingLoading: true,
            pendingLoaded: false,
            pendingFetchRunning: false,
            historyCountKnown: false,
            historyRequestId: 0,
            reviews: [],
            paginate: {
                current_page: 1,
                per_page: 10,
                total: 0
            },
            // false until fetchReviews() actually starts — history loads
            // lazily, and a region that claims busy for a fetch that is not
            // running suppresses assistive-technology announcements.
            loading: false,
            historyLoaded: false,
            // Ids whose full text the customer has asked for. Reset with the
            // rows themselves — an id from a previous page means nothing here.
            expandedReviewIds: []
        }
    },
    computed: {
        /**
         * Where a review is cut before Read more. Chosen to land around three
         * lines at the dashboard's width — long enough that most reviews are
         * shown whole and the control never appears.
         */
        contentPreviewLength() {
            return 260;
        },

        pendingTabLabel() {
            if (!this.pendingLoaded) {
                return this.$t('To Be Reviewed');
            }
            /* translators: %1$s: number of purchased products awaiting a review */
            return this.$t('To Be Reviewed (%1$s)', this.pendingProducts.length);
        },
        historyTabLabel() {
            if (!this.historyLoaded && !this.historyCountKnown) {
                return this.$t('History');
            }
            /* translators: %1$s: number of reviews the customer has written */
            return this.$t('History (%1$s)', this.paginate.total);
        }
    },
    beforeUnmount() {
        document.removeEventListener('fct-review-submitted', this.onReviewSubmitted);
        document.removeEventListener('fct-review-overlay-closed', this.onReviewOverlayClosed);
        // Leaving with the modal open: its markup goes with this component,
        // so the controller is taken out of service now — the scroll lock,
        // the document listeners and a submission still in flight with it.
        this.releaseReviewForm();
    },
    mounted() {
        document.addEventListener('fct-review-submitted', this.onReviewSubmitted);
        document.addEventListener('fct-review-overlay-closed', this.onReviewOverlayClosed);
        // Only the visible tab fetches; the other loads on first activation.
        this.fetchPendingProducts();
    },
    methods: {
        /**
         * The media PRO attached to a review, defensively.
         *
         * Free ships no media pipeline, an older PRO does not answer the
         * customer_reviews_response filter, and a review simply may have no
         * photos — all three arrive as a missing key, and all three mean the
         * same thing here: nothing to show.
         */
        reviewPhotos(review) {
            return Array.isArray(review?.media) ? review.media.filter((item) => item && item.url) : [];
        },

        /**
         * How much of a review is shown before Read more.
         *
         * Trimmed in JS rather than clamped in CSS: a clamp is invisible to the
         * code, so the control could appear with nothing to reveal, or the text
         * could be cut with no way to finish it. Here the two are the same
         * decision. Broken on a word so the cut does not land mid-word.
         */
        visibleContent(review) {
            const content = String(review?.content || '');

            if (!this.isTruncated(review) || this.isExpanded(review)) {
                return content;
            }

            const cut = content.slice(0, this.contentPreviewLength);
            const lastSpace = cut.lastIndexOf(' ');

            return (lastSpace > 0 ? cut.slice(0, lastSpace) : cut).trimEnd() + '…';
        },

        isTruncated(review) {
            return String(review?.content || '').length > this.contentPreviewLength;
        },

        isExpanded(review) {
            return this.expandedReviewIds.includes(review.id);
        },

        toggleExpanded(review) {
            if (this.isExpanded(review)) {
                this.expandedReviewIds = this.expandedReviewIds.filter((id) => id !== review.id);
                return;
            }
            this.expandedReviewIds.push(review.id);
        },

        /**
         * The image URLs of one review, for that review's lightbox. Scoped to
         * the row on purpose: paging through a neighbour's photos from inside
         * this review would be a different feature, and a confusing one.
         * Videos are excluded — el-image's preview only renders images.
         */
        photoUrls(review) {
            return this.reviewPhotos(review)
                .filter((item) => item.type !== 'video')
                .map((item) => item.url);
        },

        /**
         * Open the storefront review form for a product in a modal, the way
         * the order-review page does: the server renders the same form,
         * the page mounts it, and ReviewForm.js takes over. The customer
         * stays here instead of leaving for the product page.
         */
        /**
         * Open the storefront review form for a product, or for one of its
         * variations.
         *
         * Both tabs come through here. Writing and editing are the same form
         * and the same request — the renderer looks up the visitor's review in
         * the (product, item) slot and decides which one it is, so this side
         * never has to say. That is also why item_id has to travel: without it
         * an item-level review is asked for at product level and comes back as
         * a blank form.
         *
         * @param {{post_id: number, item_id: ?number, title: ?string}} target
         */
        openReviewForm(target) {
            if (this.openingPostId !== null) {
                return;
            }
            this.openingPostId = target.post_id;
            const params = {post_id: target.post_id};
            if (target.item_id) {
                params.item_id = target.item_id;
            }
            this.$get('customer-profile/reviews/submission-form', params)
                .then((response) => {
                    const holder = this.$refs.formHolder;
                    if (!holder) {
                        return;
                    }
                    // A form still mounted from before is replaced, not stacked.
                    this.releaseReviewForm();
                    holder.innerHTML = response.html || '';
                    const container = holder.querySelector('[data-fluent-cart-review-form]');
                    if (!container) {
                        return;
                    }
                    const detail = {container, open: true};
                    document.dispatchEvent(new CustomEvent('fct-review-form-mount', {detail}));
                    // ReviewForm.js hands the controller back on the same detail.
                    this.reviewFormController = detail.controller ? markRaw(detail.controller) : null;
                })
                .catch((error) => {
                    this.handleError(error);
                })
                .finally(() => {
                    this.openingPostId = null;
                });
        },
        // ReviewForm.js reports a success. While its overlay is still up
        // the product's row stays: the modal returns focus to that row's
        // button on close, and a detached button cannot take it. The row
        // leaves once the overlay has closed. A success that arrives after
        // the overlay was closed by hand settles at once.
        onReviewSubmitted(event) {
            const postId = Number(event.detail && event.detail.postId);
            if (!postId) {
                return;
            }
            if (this.reviewFormController) {
                this.submittedPostIds.push(postId);
                return;
            }
            this.settleSubmission(postId);
        },
        // The product leaves the pending list and the History tab, which
        // now has one more row, is refreshed: at once when it is showing,
        // on its next opening otherwise. A History request still in flight
        // is disowned, so a stale page cannot land over the new count.
        /**
         * A review was saved: bring both tabs back in step with the server.
         *
         * Two cases reach here. A first review moves the product out of To Be
         * Reviewed and adds a row to History. An edit from History changes a
         * row that is already there and moves nothing — the product stopped
         * being pending the moment it was first reviewed.
         *
         * Only the first changes the count; both leave the History rows stale,
         * which is why the reload is not inside the pending branch. It used to
         * be, so an edit saved and the customer went on looking at the text
         * they had just replaced.
         */
        settleSubmission(postId) {
            const before = this.pendingProducts.length;
            this.pendingProducts = this.pendingProducts.filter((product) => Number(product.post_id) !== postId);

            if (this.pendingProducts.length !== before) {
                this.paginate.total += 1;
                this.historyCountKnown = true;
            }

            this.historyLoaded = false;
            this.historyRequestId += 1;
            if (this.activeTab === 'history') {
                this.fetchReviews();
            }
        },
        // The overlay has closed and put focus back on the row's button:
        // the mounted markup has nothing left to do, and the reviewed rows
        // can go now. Focus, which the leaving row would drop, moves to the
        // next thing to do, or to the tab bar when nothing is left.
        onReviewOverlayClosed() {
            this.releaseReviewForm();
            if (!this.submittedPostIds.length) {
                return;
            }
            const settled = this.submittedPostIds.splice(0);
            settled.forEach((postId) => this.settleSubmission(postId));
            this.$nextTick(() => {
                // Both tabs' triggers share this class now, so one lookup
                // returns whichever tab the customer is standing on.
                const next = this.$el.querySelector('.fct-order-item-review-btn')
                    || this.$el.querySelector('.el-tabs__item.is-active')
                    || this.$el.querySelector('.el-tabs__item');
                if (next && typeof next.focus === 'function') {
                    next.focus();
                }
            });
        },
        // The mounted form's controller out of service and its markup gone.
        releaseReviewForm() {
            if (this.reviewFormController) {
                this.reviewFormController.destroy();
                this.reviewFormController = null;
            }
            if (this.$refs.formHolder) {
                this.$refs.formHolder.innerHTML = '';
            }
        },
        onTabChange(tabName) {
            if (tabName === 'history' && !this.historyLoaded) {
                this.fetchReviews();
            }
            if (tabName === 'to-review' && !this.pendingLoaded) {
                this.fetchPendingProducts();
            }
        },
        fetchPendingProducts() {
            // One in-flight request at a time — switching away and back
            // while the first is still running must not start a second.
            if (this.pendingFetchRunning) {
                return;
            }
            this.pendingFetchRunning = true;
            this.pendingLoading = true;
            this.$get("customer-profile/reviews", {type: 'pending'})
                .then((response) => {
                    this.pendingProducts = response.products || [];
                    this.pendingLoaded = true;
                    if (!this.historyLoaded && typeof response.history_total === 'number') {
                        this.paginate.total = response.history_total;
                        this.historyCountKnown = true;
                    }
                    if (!this.pendingProducts.length && this.activeTab === 'to-review') {
                        this.activeTab = 'history';
                        this.onTabChange('history');
                    }
                })
                .catch((error) => {
                    this.handleError(error);
                })
                .finally(() => {
                    this.pendingLoading = false;
                    this.pendingFetchRunning = false;
                });
        },
        fetchReviews() {
            // Rapid pagination can resolve out of order; only the latest
            // request may write state, and the page it asked for is captured
            // so rows and pagination control can never disagree.
            const requestId = ++this.historyRequestId;
            const requestedPage = this.paginate.current_page;

            this.loading = true;
            this.$get("customer-profile/reviews", {
                per_page: this.paginate.per_page,
                page: requestedPage
            })
                .then((response) => {
                    if (requestId !== this.historyRequestId) {
                        return;
                    }
                    this.reviews = response.reviews.data || [];
                    // A new page is a new set of rows; an id expanded on the
                    // previous page would otherwise expand whatever row reuses
                    // it here.
                    this.expandedReviewIds = [];
                    this.paginate.total = response.reviews.total;
                    this.paginate.current_page = requestedPage;
                    // Only a successful response marks the tab loaded — after
                    // a failed request, re-activating the tab retries instead
                    // of presenting the empty state as if there were no reviews.
                    this.historyLoaded = true;
                })
                .catch((error) => {
                    if (requestId === this.historyRequestId) {
                        this.handleError(error);
                    }
                })
                .finally(() => {
                    if (requestId === this.historyRequestId) {
                        this.loading = false;
                    }
                });
        }
    }
}
</script>
