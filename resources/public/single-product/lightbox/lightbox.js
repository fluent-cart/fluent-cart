/*!
 * Lightbox Modified for fluentcart from v2.11.5 - Vanilla JS Version
 * Author: Lokesh Dhakar
 *
 * More info:
 * http://lokeshdhakar.com/projects/lightbox2/
 *
 * Copyright Lokesh Dhakar
 * Released under the MIT license
 * https://github.com/lokesh/lightbox2/blob/master/LICENSE
 *
 * @preserve
 */

class Lightbox {
    static isBuiltOnce = false;

    constructor(options = {}, album = []) {
        this.album = album;
        this.currentImageIndex = void 0;
        this.init();

        this.options = Lightbox.defaults;
        this.option(options);

        // Bound methods for event listeners
        this.boundSizeOverlay = this.sizeOverlay.bind(this);
        this.boundKeyboardAction = this.keyboardAction.bind(this);
    }

    setAlbum(album = []) {
        this.album = album;
        this.currentImageIndex = 0;
    }

    static get defaults() {
        return {
            albumLabel: 'Image %1 of %2',
            alwaysShowNavOnTouchDevices: false,
            fadeDuration: 600,
            fitImagesInViewport: true,
            imageFadeDuration: 600,
            positionFromTop: 50,
            resizeDuration: 700,
            showImageNumberLabel: true,
            wrapAround: false,
            disableScrolling: false,
            sanitizeTitle: false,
            maxHeightPercentage: 80
        };
    }

    get classList() {
        return {
            lightboxOverlay: 'fct-lightboxOverlay',
            lightbox: 'fct-lightbox',
            lbOuterContainer: 'lb-outerContainer',
            lbContainer: 'lb-container',
            lbImage: 'lb-image',
            lbNav: 'lb-nav',
            lbPrev: 'lb-prev',
            lbPrevLink: 'lb-prevLink',
            lbNext: 'lb-next',
            lbNextLink: 'lb-nextLink',
            lbLoader: 'lb-loader',
            lbCancel: 'lb-cancel',
            lbDataContainer: 'lb-dataContainer',
            lbData: 'lb-data',
            lbDetails: 'lb-details',
            lbCaption: 'lb-caption',
            lbNumber: 'lb-number',
            lbNumbers: 'lb-numbers',
            lbCloseContainer: 'lb-closeContainer',
            lbClose: 'lb-close',
            lbDisableScrolling: 'lb-disable-scrolling'
        };
    }

    // Helper methods to replace jQuery functionality
    extend(target, source) {
        for (const key in source) {
            if (source.hasOwnProperty(key)) {
                target[key] = source[key];
            }
        }
    }

    fadeIn(element, duration, callback) {
        element.style.opacity = 0;
        element.style.display = 'flex';

        let start = null;
        const animate = (timestamp) => {
            if (!start) start = timestamp;
            const progress = (timestamp - start) / duration;

            element.style.opacity = Math.min(progress, 1);

            if (progress < 1) {
                requestAnimationFrame(animate);
            } else if (callback) {
                callback();
            }
        };

        requestAnimationFrame(animate);
    }

    fadeOut(element, duration, callback) {
        let start = null;
        const animate = (timestamp) => {
            if (!start) start = timestamp;
            const progress = (timestamp - start) / duration;

            element.style.opacity = Math.max(1 - progress, 0);

            if (progress < 1) {
                requestAnimationFrame(animate);
            } else {
                element.style.display = 'none';
                if (callback) callback();
            }
        };

        requestAnimationFrame(animate);
    }

    animate(element, properties, duration, callback) {
        const startValues = {};
        const endValues = {};

        // Get starting values
        for (const prop in properties) {
            const computedStyle = getComputedStyle(element);
            startValues[prop] = parseFloat(computedStyle[prop]) || 0;
            endValues[prop] = parseFloat(properties[prop]);
        }

        let start = null;
        const animateFrame = (timestamp) => {
            if (!start) start = timestamp;
            const progress = Math.min((timestamp - start) / duration, 1);

            // Easing function (swing equivalent)
            const easeProgress = 0.5 - Math.cos(progress * Math.PI) / 2;

            for (const prop in properties) {
                const value = startValues[prop] + (endValues[prop] - startValues[prop]) * easeProgress;
                element.style[prop] = value + 'px';
            }

            if (progress < 1) {
                requestAnimationFrame(animateFrame);
            } else if (callback) {
                callback();
            }
        };

        requestAnimationFrame(animateFrame);
    }

    option(options) {
        this.extend(this.options, options);
    }

    imageCountLabel(currentImageNum, totalImages) {
        return this.options.albumLabel.replace(/%1/g, currentImageNum).replace(/%2/g, totalImages);
    }

    init() {
        // Initialization logic
    }

    shouldBuild() {
        if (Lightbox.isBuiltOnce === false) {
            Lightbox.isBuiltOnce = true;
            return true;
        }
        return false;
    }

    ensureTemplateExist() {
        const classList = this.classList;
        if (this.shouldBuild()) {
            const template = `<div id="${classList.lightboxOverlay}" tabindex="-1" class="${classList.lightboxOverlay}"></div>
                <div id="${classList.lightbox}" tabindex="-1" class="${classList.lightbox}">
                   <div class="${classList.lbOuterContainer}">
                      <div class="${classList.lbContainer}">
                         <img class="${classList.lbImage}" src="data:image/gif;base64,R0lGODlhAQABAIAAAP///wAAACH5BAEAAAAALAAAAAABAAEAAAICRAEAOw==" alt=""/>
                         <div class="${classList.lbNav}">
                             <div class="${classList.lbCloseContainer}">
                               <a class="${classList.lbClose}" role="button" tabindex="0">
                                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1024 1024"><path fill="currentColor" d="M764.288 214.592 512 466.88 259.712 214.592a31.936 31.936 0 0 0-45.12 45.12L466.752 512 214.528 764.224a31.936 31.936 0 1 0 45.12 45.184L512 557.184l252.288 252.288a31.936 31.936 0 0 0 45.12-45.12L557.12 512.064l252.288-252.352a31.936 31.936 0 1 0-45.12-45.184z"></path></svg>
                                </a>
                            </div>
                            <a class="${classList.lbPrev}" role="button" tabindex="0" aria-label="Previous image" href="" ></a>
                            <a class="${classList.lbNext}" role="button" tabindex="0" aria-label="Next image" href="" ></a>
                         </div>
                         <div class="${classList.lbLoader}">
                            <a class="${classList.lbCancel}" role="button" tabindex="0">
                                <span class="lb-spinner"></span>
                            </a>
                         </div>
                      </div>
                   </div>
                   <div class="${classList.lbDataContainer}">
                      <div class="${classList.lbData}">
                         <div class="${classList.lbDetails}">
                            <span class="${classList.lbCaption}"></span>
                            <span class="${classList.lbNumber}"></span>
                         </div>
                      </div>
                   </div>
                </div>`;
            document.body.insertAdjacentHTML('beforeend', template);
        }
    }

    build() {
        this.ensureTemplateExist();
        // Cache DOM elements
        const classList = this.classList;
        this.lightbox = document.getElementById(classList.lightbox);
        this.overlay = document.getElementById(classList.lightboxOverlay);
        this.outerContainer = this.lightbox.querySelector(`.${classList.lbOuterContainer}`);
        this.container = this.lightbox.querySelector(`.${classList.lbContainer}`);
        this.image = this.lightbox.querySelector(`.${classList.lbImage}`);
        this.nav = this.lightbox.querySelector(`.${classList.lbNav}`);

        // Store css values for future lookup
        const containerStyle = getComputedStyle(this.container);
        this.containerPadding = {
            top: parseInt(containerStyle.paddingTop, 10),
            right: parseInt(containerStyle.paddingRight, 10),
            bottom: parseInt(containerStyle.paddingBottom, 10),
            left: parseInt(containerStyle.paddingLeft, 10)
        };

        const imageStyle = getComputedStyle(this.image);
        this.imageBorderWidth = {
            top: parseInt(imageStyle.borderTopWidth, 10),
            right: parseInt(imageStyle.borderRightWidth, 10),
            bottom: parseInt(imageStyle.borderBottomWidth, 10),
            left: parseInt(imageStyle.borderLeftWidth, 10)
        };

        // Attach event handlers
        this.overlay.style.display = 'none';
        this.overlay.addEventListener('click', () => {
            this.end();
        });

        this.lightbox.style.display = 'none';
        this.lightbox.addEventListener('click', (event) => {
            if (event.target.id === classList.lightbox) {
                this.end();
            }
        });

        this.outerContainer.addEventListener('click', (event) => {
            if (event.target.id === classList.lightbox) {
                this.end();
            }
        });

        // Fix navigation event handling
        this.lightbox.querySelector(`.${classList.lbPrev}`).addEventListener('click', (e) => {
            e.preventDefault();
            if (this.currentImageIndex === 0) {
                this.changeImage(this.album.length - 1);
            } else {
                this.changeImage(this.currentImageIndex - 1);
            }
        });

        this.lightbox.querySelector(`.${classList.lbNext}`).addEventListener('click', (e) => {
            e.preventDefault();
            if (this.currentImageIndex === this.album.length - 1) {
                this.changeImage(0);
            } else {
                this.changeImage(this.currentImageIndex + 1);
            }
        });

        this.nav.addEventListener('mousedown', (event) => {
            if (event.which === 3) {
                this.nav.style.pointerEvents = 'none';

                const contextMenuHandler = () => {
                    setTimeout(() => {
                        this.nav.style.pointerEvents = 'auto';
                    }, 0);
                    this.lightbox.removeEventListener('contextmenu', contextMenuHandler);
                };
                this.lightbox.addEventListener('contextmenu', contextMenuHandler);
            }
        });

        const closeElements = this.lightbox.querySelectorAll(`.${classList.lbLoader}, .${classList.lbClose}`);
        closeElements.forEach(element => {
            element.addEventListener('click', (e) => {
                this.end();
                return false;
            });
            element.addEventListener('keyup', (e) => {
                if (e.which === 13 || e.which === 32) {
                    this.end();
                    return false;
                }
            });
        });
    }

    start(number = 0, onStarted = null) {
        this.build();

        // Fix resize event binding
        window.addEventListener('resize', this.boundSizeOverlay);
        this.sizeOverlay();

        // Position Lightbox
        const top = window.pageYOffset + this.options.positionFromTop;
        const left = window.pageXOffset;

        this.lightbox.style.top = top + 'px';
        this.lightbox.style.left = left + 'px';
        this.fadeIn(this.lightbox, this.options.fadeDuration);

        document.body.style.overflow = 'hidden';

        // Disable scrolling of the page while open
        if (this.options.disableScrolling) {
            document.body.classList.add(this.classList.lbDisableScrolling);
        }

        this.changeImage(number);

        if (typeof onStarted === 'function') {
            onStarted();
        }
    }

    changeImage(imageNumber) {
        const classList = this.classList;
        const self = this;
        const filename = this.album[imageNumber].link;
        const filetype = filename.split('.').slice(-1)[0];

        // Disable keyboard nav during transitions
        this.disableKeyboardNav();

        // Show loading state
        this.fadeIn(this.overlay, this.options.fadeDuration);

        const loader = this.lightbox.querySelector(`.${classList.lbLoader}`);
        this.fadeIn(loader, 500); // 'slow' equivalent

        const elementsToHide = this.lightbox.querySelectorAll(`.${classList.lbImage}, .${classList.lbNav}, .${classList.lbPrev}, .${classList.lbNext}, .${classList.lbDataContainer}, .${classList.lbNumbers}, .${classList.lbCaption}`);
        elementsToHide.forEach(el => el.style.display = 'none');

        this.outerContainer.classList.add('animating');

        // When the image to show is preloaded, we send the width and height to sizeContainer()
        const preloader = new Image();
        preloader.onload = () => {
            setTimeout(() => {
                this.onImageLoaded(preloader, imageNumber, self, filename, filetype);
            }, 300);
        };
        preloader.src = this.album[imageNumber].link;
        this.currentImageIndex = imageNumber;
    }

    onImageLoaded(preloader, imageNumber, self, filename, filetype) {
        let imageHeight;
        let imageWidth;
        let maxImageHeight;
        let maxImageWidth;
        let windowHeight;
        let windowWidth;

        const image = this.lightbox.querySelector(`.${this.classList.lbImage}`);

        image.setAttribute('alt', self.album[imageNumber].alt);
        image.setAttribute('src', filename);

        image.style.width = preloader.width + 'px';
        image.style.height = preloader.height + 'px';

        const aspectRatio = preloader.width / preloader.height;

        windowWidth = window.innerWidth || document.documentElement.clientWidth || document.body.clientWidth;
        windowHeight = window.innerHeight || document.documentElement.clientHeight || document.body.clientHeight;

        // Using the maxHeightPercentage to calculate the height limit
        const maxHeightPercentage = this.options.maxHeightPercentage || 80;
        const viewportHeightLimit = (windowHeight * maxHeightPercentage) / 100;

        // Calculate the max image dimensions for the current viewport
        maxImageWidth = windowWidth - self.containerPadding.left - self.containerPadding.right - self.imageBorderWidth.left - self.imageBorderWidth.right - 20;

        maxImageHeight = Math.min(
            viewportHeightLimit - self.containerPadding.top - self.containerPadding.bottom - self.imageBorderWidth.top - self.imageBorderWidth.bottom,
            windowHeight - self.containerPadding.top - self.containerPadding.bottom - self.imageBorderWidth.top - self.imageBorderWidth.bottom - self.options.positionFromTop - 70
        );

        // Handle SVG files
        if (filetype === 'svg') {
            if (aspectRatio >= 1) {
                imageWidth = maxImageWidth;
                imageHeight = parseInt(maxImageWidth / aspectRatio, 10);
            } else {
                imageWidth = parseInt(maxImageHeight / aspectRatio, 10);
                imageHeight = maxImageHeight;
            }

            if (imageHeight > maxImageHeight) {
                imageHeight = maxImageHeight;
                imageWidth = parseInt(maxImageHeight * aspectRatio, 10);
            }

            image.style.width = imageWidth + 'px';
            image.style.height = imageHeight + 'px';
        } else {
            // Fit image inside the viewport
            if (self.options.fitImagesInViewport) {
                if (self.options.maxWidth && self.options.maxWidth < maxImageWidth) {
                    maxImageWidth = self.options.maxWidth;
                }
                if (self.options.maxHeight && self.options.maxHeight < maxImageHeight) {
                    maxImageHeight = self.options.maxHeight;
                }
            } else {
                maxImageWidth = self.options.maxWidth || preloader.width || maxImageWidth;
                maxImageHeight = self.options.maxHeight || preloader.height || maxImageHeight;
            }

            if ((preloader.width > maxImageWidth) || (preloader.height > maxImageHeight)) {
                if ((preloader.width / maxImageWidth) > (preloader.height / maxImageHeight)) {
                    imageWidth = maxImageWidth;
                    imageHeight = parseInt(preloader.height / (preloader.width / imageWidth), 10);

                    if (imageHeight > maxImageHeight) {
                        imageHeight = maxImageHeight;
                        imageWidth = parseInt(preloader.width / (preloader.height / imageHeight), 10);
                    }
                } else {
                    imageHeight = maxImageHeight;
                    imageWidth = parseInt(preloader.width / (preloader.height / imageHeight), 10);

                    if (imageWidth > maxImageWidth) {
                        imageWidth = maxImageWidth;
                        imageHeight = parseInt(preloader.height / (preloader.width / imageWidth), 10);
                    }
                }

                image.style.width = imageWidth + 'px';
                image.style.height = imageHeight + 'px';
            }
        }

        // Ensure the image container doesn't exceed the viewport height
        if (imageHeight + self.containerPadding.top + self.containerPadding.bottom + self.imageBorderWidth.top +
            self.imageBorderWidth.bottom + self.options.positionFromTop + 70 > windowHeight) {

            const availableHeight = windowHeight - self.containerPadding.top - self.containerPadding.bottom -
                self.imageBorderWidth.top - self.imageBorderWidth.bottom - self.options.positionFromTop - 70;

            const scaleFactor = availableHeight / imageHeight;
            imageHeight = availableHeight;
            imageWidth = Math.floor(imageWidth * scaleFactor);

            image.style.width = imageWidth + 'px';
            image.style.height = imageHeight + 'px';
        }

        self.sizeContainer(parseInt(image.style.width), parseInt(image.style.height));
    }

    sizeOverlay() {
        setTimeout(() => {
            this.overlay.style.width = Math.max(document.body.scrollWidth, document.body.offsetWidth,
                document.documentElement.clientWidth, document.documentElement.scrollWidth,
                document.documentElement.offsetWidth) + 'px';
            this.overlay.style.height = window.innerHeight + 'px';
        }, 0);
    }

    sizeContainer(imageWidth, imageHeight) {
        const oldWidth = this.outerContainer.offsetWidth;
        const oldHeight = this.outerContainer.offsetHeight;
        const newWidth = imageWidth + this.containerPadding.left + this.containerPadding.right + this.imageBorderWidth.left + this.imageBorderWidth.right;
        const newHeight = imageHeight + this.containerPadding.top + this.containerPadding.bottom + this.imageBorderWidth.top + this.imageBorderWidth.bottom;

        const postResize = () => {
            this.lightbox.querySelector(`.${this.classList.lbDataContainer}`).style.width = newWidth + 'px';

            const prevLink = this.lightbox.querySelector(`.${this.classList.lbPrevLink}`);
            const nextLink = this.lightbox.querySelector(`.${this.classList.lbNextLink}`);
            if (prevLink) prevLink.style.height = newHeight + 'px';
            if (nextLink) nextLink.style.height = newHeight + 'px';

            this.overlay.focus();
            this.showImage();
        };

        if (oldWidth !== newWidth || oldHeight !== newHeight) {
            this.animate(this.outerContainer, {
                width: newWidth,
                height: newHeight
            }, this.options.resizeDuration, postResize);
        } else {
            postResize();
        }
    }

    showImage() {
        const loader = this.lightbox.querySelector(`.${this.classList.lbLoader}`);
        loader.style.display = 'none';

        this.fadeIn(this.lightbox.querySelector(`.${this.classList.lbImage}`), this.options.imageFadeDuration);

        this.updateNav();
        this.updateDetails();
        this.preloadNeighboringImages();
        this.enableKeyboardNav();
    }

    updateNav() {
        let alwaysShowNav = false;
        try {
            document.createEvent('TouchEvent');
            alwaysShowNav = this.options.alwaysShowNavOnTouchDevices ? true : false;
        } catch (e) {
            // Touch events not supported
        }

        this.lightbox.querySelector(`.${this.classList.lbNav}`).style.display = 'block';

        if (this.album.length > 1) {
            if (this.options.wrapAround) {
                if (alwaysShowNav) {
                    this.lightbox.querySelector(`.${this.classList.lbPrev}`).style.opacity = '1';
                }
                this.lightbox.querySelector(`.${this.classList.lbPrev}`).style.display = 'block';
                this.lightbox.querySelector(`.${this.classList.lbNext}`).style.display = 'block';
            } else {
                if (this.currentImageIndex > 0) {
                    const prevBtn = this.lightbox.querySelector(`.${this.classList.lbPrev}`);
                    prevBtn.style.display = 'block';
                    if (alwaysShowNav) {
                        prevBtn.style.opacity = '1';
                    }
                }
                if (this.currentImageIndex < this.album.length - 1) {
                    const nextBtn = this.lightbox.querySelector(`.${this.classList.lbNext}`);
                    nextBtn.style.display = 'block';
                    if (alwaysShowNav) {
                        nextBtn.style.opacity = '1';
                    }
                }
            }
        }
    }

    updateDetails() {
        if (typeof this.album[this.currentImageIndex].title !== 'undefined' &&
            this.album[this.currentImageIndex].title !== '') {
            const caption = this.lightbox.querySelector('.lb-caption');
            if (this.options.sanitizeTitle) {
                caption.textContent = this.album[this.currentImageIndex].title;
            } else {
                caption.innerHTML = this.album[this.currentImageIndex].title;
            }
            this.fadeIn(caption, 300); // 'fast' equivalent
        }

        if (this.album.length > 1 && this.options.showImageNumberLabel) {
            const labelText = this.imageCountLabel(this.currentImageIndex + 1, this.album.length);
            const numberElement = this.lightbox.querySelector(`.${this.classList.lbNumber}`);
            numberElement.textContent = labelText;
            this.fadeIn(numberElement, 300);
        } else {
            this.lightbox.querySelector(`.${this.classList.lbNumber}`).style.display = 'none';
        }

        this.outerContainer.classList.remove('animating');

        this.fadeIn(this.lightbox.querySelector(`.${this.classList.lbDataContainer}`), this.options.resizeDuration, () => {
            return this.sizeOverlay();
        });
    }

    preloadNeighboringImages() {
        if (this.album.length > this.currentImageIndex + 1) {
            const preloadNext = new Image();
            preloadNext.src = this.album[this.currentImageIndex + 1].link;
        }
        if (this.currentImageIndex > 0) {
            const preloadPrev = new Image();
            preloadPrev.src = this.album[this.currentImageIndex - 1].link;
        }
    }

    enableKeyboardNav() {
        document.addEventListener('keyup', this.boundKeyboardAction);
        this.lightbox.addEventListener('keyup', this.boundKeyboardAction);
        this.overlay.addEventListener('keyup', this.boundKeyboardAction);
    }

    disableKeyboardNav() {
        document.removeEventListener('keyup', this.boundKeyboardAction);
        this.lightbox.removeEventListener('keyup', this.boundKeyboardAction);
        this.overlay.removeEventListener('keyup', this.boundKeyboardAction);
    }

    keyboardAction(event) {
        const KEYCODE_ESC = 27;
        const KEYCODE_LEFTARROW = 37;
        const KEYCODE_RIGHTARROW = 39;
        const keycode = event.keyCode;

        if (keycode === KEYCODE_ESC) {
            event.stopPropagation();
            this.end();
        } else if (keycode === KEYCODE_LEFTARROW) {
            if (this.currentImageIndex !== 0) {
                this.changeImage(this.currentImageIndex - 1);
            } else if (this.options.wrapAround && this.album.length > 1) {
                this.changeImage(this.album.length - 1);
            }
        } else if (keycode === KEYCODE_RIGHTARROW) {
            if (this.currentImageIndex !== this.album.length - 1) {
                this.changeImage(this.currentImageIndex + 1);
            } else if (this.options.wrapAround && this.album.length > 1) {
                this.changeImage(0);
            }
        }
    }

    end() {
        this.disableKeyboardNav();
        window.removeEventListener('resize', this.boundSizeOverlay);
        this.fadeOut(this.lightbox, this.options.fadeDuration);
        this.fadeOut(this.overlay, this.options.fadeDuration);
        document.body.style.overflow = 'inherit';

        if (this.options.disableScrolling) {
            document.body.classList.remove(this.classList.lbDisableScrolling);
        }

        setTimeout(() => {
            const lightboxEl = document.getElementById('lightbox');
            const overlayEl = document.getElementById('lightboxOverlay');
            if (lightboxEl) lightboxEl.remove();
            if (overlayEl) overlayEl.remove();
        }, this.options.fadeDuration);
    }
}

// Export the Lightbox class
export default Lightbox;
