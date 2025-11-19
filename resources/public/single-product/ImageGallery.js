import Lightbox from "./lightbox/lightbox";

window.addEventListener("fluent_cart_app_loaded", function (event) {
    document.querySelectorAll('[data-fluent-cart-product-gallery-wrapper]').forEach((container, index) => {
        new window.FluentCartImageGallery().init(container);
    });
});

window.addEventListener('fluentCartSingleProductModalOpened' , function (event) {
    let enableZoom = false;
    if (window.fluentcart_single_product_vars.enable_image_zoom_in_modal === 'yes') {
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

    init(container, enableZoom = true) {
        this.container = container;
        this.#productId = this.container.getAttribute('data-product-id');

        // Get thumbnail mode from container attribute
        this.#thumbnailMode = this.container.getAttribute('data-thumbnail-mode') || 'all';

        this.#enableZoom = enableZoom;
        this.#thumbnailControls = this.findInContainer('[data-fluent-cart-thumb-control-button]');
        this.#thumbnailControlsWrapper = this.findOneInContainer('[data-fluent-cart-single-product-page-product-thumbnail-controls]');
        this.#imgContainer = this.findOneInContainer('[data-fluent-cart-single-product-page-product-thumbnail]');

        this.#listenForVariationChange();

        this.#setup();
        this.#initImageZoom();
        this.#initImageGallery();

        this.#prepareLightboxImages();
        this.#setupThumbnailControls();
    }

    #listenForVariationChange() {
        window.addEventListener("fluentCartSingleProductVariationChanged", (event) => {
            const productId = event.detail.productId;
            if (productId != this.#productId) {
                return;
            }
            this.#currentlySelectedVariationId = event.detail.variationId;
            this.updateGalleryByVariation(this.#currentlySelectedVariationId)

            let controlButtons = this.#thumbnailControlsWrapper?.querySelectorAll(`[data-fluent-cart-thumb-control-button][data-variation-id="${this.#currentlySelectedVariationId}"]`);
            this.#setupControlWrapper(controlButtons);
        });
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
        this.#imgContainer.removeEventListener('click', this.#openLightBox.bind(this));
        this.#lightBox = new Lightbox({}, []);
        this.#imgContainer.addEventListener('click', this.#openLightBox.bind(this));
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
            // Show all images from all variations
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
        const addedSrcs = new Set();

        // Helper to add unique images
        const addUniqueImages = (images) => {
            if (Array.isArray(images)) {
                images.forEach(img => {
                    if (!addedSrcs.has(img.link)) {
                        allImages.push(img);
                        addedSrcs.add(img.link);
                    }
                });
            }
        };

        // First, add current variation images
        if (this.#currentlySelectedVariationId && this.#lightBoxImages[this.#currentlySelectedVariationId]) {
            addUniqueImages(this.#lightBoxImages[this.#currentlySelectedVariationId]);
        }

        // Then add default/variation 0 images
        if (this.#lightBoxImages[0]) {
            addUniqueImages(this.#lightBoxImages[0]);
        }

        // Finally, add all other variation images
        Object.keys(this.#lightBoxImages).forEach(variationId => {
            if (variationId != this.#currentlySelectedVariationId && variationId != 0) {
                addUniqueImages(this.#lightBoxImages[variationId]);
            }
        });

        return allImages.length > 0 ? allImages : this.#lightBoxImages[0] || [];
    }

    updateGalleryByVariation(variationId = 0) {
        // If thumbnail mode is 'all', show all thumbnails
        if (this.#thumbnailMode === 'all') {
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
            control.classList.add('active');
            this.#setThumbImage(control);
        }
    }

    #setupThumbnailControls() {
        this.#thumbnailControls.forEach(control => {
            control.addEventListener('click', (event) => {
                this.#handleThumbnailChange(control);
            });
        });
    }

    #handleThumbnailChange(control) {
        this.#thumbnailControls.forEach(ctrl => ctrl.classList.remove('active'));
        control.classList.add('active');

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

}
