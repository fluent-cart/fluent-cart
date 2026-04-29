class FluentCartProductCarousel {
    static #instance = null;
    static SLIDE_THROTTLE_MS = 350;
    static #hoverZoneControllers = new WeakMap();
    static #autoplayControllers = new WeakMap();

    static init() {
        if (!FluentCartProductCarousel.#instance) {
            FluentCartProductCarousel.#instance = new FluentCartProductCarousel();
        }

        FluentCartProductCarousel.#instance.initCarousels(document);
        return FluentCartProductCarousel.#instance;
    }

    static reInit(root = document) {
        if (!FluentCartProductCarousel.#instance) {
            FluentCartProductCarousel.init();
            return;
        }

        FluentCartProductCarousel.#instance.initCarousels(root);
    }

    /**
     * Cleanup hover zone listeners for a specific carousel
     * @param {HTMLElement} carousel - The carousel element to clean up
     */
    static cleanupHoverZones(carousel) {
        const controller = FluentCartProductCarousel.#hoverZoneControllers.get(carousel);
        if (controller) {
            controller.abort();
            FluentCartProductCarousel.#hoverZoneControllers.delete(carousel);
        }
    }

    /**
     * Add hover-based left/right navigation zones
     * @param {HTMLElement} carousel - The carousel container
     * @param {Object} swiper - Swiper instance
     * @param {Object} settings - Carousel settings object
     */
    static addHoverNavigationZones(carousel, swiper) {
        // Do not enable hover zones if there's only one slide total
        if (!swiper.slides || swiper.slides.length <= 1) return;

        // Skip if already added
        if (carousel.querySelector('.fct-hover-zone')) return;

        // Only for hover-capable devices
        if (!window.matchMedia('(hover: hover)').matches) return;

        // Clean up existing listeners if any
        const existingController = FluentCartProductCarousel.#hoverZoneControllers.get(carousel);
        if (existingController) {
            existingController.abort();
        }

        // Create new AbortController for cleanup
        const controller = new AbortController();
        FluentCartProductCarousel.#hoverZoneControllers.set(carousel, controller);

        const leftZone = document.createElement('div');
        const rightZone = document.createElement('div');

        leftZone.className = 'fct-hover-zone fct-hover-left';
        rightZone.className = 'fct-hover-zone fct-hover-right';

        // Limit zone height to slide area only (exclude pagination)
        const wrapperEl = carousel.querySelector('.swiper-wrapper');
        if (wrapperEl) {
            const h = wrapperEl.offsetHeight + 'px';
            leftZone.style.height = h;
            rightZone.style.height = h;
        }

        let throttle = false;

        const triggerSlide = (direction) => {
            if (throttle) return;
            throttle = true;

            direction === 'prev'
                ? swiper.slidePrev()
                : swiper.slideNext();

            setTimeout(() => {
                throttle = false;
            }, FluentCartProductCarousel.SLIDE_THROTTLE_MS);
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
            } catch (e) { console.warn('FluentCart: Invalid carousel settings', e); }

            const slidesToShow = Math.max(1, Number(settings.slidesToShow || 3));
            const spaceBetween = Number(settings.spaceBetween || 16);

            const nextEl = carousel.querySelector('.swiper-button-next');
            const prevEl = carousel.querySelector('.swiper-button-prev');
            const paginationEl = carousel.querySelector('.swiper-pagination');

            // Backward compatibility (dots → pagination)
            const hasPagination =
                settings.pagination === 'yes' ||
                settings.dots === 'yes';

            // pagination type mapping
            const paginationTypeMap = {
                bullets: 'bullets',
                dots: 'bullets',
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
                          pauseOnMouseEnter: autoplayMode === 'yes',
                      }
                    : false;

            // Build pagination config
            let paginationConfig = false;
            if (hasPagination && paginationEl) {
                const paginationType = paginationTypeMap[settings.paginationType] || 'bullets';

                paginationConfig = {
                    el: paginationEl,
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

            const swiperConfig = {
                slidesPerView: slidesToShow,
                spaceBetween,
                loop: settings.infinite === 'yes',

                grabCursor: true,
                watchOverflow: true,
                observer: true,
                observeParents: true,

                // RTL support
                rtl: document.documentElement.dir === 'rtl',

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
                    settings.arrows === 'yes' && nextEl && prevEl
                        ? {
                              nextEl,
                              prevEl,
                          }
                        : false,

                pagination: paginationConfig,

                breakpoints: {
                    0: { slidesPerView: 1 },
                    640: { slidesPerView: Math.min(2, slidesToShow) },
                    1024: { slidesPerView: Math.min(3, slidesToShow) },
                    1280: { slidesPerView: slidesToShow },
                },
            };

            const swiper = new Swiper(carousel, swiperConfig);

            /**
             * Hover autoplay handling
             * - Start on mouseenter of .swiper-wrapper (slides area)
             * - Stop on mouseleave of outer wrapper (so moving to hover zones doesn't stop)
             */
            if (autoplayMode === 'hover' && swiper.autoplay) {
                // Clean up previous listeners if re-initializing
                const existingController = FluentCartProductCarousel.#autoplayControllers.get(carousel);
                if (existingController) {
                    existingController.abort();
                }

                const controller = new AbortController();
                FluentCartProductCarousel.#autoplayControllers.set(carousel, controller);

                const wrapperEl = carousel.querySelector('.swiper-wrapper');
                const outerWrapper = carousel.closest('.fct-product-carousel-wrapper');

                if (wrapperEl) {
                    wrapperEl.addEventListener('mouseenter', () => {
                        swiper.autoplay.start();
                    }, { signal: controller.signal });
                }

                if (outerWrapper) {
                    outerWrapper.addEventListener('mouseleave', () => {
                        swiper.autoplay.stop();
                    }, { signal: controller.signal });
                }
            }

            /**
             * Hover navigation zones (only for hover autoplay)
             */
            if (autoplayMode === 'hover') {
                FluentCartProductCarousel.addHoverNavigationZones(carousel, swiper);
            }

            carousel.swiper = swiper;
        });
    }
}

/**
 * Expose class globally
 */
window.FluentCartProductCarousel = FluentCartProductCarousel;

/**
 * Frontend load
 */
document.addEventListener('DOMContentLoaded', () => {
    FluentCartProductCarousel.init();
});
