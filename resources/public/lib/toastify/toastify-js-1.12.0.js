/*!
 * Toastify js 1.12.0 (Class Implementation with slideFrom option)
 * https://github.com/apvarun/toastify-js
 * @license MIT licensed
 *
 * Copyright (C) 2018 Varun A P
 * Converted to class-based implementation
 */
(function (root, factory) {
    if (typeof module === "object" && module.exports) {
        module.exports = factory();
    } else {
        root.Toastify = factory();
    }
})(this, function (global) {
    // Default global options
    const DEFAULT_OPTIONS = {
        oldestFirst: true,
        text: "Toastify is awesome!",
        node: undefined,
        duration: 3000,
        selector: undefined,
        callback: function () {
        },
        destination: undefined,
        newWindow: false,
        close: false,
        gravity: "toastify-top",
        positionLeft: false,
        position: '',
        backgroundColor: '',
        avatar: "",
        className: "",
        stopOnFocus: true,
        onClick: function () {
        },
        offset: {x: 0, y: 0},
        escapeMarkup: true,
        ariaLive: 'polite',
        style: {background: ''},
        slideFrom: '',
        type: ''
    };

    class Toastify {
        constructor(options = {}) {
            // Creating the options object
            this.options = {};
            this.toastElement = null;
            this.version = "1.12.0";

            // Validating the options
            this.options.text = options.text || DEFAULT_OPTIONS.text;
            this.options.node = options.node || DEFAULT_OPTIONS.node;
            this.options.duration = options.duration === 0 ? 0 : options.duration || DEFAULT_OPTIONS.duration;
            this.options.selector = options.selector || DEFAULT_OPTIONS.selector;
            this.options.callback = options.callback || DEFAULT_OPTIONS.callback;
            this.options.destination = options.destination || DEFAULT_OPTIONS.destination;
            this.options.newWindow = options.newWindow || DEFAULT_OPTIONS.newWindow;
            this.options.close = options.close || DEFAULT_OPTIONS.close;
            this.options.gravity = options.gravity === "bottom" ? "toastify-bottom" : DEFAULT_OPTIONS.gravity;
            this.options.positionLeft = options.positionLeft || DEFAULT_OPTIONS.positionLeft;
            this.options.position = options.position || DEFAULT_OPTIONS.position;
            this.options.backgroundColor = options.backgroundColor || DEFAULT_OPTIONS.backgroundColor;
            this.options.avatar = options.avatar || DEFAULT_OPTIONS.avatar;
            this.options.className = options.className || DEFAULT_OPTIONS.className;
            this.options.stopOnFocus = options.stopOnFocus === undefined ? DEFAULT_OPTIONS.stopOnFocus : options.stopOnFocus;
            this.options.onClick = options.onClick || DEFAULT_OPTIONS.onClick;
            this.options.offset = options.offset || DEFAULT_OPTIONS.offset;
            this.options.escapeMarkup = options.escapeMarkup !== undefined ? options.escapeMarkup : DEFAULT_OPTIONS.escapeMarkup;
            this.options.ariaLive = options.ariaLive || DEFAULT_OPTIONS.ariaLive;
            this.options.style = options.style || Object.assign({}, DEFAULT_OPTIONS.style);
            this.options.slideFrom = options.slideFrom || DEFAULT_OPTIONS.slideFrom;
            this.options.type = options.type || null;

            if (options.backgroundColor) {
                this.options.style.background = options.backgroundColor;
            }
        }

        buildToast() {
            // Validating if the options are defined
            if (!this.options) {
                throw "Toastify is not initialized";
            }

            // Creating the DOM object
            const divElement = document.createElement("div");
            divElement.className = "toastify " + this.options.className;

            // Positioning toast to left or right or center
            if (!!this.options.position) {
                divElement.className += " toastify-" + this.options.position;
            } else {
                // To be depreciated in further versions
                if (this.options.positionLeft === true) {
                    divElement.className += " toastify-left";
                    console.warn('Property `positionLeft` will be depreciated in further versions. Please use `position` instead.');
                } else {
                    // Default position
                    divElement.className += " toastify-right";
                }
            }

            // Assigning gravity of element
            divElement.className += " " + this.options.gravity;

            if (this.options.backgroundColor) {
                // This is being deprecated in favor of using the style HTML DOM property
                console.warn('DEPRECATION NOTICE: "backgroundColor" is being deprecated. Please use the "style.background" property.');
            }

            // Loop through our style object and apply styles to divElement
            for (const property in this.options.style) {
                divElement.style[property] = this.options.style[property];
            }

            // Announce the toast to screen readers
            if (this.options.ariaLive) {
                divElement.setAttribute('aria-live', this.options.ariaLive);
            }

            // Adding the toast message/node
            if (this.options.node && this.options.node.nodeType === Node.ELEMENT_NODE) {
                // If we have a valid node, we insert it
                divElement.appendChild(this.options.node);
            } else {
                if (this.options.escapeMarkup) {
                    divElement.innerText = this.options.text;
                } else {
                    divElement.innerHTML = this.options.text;
                }

                const successIcon = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1024 1024"><path fill="green" d="M512 64a448 448 0 1 1 0 896 448 448 0 0 1 0-896m-55.808 536.384-99.52-99.584a38.4 38.4 0 1 0-54.336 54.336l126.72 126.72a38.272 38.272 0 0 0 54.336 0l262.4-262.464a38.4 38.4 0 1 0-54.272-54.336z"></path></svg>';
                const errorIcon = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1024 1024"><path fill="currentColor" d="M512 64a448 448 0 1 1 0 896 448 448 0 0 1 0-896m0 393.664L407.936 353.6a38.4 38.4 0 1 0-54.336 54.336L457.664 512 353.6 616.064a38.4 38.4 0 1 0 54.336 54.336L512 566.336 616.064 670.4a38.4 38.4 0 1 0 54.336-54.336L566.336 512 670.4 407.936a38.4 38.4 0 1 0-54.336-54.336z"></path></svg>';
                const infoIcon = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1024 1024"><path fill="currentColor" d="M512 64a448 448 0 1 1 0 896.064A448 448 0 0 1 512 64m67.2 275.072c33.28 0 60.288-23.104 60.288-57.344s-27.072-57.344-60.288-57.344c-33.28 0-60.16 23.104-60.16 57.344s26.88 57.344 60.16 57.344M590.912 699.2c0-6.848 2.368-24.64 1.024-34.752l-52.608 60.544c-10.88 11.456-24.512 19.392-30.912 17.28a12.992 12.992 0 0 1-8.256-14.72l87.68-276.992c7.168-35.136-12.544-67.2-54.336-71.296-44.096 0-108.992 44.736-148.48 101.504 0 6.784-1.28 23.68.064 33.792l52.544-60.608c10.88-11.328 23.552-19.328 29.952-17.152a12.8 12.8 0 0 1 7.808 16.128L388.48 728.576c-10.048 32.256 8.96 63.872 55.04 71.04 67.84 0 107.904-43.648 147.456-100.416z"></path></svg>';
                const warningIcon = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1024 1024"><path fill="currentColor" d="M512 64a448 448 0 1 1 0 896 448 448 0 0 1 0-896m0 192a58.432 58.432 0 0 0-58.24 63.744l23.36 256.384a35.072 35.072 0 0 0 69.76 0l23.296-256.384A58.432 58.432 0 0 0 512 256m0 512a51.2 51.2 0 1 0 0-102.4 51.2 51.2 0 0 0 0 102.4"></path></svg>';

                if (this.options.type) {


                    const icon = document.createElement('div');
                    icon.classList.add('toastify-icon');
                    if (this.options.type === 'success') {
                        icon.innerHTML = successIcon.trim();
                        icon.classList.add('success');
                    } else if (this.options.type === 'error') {
                        icon.innerHTML = errorIcon.trim();
                        icon.classList.add('error');
                    } else if (this.options.type === 'info') {
                        icon.innerHTML = infoIcon.trim();
                        icon.classList.add('info');
                    } else if (this.options.type === 'warning') {
                        icon.innerHTML = warningIcon.trim();
                        icon.classList.add('warning');
                    }

                    divElement.classList.add('has-toastify-icon');

                    if (this.options.position === "left" || this.options.positionLeft === true) {
                        // Adding close icon on the left of content
                        divElement.appendChild(icon);

                    } else {
                        // Adding close icon on the right of content
                        divElement.insertAdjacentElement("afterbegin", icon);
                    }
                }

                if (this.options.avatar !== "" && !this.options.type) {
                    const avatarElement = document.createElement("img");
                    avatarElement.src = this.options.avatar;
                    avatarElement.className = "toastify-avatar";

                    if (this.options.position == "left" || this.options.positionLeft === true) {
                        // Adding close icon on the left of content
                        divElement.appendChild(avatarElement);
                    } else {
                        // Adding close icon on the right of content
                        divElement.insertAdjacentElement("afterbegin", avatarElement);
                    }
                }
            }

            // Adding a close icon to the toast
            if (this.options.close === true) {
                // Create a span for close element
                const closeElement = document.createElement("button");
                closeElement.type = "button";
                closeElement.setAttribute("aria-label", "Close");
                closeElement.className = "toast-close";
                closeElement.innerHTML = "&#10006;";

                // Triggering the removal of toast from DOM on close click
                closeElement.addEventListener(
                    "click",
                    (event) => {
                        event.stopPropagation();
                        this.removeElement(this.toastElement);
                        window.clearTimeout(this.toastElement.timeOutValue);
                    }
                );

                //Calculating screen width
                const width = window.innerWidth > 0 ? window.innerWidth : screen.width;

                // Adding the close icon to the toast element
                // Display on the right if screen width is less than or equal to 360px
                if ((this.options.position == "left" || this.options.positionLeft === true) && width > 360) {
                    // Adding close icon on the left of content
                    divElement.insertAdjacentElement("afterbegin", closeElement);
                } else {
                    // Adding close icon on the right of content
                    divElement.appendChild(closeElement);
                }
            }

            // Clear timeout while toast is focused
            if (this.options.stopOnFocus && this.options.duration > 0) {
                // stop countdown
                divElement.addEventListener(
                    "mouseover",
                    () => {
                        window.clearTimeout(divElement.timeOutValue);
                    }
                );

                // add back the timeout
                divElement.addEventListener(
                    "mouseleave",
                    () => {
                        divElement.timeOutValue = window.setTimeout(
                            () => {
                                // Remove the toast from DOM
                                this.removeElement(divElement);
                            },
                            this.options.duration
                        );
                    }
                );
            }

            // Adding an on-click destination path
            if (typeof this.options.destination !== "undefined") {
                divElement.addEventListener(
                    "click",
                    (event) => {
                        event.stopPropagation();
                        if (this.options.newWindow === true) {
                            window.open(this.options.destination, "_blank");
                        } else {
                            window.location = this.options.destination;
                        }
                    }
                );
            }

            if (typeof this.options.onClick === "function" && typeof this.options.destination === "undefined") {
                divElement.addEventListener(
                    "click",
                    (event) => {
                        event.stopPropagation();
                        this.options.onClick();
                    }
                );
            }

            // Adding offset
            if (typeof this.options.offset === "object") {
                const x = Toastify.getAxisOffsetAValue("x", this.options);
                const y = Toastify.getAxisOffsetAValue("y", this.options);

                const xOffset = this.options.position == "left" ? x : "-" + x;
                const yOffset = this.options.gravity == "toastify-top" ? y : "-" + y;

                divElement.style.transform = "translate(" + xOffset + "," + yOffset + ")";
            }

            return divElement;
        }

        // Get initial position for slide animation
        getSlidePosition() {
            if (!this.options.slideFrom) return null;

            const width = window.innerWidth;
            const height = window.innerHeight;

            switch (this.options.slideFrom.toLowerCase()) {
                case 'left':
                    return {transform: `translateX(-${width}px)`};
                case 'right':
                    return {transform: `translateX(${width}px)`};
                case 'top':
                    return {transform: `translateY(-${height}px)`};
                case 'bottom':
                    return {transform: `translateY(${height}px)`};
                default:
                    return null;
            }
        }

        // Get final position considering offsets
        getFinalPosition() {
            let transform = "";

            if (typeof this.options.offset === "object") {
                const x = Toastify.getAxisOffsetAValue("x", this.options);
                const y = Toastify.getAxisOffsetAValue("y", this.options);

                const xOffset = this.options.position == "left" ? x : "-" + x;
                const yOffset = this.options.gravity == "toastify-top" ? y : "-" + y;

                transform = `translate(${xOffset}, ${yOffset})`;
            }

            return {transform};
        }

        showToast() {
            // Creating the DOM object for the toast
            this.toastElement = this.buildToast();

            // Apply slide animation if needed
            const initialPosition = this.getSlidePosition();
            if (initialPosition) {
                // Apply initial position (off-screen)
                Object.assign(this.toastElement.style, initialPosition);
            }

            // Getting the root element to with the toast needs to be added
            let rootElement;
            if (typeof this.options.selector === "string") {
                rootElement = document.getElementById(this.options.selector);
            } else if (this.options.selector instanceof HTMLElement || (typeof ShadowRoot !== 'undefined' && this.options.selector instanceof ShadowRoot)) {
                rootElement = this.options.selector;
            } else {
                rootElement = document.body;
            }

            // Validating if root element is present in DOM
            if (!rootElement) {
                throw "Root element is not defined";
            }

            // Adding the DOM element
            const elementToInsert = DEFAULT_OPTIONS.oldestFirst ? rootElement.firstChild : rootElement.lastChild;
            rootElement.insertBefore(this.toastElement, elementToInsert);
            // Force reflow
            void this.toastElement.offsetWidth;

            // Add the "on" class for default fade-in
            if (!this.options.slideFrom) {
                this.toastElement.className += " on";
            }

            // If using slideFrom, animate to final position
            if (initialPosition) {
                // Make sure transition is applied
                this.toastElement.style.transition = "transform 0.4s cubic-bezier(0.215, 0.61, 0.355, 1), opacity 0.4s cubic-bezier(0.215, 0.61, 0.355, 1)";

                // Force browser to recognize the element before animation
                setTimeout(() => {
                    const finalPosition = this.getFinalPosition();
                    Object.assign(this.toastElement.style, finalPosition);
                    this.toastElement.className += " on";
                }, 20);
            }

            // Repositioning the toasts in case multiple toasts are present
            Toastify.reposition();

            if (this.options.duration > 0) {
                this.toastElement.timeOutValue = window.setTimeout(
                    () => {
                        // Remove the toast from DOM
                        this.removeElement(this.toastElement);
                    },
                    this.options.duration
                );
            }

            // Supporting function chaining
            return this;
        }

        hideToast() {
            if (this.toastElement.timeOutValue) {
                clearTimeout(this.toastElement.timeOutValue);
            }
            this.removeElement(this.toastElement);
        }

        removeElement(toastElement) {
            // If using slideFrom, animate out in that direction
            if (this.options.slideFrom) {
                const width = window.innerWidth;
                const height = window.innerHeight;

                // Ensure transition is still applied
                toastElement.style.transition = "transform 0.4s cubic-bezier(0.215, 0.61, 0.355, 1), opacity 0.4s cubic-bezier(0.215, 0.61, 0.355, 1)";

                // Set exit transform based on slide direction
                switch (this.options.slideFrom.toLowerCase()) {
                    case 'left':
                        toastElement.style.transform = `translateX(-${width}px)`;
                        break;
                    case 'right':
                        toastElement.style.transform = `translateX(${width}px)`;
                        break;
                    case 'top':
                        toastElement.style.transform = `translateY(-${height}px)`;
                        break;
                    case 'bottom':
                        toastElement.style.transform = `translateY(${height}px)`;
                        break;
                }

                // Also reduce opacity
                toastElement.style.opacity = '0';
            } else {
                // Standard removal - just remove "on" class
                toastElement.className = toastElement.className.replace(" on", "");
            }

            // Removing the element from DOM after transition end
            window.setTimeout(
                () => {
                    // remove options node if any
                    if (this.options.node && this.options.node.parentNode) {
                        this.options.node.parentNode.removeChild(this.options.node);
                    }

                    // Remove the element from the DOM, only when the parent node was not removed before.
                    if (toastElement.parentNode) {
                        toastElement.parentNode.removeChild(toastElement);
                    }

                    // Calling the callback function
                    this.options.callback.call(toastElement);

                    // Repositioning the toasts again
                    Toastify.reposition();
                },
                400
            );
        }

        // Static helper method to get offset
        static getAxisOffsetAValue(axis, options) {
            if (options.offset[axis]) {
                if (isNaN(options.offset[axis])) {
                    return options.offset[axis];
                } else {
                    return options.offset[axis] + 'px';
                }
            }
            return '0px';
        }

        // Static method to check if an element contains a class
        static containsClass(elem, yourClass) {
            if (!elem || typeof yourClass !== "string") {
                return false;
            } else if (
                elem.className &&
                elem.className
                    .trim()
                    .split(/\s+/gi)
                    .indexOf(yourClass) > -1
            ) {
                return true;
            } else {
                return false;
            }
        }

        // Static method to reposition all toasts
        static reposition() {
            // Top and bottom margins
            const topLeftOffsetSize = {
                top: 15,
                bottom: 15,
            };
            const topRightOffsetSize = {
                top: 15,
                bottom: 15,
            };
            const offsetSize = {
                top: 15,
                bottom: 15,
            };

            // Get all toast messages on the DOM
            const allToasts = Array.from(document.getElementsByClassName("toastify"));

            // Reverse the order for bottom stacking when oldestFirst is false


            if (Toastify.defaults.oldestFirst) {
                allToasts.reverse();
            }

            let classUsed;

            // Modifying the position of each toast element
            for (let i = 0; i < allToasts.length; i++) {
                // Getting the applied gravity
                if (Toastify.containsClass(allToasts[i], "toastify-top") === true) {
                    classUsed = "toastify-top";
                } else {
                    classUsed = "toastify-bottom";
                }

                const height = allToasts[i].offsetHeight;
                classUsed = classUsed.substr(9, classUsed.length - 1); // Get 'top' or 'bottom'
                // Spacing between toasts
                const offset = 15;

                const width = window.innerWidth > 0 ? window.innerWidth : screen.width;

                // Show toast in center if screen width is less than or equal to 360px
                if (width <= 360) {
                    // Setting the position
                    allToasts[i].style[classUsed] = offsetSize[classUsed] + "px";
                    offsetSize[classUsed] += height + offset;
                } else {
                    if (Toastify.containsClass(allToasts[i], "toastify-left") === true) {
                        // Setting the position
                        allToasts[i].style[classUsed] = topLeftOffsetSize[classUsed] + "px";
                        topLeftOffsetSize[classUsed] += height + offset;
                    } else {
                        // Setting the position
                        allToasts[i].style[classUsed] = topRightOffsetSize[classUsed] + "px";
                        topRightOffsetSize[classUsed] += height + offset;
                    }
                }
            }

            // Supporting function chaining
            return this;
        }
    }

    // Set default options as static property
    Toastify.defaults = DEFAULT_OPTIONS;

    // Return the Toastify class
    return Toastify;
});