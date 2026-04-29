class FluentCartMediaCarousel {
    static #instance = null;
    static SLIDE_THROTTLE_MS = 350;
    static #hoverZoneControllers = new WeakMap();

    static init() {
        if (!FluentCartMediaCarousel.#instance) {
            FluentCartMediaCarousel.#instance = new FluentCartMediaCarousel();
        }

        FluentCartMediaCarousel.#instance.initCarousels(document);
        return FluentCartMediaCarousel.#instance;
    }

    static reInit(root = document) {
        if (!FluentCartMediaCarousel.#instance) {
            FluentCartMediaCarousel.init();
            return;
        }

        FluentCartMediaCarousel.#instance.initCarousels(root);
    }

    /**
     * Cleanup hover zone listeners for a specific carousel
     * @param {HTMLElement} carousel - The carousel element to clean up
     */
    static cleanupHoverZones(carousel) {
        const controller = FluentCartMediaCarousel.#hoverZoneControllers.get(carousel);
        if (controller) {
            controller.abort();
            FluentCartMediaCarousel.#hoverZoneControllers.delete(carousel);
        }
    }

    /**
     * Add hover-based left/right navigation zones
     * @param {HTMLElement} carousel - The carousel container
     * @param {Object} swiper - Swiper instance
     * @param {Object} settings - Carousel settings object
     */
    static addHoverNavigationZones(carousel, swiper, settings) {
        // Do not enable hover zones if more than 1 slide is visible
        const slidesToShow = Math.max(1, Number(settings.slidesToShow || 1));
        if (slidesToShow > 1) return;

        // Do not enable hover zones if there's only one slide total
        if (!swiper.slides || swiper.slides.length <= 1) return;

        // Skip if already added
        if (carousel.querySelector('.fct-hover-zone')) return;

        // Only for hover-capable devices
        if (!window.matchMedia('(hover: hover)').matches) return;

        // Clean up existing listeners if any
        const existingController = FluentCartMediaCarousel.#hoverZoneControllers.get(carousel);
        if (existingController) {
            existingController.abort();
        }

        // Create new AbortController for cleanup
        const controller = new AbortController();
        FluentCartMediaCarousel.#hoverZoneControllers.set(carousel, controller);

        const leftZone = document.createElement('div');
        const rightZone = document.createElement('div');

        leftZone.className = 'fct-hover-zone fct-hover-left';
        rightZone.className = 'fct-hover-zone fct-hover-right';

        let throttle = false;

        const triggerSlide = (direction) => {
            if (throttle) return;
            throttle = true;

            direction === 'prev'
                ? swiper.slidePrev()
                : swiper.slideNext();

            setTimeout(() => {
                throttle = false;
            }, FluentCartMediaCarousel.SLIDE_THROTTLE_MS);
        };

        leftZone.addEventListener('mouseenter', () => {
            triggerSlide('prev');
        }, { signal: controller.signal });

        rightZone.addEventListener('mouseenter', () => {
            triggerSlide('next');
        }, { signal: controller.signal });

        carousel.appendChild(leftZone);
        carousel.appendChild(rightZone);
    }

    initCarousels(root = document) {
        if (!root || !root.querySelectorAll) return;

        const carousels = root.querySelectorAll(
            '.swiper.fct-product-carousel[data-fluent-cart-product-carousel]'
        );

        if (!carousels.length) return;

        carousels.forEach((carousel) => {

            // Avoid re-init
            if (carousel.swiper) {
                return;
            }

            let settings = {};
            try {
                settings = JSON.parse(
                    carousel.getAttribute('data-carousel-settings') || '{}'
                );
            } catch (e) {}

            const slidesToShow = Math.max(1, Number(settings.slidesToShow || 3));

            // Backward compatibility (dots → pagination)
            const hasPagination =
                settings.pagination === 'yes' ||
                settings.dots === 'yes';

            // pagination type mapping
            const paginationTypeMap = {
                bullets: 'bullets',
                fraction: 'fraction',
                progress: 'progressbar',
                segmented: 'custom',
            };

            const autoplayMode = settings.autoplay || 'no';

            const autoplayConfig =
                autoplayMode === 'yes' || autoplayMode === 'hover'
                    ? {
                          delay: Number(settings.autoplayDelay || 3000),
                          disableOnInteraction: false,
                      }
                    : false;

            // Build pagination config
            let paginationConfig = false;
            if (hasPagination) {
                const paginationType = paginationTypeMap[settings.paginationType] || 'bullets';

                paginationConfig = {
                    el: carousel.querySelector('.swiper-pagination'),
                    clickable: true,
                    type: paginationType,
                };

                // Custom rendering for segmented pagination
                if (settings.paginationType === 'segmented') {
                    paginationConfig.renderCustom = function (_swiper, current, total) {
                        let html = '';
                        for (let i = 1; i <= total; i++) {
                            let className = 'swiper-pagination-segment';
                            if (i < current) {
                                className += ' is-completed';
                            } else if (i === current) {
                                className += ' is-active';
                            }
                            html += `<div class="${className}"></div>`;
                        }
                        // Optional counter
                        html += `<span class="segment-counter">${current} / ${total}</span>`;
                        return html;
                    };
                }
            }

            const swiper = new Swiper(carousel, {
                slidesPerView: slidesToShow,
                spaceBetween: 16,
                loop: settings.infinite === 'yes',
                observer: true,
                observeParents: true,
                grabCursor: true,

                autoplay: autoplayConfig,
                on: {
                    init(swiper) {
                        // Stop autoplay initially if hover mode
                        if (settings.autoplay === 'hover') {
                            swiper.autoplay.stop();
                        }
                    },
                },

                navigation:
                    settings.arrows === 'yes'
                        ? {
                              nextEl: carousel.querySelector('.swiper-button-next'),
                              prevEl: carousel.querySelector('.swiper-button-prev'),
                          }
                        : false,

                pagination: paginationConfig,
            });

            /**
             * Hover autoplay handling
             */
            if (autoplayMode === 'hover' && swiper.autoplay) {
                carousel.addEventListener('mouseenter', () => {
                    swiper.autoplay.start();
                });

                carousel.addEventListener('mouseleave', () => {
                    swiper.autoplay.stop();
                });
            }

            /**
             * Hover navigation zones (left/right)
             */
            FluentCartMediaCarousel.addHoverNavigationZones(carousel, swiper, settings);

            carousel.swiper = swiper;
        });
    }
}

/**
 * expose CLASS globally
 */
window.FluentCartMediaCarousel = FluentCartMediaCarousel;

/**
 * init on first page load
 */
document.addEventListener('DOMContentLoaded', () => {
    FluentCartMediaCarousel.init();
});
