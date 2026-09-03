import Lightbox from "./lightbox/lightbox";

// Keyed by the gallery wrapper DOM element; avoids mutating DOM nodes with
// custom properties and lets the GC collect entries when elements are removed.
const galleryControllers = new WeakMap();

window.addEventListener("fluent_cart_app_loaded", function (event) {
    document.querySelectorAll('[data-fluent-cart-product-gallery-wrapper]').forEach((container, index) => {
        new window.FluentCartImageGallery().init(container);
    });
});

window.addEventListener('fluentCartSingleProductModalOpened', function (event) {
    let enableZoom = false;
    if (window.fluentcart_single_product_vars && window.fluentcart_single_product_vars.enable_image_zoom_in_modal === 'yes') {
        enableZoom = true;
    }

    document.querySelectorAll('[data-fluent-cart-product-gallery-wrapper]').forEach((container, index) => {
        new window.FluentCartImageGallery().init(container, enableZoom);
    });
});

export default class ImageGallery {
    #imgContainer;
    #lightBox;
    #zoomer;
    #lightBoxImages;
    #currentlySelectedVariationId = 0;
    #enableZoom;
    #thumbnailControls;
    #thumbnailControlsWrapper;
    #productId;
    #thumbnailMode = 'all'; // all or by-variants
    #resizeController = null;
    // Advanced variation gallery grouping
    #primaryTermVariantMap = null; // { termId: [variantId, ...] } for the primary attribute group
    #variantFirstMediaMap  = null; // { variantId: { id: mediaId, url: firstUrl } }
    #currentPrimaryTermId  = 0;

    init(container, enableZoom = true) {
        // Abort the previous instance bound to this container (covers modal re-open
        // where a brand-new object is created) and this object if re-init'd directly.
        galleryControllers.get(container)?.abort();
        this.#resizeController?.abort();
        this.#resizeController = new AbortController();
        galleryControllers.set(container, this.#resizeController);
        this.container = container;
        this.#productId = this.container.getAttribute('data-product-id');

        // Get thumbnail mode from container attribute
        this.#thumbnailMode = this.container.getAttribute('data-thumbnail-mode') || 'all';

        this.#enableZoom = enableZoom;
        this.#thumbnailControls = this.findInContainer('[data-fluent-cart-thumb-control-button]');
        this.#thumbnailControlsWrapper = this.findOneInContainer('[data-fluent-cart-single-product-page-product-thumbnail-controls]');
        this.#imgContainer = this.findOneInContainer('[data-fluent-cart-single-product-page-product-thumbnail]');

        this.#listenForVariationChange();

        this.#initAdvVariationGallery();
        this.#setup();
        this.#initImageZoom();
        this.#initImageGallery();

        this.#prepareLightboxImages();
        this.#setupThumbnailControls();
        this.#initScrollableThumbs();
        this.#initSeeMoreButton();
    }

    #initAdvVariationGallery() {
        // Only applies to advanced variation products. The .fct-advanced-variation-wrap
        // element (rendered by AdvancedVariationRenderer.php) carries:
        //   data-primary-group-id  — the group whose terms drive gallery swapping
        //   data-attribute-config  — { groupId: [termId, ...] }
        //   data-variation-map     — { "termA_termB": variantId, ... }
        // From these we derive primaryTermVariantMap = { termId: [variantId, ...] }
        // so thumbnails can be activated by primary-group term instead of by variant ID.
        // Scope the lookup to THIS product's root. The gallery wrapper and the
        // pricing section are siblings under [data-fluent-cart-single-product-page],
        // so closest() from the gallery can't reach the pricing section. A bare
        // document.querySelector would grab the FIRST pricing section in the page —
        // wrong product when the modal is appended after a background product, which
        // leaves #primaryTermVariantMap null and breaks thumbnail activation.
        const productRoot = this.container.closest('[data-fluent-cart-single-product-page]');
        const scope = productRoot || document;

        const variationWrap = scope.querySelector('[data-primary-group-id]');

        if (!variationWrap) return;
        const primaryGroupId = parseInt(variationWrap.getAttribute('data-primary-group-id') || '0', 10);
        if (!primaryGroupId) return;

        let attributeConfig = {};
        let variationMap    = {};
        try {
            attributeConfig = JSON.parse(variationWrap.getAttribute('data-attribute-config') || '{}');
            variationMap    = JSON.parse(variationWrap.getAttribute('data-variation-map')     || '{}');
        } catch (e) {
            return;
        }

        const primaryTermIds = (attributeConfig[String(primaryGroupId)] || []).map(Number);
        if (!primaryTermIds.length) return;

        // Build { termId: [variantId, ...] } by checking each identifier for a primary-group term
        const primaryTermVariantMap = {};
        primaryTermIds.forEach(termId => { primaryTermVariantMap[termId] = []; });

        Object.entries(variationMap).forEach(([identifier, variantId]) => {
            const identifierTermIds = identifier.split('_').map(Number);
            primaryTermIds.forEach(termId => {
                if (identifierTermIds.includes(termId)) {
                    primaryTermVariantMap[termId].push(Number(variantId));
                }
            });
        });

        this.#primaryTermVariantMap = primaryTermVariantMap;

        // Read the variant → first-media map emitted by PHP on the controls wrapper.
        try {
            const rawJson = this.#thumbnailControlsWrapper
                ? this.#thumbnailControlsWrapper.getAttribute('data-variant-first-media-map')
                : null;
            if (rawJson) {
                this.#variantFirstMediaMap = JSON.parse(rawJson);
            }
        } catch (e) {
            this.#variantFirstMediaMap = null;
        }

        // Activate the thumbnail for the initially selected primary-group term.
        // AdvancedVariationSelector may fire its default-selection event before this
        // listener is registered, so we read the current DOM state directly.
        let initialSelectedTermId = 0;
        const selectedSwatch = variationWrap.querySelector(
            `.fct-swatch-item[data-group-id="${primaryGroupId}"].selected, ` +
            `.fct-swatch-item[data-group-id="${primaryGroupId}"][aria-checked="true"]`
        );
        if (selectedSwatch) {
            initialSelectedTermId = parseInt(selectedSwatch.getAttribute('data-term-id') || '0', 10);
        }
        if (!initialSelectedTermId) {
            const selectedOption = variationWrap.querySelector(
                `[data-attribute-group="${primaryGroupId}"] [data-fct-select-option][aria-selected="true"]`
            );
            if (selectedOption) {
                initialSelectedTermId = parseInt(selectedOption.getAttribute('data-value') || '0', 10);
            }
        }
        if (initialSelectedTermId) {
            this.#filterGalleryByPrimaryTerm(initialSelectedTermId);
        }
    }

    // Activate the first thumbnail belonging to the given primary-group term.
    // termId === 0 means the swatch was deselected — fall back to the first visible thumbnail.
    // Matches via data-variation-id using the JS-built primaryTermVariantMap,
    // so this works even when PHP's gallery_variation_data filter returns an empty variant_term_map.
    #filterGalleryByPrimaryTerm(termId) {
        if (!this.#primaryTermVariantMap) return;
        this.#currentPrimaryTermId = Number(termId);

        const visibleThumbnails = this.#getVisibleThumbnails();
        if (!visibleThumbnails.length) return;

        if (!this.#currentPrimaryTermId) {
            this.#handleThumbnailChange(visibleThumbnails[0]);
            return;
        }

        const termVariantIds = new Set((this.#primaryTermVariantMap[this.#currentPrimaryTermId] || []).map(String));
        const matchingThumb = termVariantIds.size
            ? visibleThumbnails.find(btn => termVariantIds.has(btn.getAttribute('data-variation-id') || ''))
            : null;

        if (matchingThumb) {
            this.#handleThumbnailChange(matchingThumb);
        } else {
            // Selected term has no image of its own — reset to the product
            // default / placeholder instead of leaving the previously selected
            // term's image on screen.
            this.#resetMainImageToDefault();
        }
    }

    // Reset the main gallery image to the product default (featured image, or
    // the placeholder.svg the renderer emits when the product has no image) and
    // clear any active thumbnail, since no term/variant thumbnail represents it.
    #resetMainImageToDefault() {
        const productThumbnail = this.findOneInContainer('[data-fluent-cart-single-product-page-product-thumbnail]');
        if (!productThumbnail) return;

        const defaultUrl = productThumbnail.dataset.defaultImageUrl;
        if (!defaultUrl) return;

        productThumbnail.setAttribute('src', defaultUrl);

        this.#thumbnailControls.forEach(ctrl => {
            ctrl.classList.remove('active');
            ctrl.setAttribute('aria-pressed', 'false');
            ctrl.setAttribute('tabindex', '-1');
        });
    }

    // Activate the thumbnail whose media matches the given variant's first image.
    // For advanced variation products, uses the PHP-emitted variant→first-media map:
    //   match by data-media-id when the WP attachment ID is known (id > 0),
    //   fall back to data-url for externally imported images (id = 0).
    // If the variant has no entry in the map, resets to the product default /
    // placeholder image instead of leaving the previous variant's image up.
    // For simple variation products (no map), falls back to lightbox URL lookup.
    #activateFirstThumbForVariant(variantId) {
        const visibleThumbnails = this.#getVisibleThumbnails();
        if (!visibleThumbnails.length) return;

        if (this.#variantFirstMediaMap) {
            const firstMedia = this.#variantFirstMediaMap[String(variantId)];
            if (!firstMedia) {
                // Variant has no media — show the product default / placeholder
                // rather than leaving the previously selected variant's image up.
                this.#resetMainImageToDefault();
                return;
            }

            let matchingThumb = null;
            if (firstMedia.id > 0) {
                matchingThumb = visibleThumbnails.find(btn => btn.getAttribute('data-media-id') === String(firstMedia.id));
            }
            if (!matchingThumb) {
                matchingThumb = visibleThumbnails.find(btn => btn.getAttribute('data-url') === firstMedia.url);
            }
            if (matchingThumb) {
                this.#handleThumbnailChange(matchingThumb);
            } else {
                this.#resetMainImageToDefault();
            }
            return;
        }

        // Simple variation fallback — URL match via lightbox images
        const variantLightboxImages = this.#lightBoxImages?.[String(variantId)];
        const firstImageUrl = variantLightboxImages?.[0]?.link ?? null;
        if (firstImageUrl) {
            const matchingThumb = visibleThumbnails.find(btn => btn.getAttribute('data-url') === firstImageUrl);
            if (matchingThumb) this.#handleThumbnailChange(matchingThumb);
        }
    }

    #listenForVariationChange() {
        const signal = this.#resizeController.signal;

        // Batch gallery updates into one paint frame to prevent flicker when both
        // fluentCartAdvVariationImageChanged (primary-group term) and
        // fluentCartSingleProductVariationChanged (full combination) fire in the same tick.
        // The variant-specific update always wins over the term-group update.
        let pendingTermId    = null;
        let pendingVariantId = null;
        let rafScheduled     = false;

        const flushGalleryUpdate = () => {
            rafScheduled = false;
            if (pendingVariantId !== null) {
                this.#activateFirstThumbForVariant(pendingVariantId);
            } else if (pendingTermId !== null) {
                this.#filterGalleryByPrimaryTerm(pendingTermId);
            }
            pendingTermId    = null;
            pendingVariantId = null;
        };

        window.addEventListener("fluentCartSingleProductVariationChanged", (event) => {
            const eventProductId = event.detail.productId;
            if (eventProductId != this.#productId) {
                return;
            }
            this.#currentlySelectedVariationId = event.detail.variationId;

            if (this.#primaryTermVariantMap) {
                pendingVariantId = this.#currentlySelectedVariationId;
                pendingTermId    = null;
                if (!rafScheduled) { rafScheduled = true; requestAnimationFrame(flushGalleryUpdate); }
                return;
            }

            this.updateGalleryByVariation(this.#currentlySelectedVariationId);

            const variationControlButtons = this.#thumbnailControlsWrapper?.querySelectorAll(`[data-fluent-cart-thumb-control-button][data-variation-id="${this.#currentlySelectedVariationId}"]`);
            this.#setupControlWrapper(variationControlButtons);
        }, { signal });

        window.addEventListener("fluentCartAdvVariationImageChanged", (event) => {
            if (event.detail.productId != this.#productId) {
                return;
            }

            // termId > 0 → activate that primary-group term. termId === 0 → swatch deselected, reset.
            if (this.#primaryTermVariantMap) {
                pendingTermId    = event.detail.termId;
                pendingVariantId = null;
                if (!rafScheduled) { rafScheduled = true; requestAnimationFrame(flushGalleryUpdate); }
                return;
            }

            // Fallback for non-adv products: direct src swap
            const productThumbnail = this.findOneInContainer('[data-fluent-cart-single-product-page-product-thumbnail]');
            if (productThumbnail) {
                productThumbnail.setAttribute('src', event.detail.url);
            }
        }, { signal });
    }

    #setup() {
        const controlButtons = this.#thumbnailControlsWrapper?.querySelectorAll('[data-fluent-cart-thumb-control-button]:not(.is-hidden)');
        const activeControl = this.#thumbnailControlsWrapper?.querySelector('.active[data-fluent-cart-thumb-control-button]:not(.is-hidden)');

        if (activeControl) {
            this.#currentlySelectedVariationId = activeControl.dataset.variationId;
        } else {
            this.#currentlySelectedVariationId = controlButtons?.[0]?.dataset?.variationId || 0;
        }
        this.updateGalleryByVariation(this.#currentlySelectedVariationId);
    }


    #initImageGallery() {
        if(this.#imgContainer){
            this.#imgContainer.removeEventListener('click', this.#openLightBox.bind(this));
        }
        this.#lightBox = new Lightbox({}, []);
        if (this.#imgContainer){
            this.#imgContainer.addEventListener('click', this.#openLightBox.bind(this));
        }
    }


    #openLightBox(event) {
        const imageParentContainer = this.#imgContainer.parentElement;

        // Check if position is already relative
        const originalPosition = imageParentContainer.style.position;
        const positionWasEmpty = !originalPosition || originalPosition === '';
        const modal = document.querySelector('[data-fluent-cart-shop-app-single-product-modal]');

        if (modal) {
            window.FluentCartSingleProductModal.closeModal(modal);
        }

        if (positionWasEmpty) {
            imageParentContainer.style.position = 'relative';
        }

        const overlayDiv = document.createElement('div');
        overlayDiv.style.position = 'fixed';
        overlayDiv.style.top = '0';
        overlayDiv.style.left = '0';
        overlayDiv.style.right = '0';
        overlayDiv.style.bottom = '0';
        overlayDiv.style.zIndex = '99999';
        document.body.appendChild(overlayDiv);

        if (this.#zoomer && this.#zoomer.closezoom) {
            this.#zoomer.closezoom();
        }

        setTimeout(() => {
            if (overlayDiv.parentNode) {
                overlayDiv.parentNode.removeChild(overlayDiv);
            }

            // Remove inline position style only if we added it
            if (positionWasEmpty) {
                imageParentContainer.style.position = '';
            }
        }, 1000);

        const target = event.target;

        let lightboxAlbum = this.#getLightboxAlbumByMode();

        const imageIndex = this.#getImageIndexFromAlbum(lightboxAlbum, target.getAttribute('src'));
        if (imageIndex > -1) {
            this.#lightBox.setAlbum(lightboxAlbum);
            this.#lightBox.start(imageIndex, () => {
            });
        }
    }

    #getLightboxAlbumByMode() {
        if (this.#thumbnailMode === 'all') {
            // Advanced variation with active color filter: show only that group's images
            if (this.#currentPrimaryTermId && this.#primaryTermVariantMap) {
                const allowedVariantIds = new Set((this.#primaryTermVariantMap[this.#currentPrimaryTermId] || []).map(String));
                if (allowedVariantIds.size) {
                    const seen = new Set();
                    const termAlbum = [];
                    Object.entries(this.#lightBoxImages).forEach(([varId, images]) => {
                        // Include this term's variants AND the shared default /
                        // featured images (variation 0), so the featured image is
                        // viewable in the lightbox too — matching the by-variants
                        // branch below, which already merges variation 0.
                        if (allowedVariantIds.has(varId) || varId === '0') {
                            images.forEach(img => {
                                if (!seen.has(img.link)) {
                                    seen.add(img.link);
                                    termAlbum.push(img);
                                }
                            });
                        }
                    });
                    if (termAlbum.length > 0) return termAlbum;
                }
            }
            return this.#getAllImagesForLightbox();
        } else {
            // Show only current variation + default images (by-variants mode)
            let lightboxAlbum = this.#lightBoxImages[this.#currentlySelectedVariationId];
            let defaultLightboxAlbum = this.#lightBoxImages[0];

            if (lightboxAlbum && defaultLightboxAlbum) {
                lightboxAlbum = [...lightboxAlbum, ...defaultLightboxAlbum];
            }

            if (!lightboxAlbum || lightboxAlbum.length === 0) {
                lightboxAlbum = this.#lightBoxImages[0];
            }

            return lightboxAlbum;
        }
    }

    #getAllImagesForLightbox() {
        const allImages = [];
        const addedVariationIds = new Set();

        const addImages = (variationId, images) => {
            const key = String(variationId);
            if (addedVariationIds.has(key)) return;
            addedVariationIds.add(key);
            if (Array.isArray(images)) {
                images.forEach(img => allImages.push(img));
            }
        };

        // First, add current variation images
        if (this.#currentlySelectedVariationId && this.#lightBoxImages[this.#currentlySelectedVariationId]) {
            addImages(this.#currentlySelectedVariationId, this.#lightBoxImages[this.#currentlySelectedVariationId]);
        }

        // Then add default/variation 0 images
        if (this.#lightBoxImages[0]) {
            addImages('0', this.#lightBoxImages[0]);
        }

        // Finally, add all other variation images
        Object.keys(this.#lightBoxImages).forEach(variationId => {
            addImages(variationId, this.#lightBoxImages[variationId]);
        });

        return allImages.length > 0 ? allImages : this.#lightBoxImages[0] || [];
    }

    updateGalleryByVariation(variationId = 0) {
        // If thumbnail mode is 'all', show all thumbnails — BUT if an advanced
        // variation gallery filter is active, respect the existing filter instead
        // of un-hiding everything.
        if (this.#thumbnailMode === 'all') {
            if (this.#primaryTermVariantMap) {
                return; // thumbnail activation is managed by #filterGalleryByPrimaryTerm / #activateFirstThumbForVariant
            }
            const allThumbnails = this.findInContainer('[data-fluent-cart-thumb-control-button]');
            allThumbnails.forEach(img => img.classList.remove('is-hidden'));
            return; // Don't hide any thumbnails
        }

        // Original behavior for 'by-variants' mode
        const variationImages = this.findInContainer(`[data-fluent-cart-thumb-control-button][data-variation-id="${variationId}"]`);
        if (variationImages.length > 0) {
            const otherImages = this.findInContainer(`[data-fluent-cart-thumb-control-button][data-variation-id]:not([data-variation-id="${variationId}"])`);
            otherImages.forEach(img => img.classList.add('is-hidden'));
            variationImages.forEach(img => img.classList.remove('is-hidden'));
        }

        const defaultImages = this.findInContainer(`[data-fluent-cart-thumb-control-button][data-variation-id="0"]`);
        defaultImages.forEach(img => img.classList.remove('is-hidden'));
    }

    #initImageZoom() {
        const zoomEnabledOnPage = window.fluentcart_single_product_vars.enable_image_zoom === 'yes';

        if (zoomEnabledOnPage && this.#enableZoom) {
            if (this.#zoomer == null && this.#imgContainer) {
                window.onload = function () {
                    // Select your main image and any gallery thumbnails
                };

                this.#zoomer = xZoom(this.#imgContainer, {
                    tint: false,
                    Yoffset: 120,
                    zoomWidth: 500,
                    zoomHeight: 400,
                    position: 'inside',
                    lensSize: 300,
                });
            }
        }
    }

    #getImageIndexFromAlbum(album, imageSrc) {
        if (!Array.isArray(album)) {
            return -1;
        }
        return album.findIndex(item => item.link === imageSrc);
    }

    findInContainer(selector) {
        return this.container.querySelectorAll(selector);
    }

    findOneInContainer(selector) {
        return this.container.querySelector(selector);
    }


    #supportsHoverAndFinePointer() {
        return window.matchMedia('(hover: hover) and (pointer: fine)').matches;
    }

    #prepareLightboxImages() {
        const lightBoxImages = {};

        // Use JSON attribute when max thumbnails is limiting visible buttons
        // (more images in JSON than buttons in DOM means some are hidden)
        const allImagesJson = this.#thumbnailControlsWrapper?.getAttribute('data-all-gallery-images');
        if (allImagesJson) {
            try {
                const allImages = JSON.parse(allImagesJson);
                if (allImages.length > this.#thumbnailControls.length) {
                    allImages.forEach((img) => {
                        const variationId = (img.variation_id != null && img.variation_id !== '') ? String(img.variation_id) : '0';
                        if (!lightBoxImages.hasOwnProperty(variationId)) {
                            lightBoxImages[variationId] = [];
                        }
                        lightBoxImages[variationId].push({ alt: img.title || '', link: img.url, title: img.title || '' });
                    });
                    this.#lightBoxImages = lightBoxImages;
                    return;
                }
            } catch (e) {
                // Fall through to DOM-based approach
            }
        }

        // Default: gather images from thumbnail buttons in the DOM
        if (this.#thumbnailControls.length > 0) {
            this.#thumbnailControls.forEach((control, index) => {
                const variationId = control.dataset.variationId.toString();
                const image = control.querySelector('[data-fluent-cart-single-product-page-product-thumbnail-controls-thumb]');

                if (!image) {
                    return;
                }

                if (!lightBoxImages.hasOwnProperty(variationId)) {
                    lightBoxImages[variationId] = [];
                }

                if (this.#getImageIndexFromAlbum(lightBoxImages[variationId], image.src) === -1) {
                    lightBoxImages[variationId].push({
                        alt: image.alt,
                        link: image.src,
                        title: image.alt
                    });
                }
            });
        }

        this.#lightBoxImages = lightBoxImages;
    }

    #setupControlWrapper(controlButtons) {
        // First, remove 'active' class from all thumbnails
        this.#thumbnailControls.forEach(ctrl => ctrl.classList.remove('active'));


        if (controlButtons && controlButtons.length > 0) {
            const control = controlButtons[0];
            control.click();
            control.classList.add('active');
            this.#setThumbImage(control);
        }
    }

    #setupThumbnailControls() {
        // Attach to ALL buttons, not just the currently-visible set. When advanced
        // variation color filtering reveals buttons that were hidden at init time,
        // they must already have click listeners attached.
        this.findInContainer('[data-fluent-cart-thumb-control-button]').forEach(control => {
            control.addEventListener('click', () => {
                this.#handleThumbnailChange(control);
            });
        });

        if (this.#thumbnailControlsWrapper) {
            this.#thumbnailControlsWrapper.addEventListener('keydown', (event) => {
                this.#handleThumbnailKeydown(event);
            });
        }
    }

    #getVisibleThumbnails() {
        return Array.from(
            this.#thumbnailControlsWrapper?.querySelectorAll('[data-fluent-cart-thumb-control-button]:not(.is-hidden)') || []
        );
    }

    #handleThumbnailKeydown(event) {
        const isVertical = this.container.classList.contains('thumb-pos-left') || this.container.classList.contains('thumb-pos-right');
        const nextKeys = isVertical ? ['ArrowDown'] : ['ArrowRight'];
        const prevKeys = isVertical ? ['ArrowUp'] : ['ArrowLeft'];

        // Support both axes regardless of layout for convenience
        const isNext = nextKeys.includes(event.key) || (!isVertical && event.key === 'ArrowDown');
        const isPrev = prevKeys.includes(event.key) || (!isVertical && event.key === 'ArrowUp');

        if (!isNext && !isPrev) return;

        event.preventDefault();
        const visible = this.#getVisibleThumbnails();
        if (visible.length === 0) return;

        const currentIndex = visible.indexOf(document.activeElement);
        let nextIndex;

        if (isNext) {
            nextIndex = currentIndex < visible.length - 1 ? currentIndex + 1 : 0;
        } else {
            nextIndex = currentIndex > 0 ? currentIndex - 1 : visible.length - 1;
        }

        const target = visible[nextIndex];
        target.focus();
        this.#handleThumbnailChange(target);
    }

    #handleThumbnailChange(control) {
        this.#thumbnailControls.forEach(ctrl => {
            ctrl.classList.remove('active');
            ctrl.setAttribute('aria-pressed', 'false');
            ctrl.setAttribute('tabindex', '-1');
        });
        control.classList.add('active');
        control.setAttribute('aria-pressed', 'true');
        control.setAttribute('tabindex', '0');

        // Update current variation ID based on clicked thumbnail
        const variationId = control.dataset.variationId;
        if (variationId !== undefined) {
            this.#currentlySelectedVariationId = variationId;
        }

        this.#setThumbImage(control);
    }

    #setThumbImage(control) {
        const productThumbnail = this.findOneInContainer('[data-fluent-cart-single-product-page-product-thumbnail]');
        if (!productThumbnail) return;

        let thumbnailUrl = control.dataset.url;
        if (thumbnailUrl === undefined) {
            thumbnailUrl = productThumbnail.dataset.defaultImageUrl;
        }

        productThumbnail.setAttribute('src', thumbnailUrl);
    }

    #initScrollableThumbs() {
        const isScrollable = this.container.getAttribute('data-scrollable-thumbs') === 'yes';
        if (!isScrollable || !this.#thumbnailControlsWrapper) return;

        const position = this.container.classList.contains('thumb-pos-left') || this.container.classList.contains('thumb-pos-right');
        if (!position) return;

        // For left/right positions, match thumbnail container height to main image height
        const syncHeight = () => {
            const galleryThumb = this.findOneInContainer('.fct-product-gallery-thumb');
            if (galleryThumb && this.#thumbnailControlsWrapper) {
                this.#thumbnailControlsWrapper.style.maxHeight = galleryThumb.offsetHeight + 'px';
            }
        };

        // Sync on load and resize
        const mainImg = this.#imgContainer;
        if (mainImg && mainImg.tagName === 'IMG') {
            if (mainImg.complete) {
                syncHeight();
            } else {
                mainImg.addEventListener('load', syncHeight, { once: true, signal: this.#resizeController.signal });
            }
        }

        window.addEventListener('resize', syncHeight, { signal: this.#resizeController.signal });
    }

    #initSeeMoreButton() {
        const seeMoreBtn = this.findOneInContainer('[data-fluent-cart-gallery-see-more]');
        if (!seeMoreBtn) return;

        seeMoreBtn.addEventListener('click', () => {
            // Open lightbox with ALL images, starting from the first hidden one
            const allImages = this.#getAllImagesForLightbox();
            if (!allImages || allImages.length === 0) return;

            // Start from the image after the last visible thumbnail (exclude hidden ones from by-variants mode)
            const visibleCount = this.findInContainer('[data-fluent-cart-thumb-control-button]:not(.is-hidden)').length;
            const startIndex = Math.min(visibleCount, allImages.length - 1);

            this.#lightBox.setAlbum(allImages);
            this.#lightBox.start(startIndex, () => {});
        }, { signal: this.#resizeController.signal });
    }

}
