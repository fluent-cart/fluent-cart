/*!-----------------------------------------------------
 * xZoom v1.0.15
 * (c) 2013 by Azat Ahmedov & Elman Guseynov
 * https://github.com/payalord
 * https://dribbble.com/elmanvebs
 * Apache License 2.0
 *------------------------------------------------------*/
(function () {
    window.requestAnimFrame = (function () {
        return window.requestAnimationFrame ||
            window.webkitRequestAnimationFrame ||
            window.mozAnimationFrame ||
            window.oAnimationFrame ||
            window.msAnimationFrame ||
            function (callback) {
                window.setTimeout(callback, 20);
            };
    })();

    function detectOldIE() {
        if (/MSIE (\d+\.\d+);/.test(navigator.userAgent)) {
            var ieversion = new Number(RegExp.$1);
            if (ieversion >= 9) return false;
            else if (ieversion >= 8) return true;
            else if (ieversion >= 7) return true;
            else if (ieversion >= 6) return true;
            else if (ieversion >= 5) return true;
        } else return false;
    }

    // Polyfills for basic jQuery functions
    function addEvent(element, event, handler) {
        if (element.addEventListener) {
            element.addEventListener(event, handler, false);
        } else if (element.attachEvent) {
            element.attachEvent('on' + event, handler);
        }
    }

    function removeEvent(element, event, handler) {
        if (element.removeEventListener) {
            element.removeEventListener(event, handler, false);
        } else if (element.detachEvent) {
            element.detachEvent('on' + event, handler);
        }
    }

    function triggerEvent(element, eventType) {
        var event;
        if (document.createEvent) {
            event = document.createEvent('HTMLEvents');
            event.initEvent(eventType, true, true);
        } else {
            event = document.createEventObject();
            event.eventType = eventType;
        }
        event.eventName = eventType;
        if (element.dispatchEvent) {
            element.dispatchEvent(event);
        } else if (element.fireEvent) {
            element.fireEvent('on' + event.eventType, event);
        }
    }

    // Simplified $(element).css() for common properties
    function setStyle(element, styles) {
        for (var prop in styles) {
            element.style[prop] = styles[prop];
        }
    }

    // Simplified $(element).width() and $(element).height()
    function getWidth(element) {
        return element.offsetWidth;
    }

    function getHeight(element) {
        return element.offsetHeight;
    }

    function setWidth(element, value) {
        element.style.width = typeof value === 'number' ? value + 'px' : value;
    }

    function setHeight(element, value) {
        element.style.height = typeof value === 'number' ? value + 'px' : value;
    }

    // Simplified $(element).offset()
    function getOffset(element) {
        var rect = element.getBoundingClientRect();
        return {
            top: rect.top + window.pageYOffset,
            left: rect.left + window.pageXOffset
        };
    }

    // Simplified $(element).position()
    function getPosition(element) {
        return {
            top: element.offsetTop,
            left: element.offsetLeft
        };
    }

    // Simplified $(element).append()
    function appendChild(parent, child) {
        parent.appendChild(child);
    }

    // Simplified $(element).remove()
    function removeElement(element) {
        if (element && element.parentNode) {
            element.parentNode.removeChild(element);
        }
    }

    // Simplified $(element).addClass(), .removeClass(), .hasClass()
    function addClass(element, className) {
        element.classList.add(className);
    }

    function removeClass(element, className) {
        element.classList.remove(className);
    }

    function hasClass(element, className) {
        return element.classList.contains(className);
    }

    // Simplified $(element).data()
    var dataStore = new WeakMap();

    function setData(element, key, value) {
        if (!dataStore.has(element)) {
            dataStore.set(element, {});
        }
        dataStore.get(element)[key] = value;
    }

    function getData(element, key) {
        var data = dataStore.get(element);
        return data ? data[key] : undefined;
    }

    // Simplified $(element).attr()
    function getAttr(element, attr) {
        return element.getAttribute(attr);
    }

    function setAttr(element, attr, value) {
        element.setAttribute(attr, value);
    }

    function removeAttr(element, attr) {
        element.removeAttribute(attr);
    }

    // Simplified $(element).html()
    function setHtml(element, html) {
        element.innerHTML = html;
    }

    // Simplified $(element).fadeOut() / .fadeTo()
    function fadeOut(element, duration, callback) {
        var opacity = 1;
        var timer = setInterval(function () {
            if (opacity <= 0.05) { // Check for a small value to ensure it fully fades out
                clearInterval(timer);
                setStyle(element, { 'display': 'none', 'opacity': 1 }); // Reset opacity for future use
                if (callback) callback();
            } else {
                opacity -= opacity * 0.1; // Reduce opacity
                setStyle(element, { 'opacity': opacity });
            }
        }, 1000 / 60); // 60 frames per second
    }

    function fadeTo(element, duration, targetOpacity, callback) {
        var startOpacity = parseFloat(element.style.opacity) || 0;
        var change = targetOpacity - startOpacity;
        var startTime = null;

        function animate(currentTime) {
            if (!startTime) startTime = currentTime;
            var progress = (currentTime - startTime) / duration;
            var currentOpacity = startOpacity + change * progress;
            setStyle(element, { 'opacity': currentOpacity });

            if (progress < 1) {
                requestAnimationFrame(animate);
            } else {
                setStyle(element, { 'opacity': targetOpacity });
                if (callback) callback();
            }
        }
        requestAnimationFrame(animate);
    }


    // Simplified document.createElement and querySelector/querySelectorAll
    function createElement(tag) {
        return document.createElement(tag);
    }

    function querySelector(selector, context) {
        context = context || document;
        return context.querySelector(selector);
    }

    function querySelectorAll(selector, context) {
        context = context || document;
        return Array.prototype.slice.call(context.querySelectorAll(selector));
    }


    // Main xobject function
    function XObject(mObj, opts) {
        this.xzoom = true;
        var current = this;
        var parent;
        var xzoomID = null;

        var sw, sh, mw, mh, moffset, stop, sleft, mtop, mleft, ctop, cleft, mx, my;
        var source, tint, preview, loading, trans, transImg;
        var sobjects = []; // Not directly used in the provided snippet for the vanilla conversion, but kept for consistency
        var imageGallery = [],
            index = 0,
            cindex = 0;
        var img, imgObj, lens, lensImg;
        var lw, lh, ll, lt, llc, ltc, ocofx, ocofy, cofx, cofy, c1, c2, iwh, scale = 0;
        var imgObjwidth, imgObjheight;
        var flag, u = 0,
            v = 0,
            su = 0,
            sv = 0,
            lsu = 0,
            lsv = 0,
            lu = 0,
            lv = 0,
            llu = 0,
            llv = 0;
        var ie = detectOldIE(),
            aie = /MSIE (\d+\.\d+);/.test(navigator.userAgent),
            iex, iey;
        var active, title = '',
            caption, caption_container;

        var wsw, wsh, osw, osh, tsw, tsh, oposition, reverse;

        this.adaptive = function () {
            if (osw === 0 || osh === 0) {
                setStyle(mObj, { 'width': '', 'height': '' });
                osw = getWidth(mObj);
                osh = getHeight(mObj);
            }

            xremove();
            wsw = window.innerWidth || document.documentElement.clientWidth || document.body.clientWidth;
            wsh = window.innerHeight || document.documentElement.clientHeight || document.body.clientHeight;

            tsw = getWidth(mObj);
            tsh = getHeight(mObj);

            var update = false;
            if (osw > wsw || osh > wsh) update = true;

            if (tsw > osw) tsw = osw;
            if (tsh > osh) tsh = osh;
            if (update) {
                setWidth(mObj, '100%');
            } else {
                if (osw !== 0) setWidth(mObj, osw);
            }
            if (oposition != 'fullscreen') {
                if (adaptive_position_fit()) {
                    current.options.position = oposition;
                } else {
                    current.options.position = current.options.mposition;
                }
            }
            if (!current.options.lensReverse) reverse = current.options.adaptiveReverse && current.options.position == current.options.mposition;
        };

        function spos() {
            var doc = document.documentElement;
            var left = (window.pageXOffset || doc.scrollLeft) - (doc.clientLeft || 0);
            var top = (window.pageYOffset || doc.scrollTop) - (doc.clientTop || 0);
            return {
                left: left,
                top: top
            };
        }

        function adaptive_position_fit() {
            var moffset = getOffset(mObj);

            if (current.options.zoomWidth == 'auto') mw = tsw;
            else mw = current.options.zoomWidth;
            if (current.options.zoomHeight == 'auto') mh = tsh;
            else mh = current.options.zoomHeight;

            if (current.options.position.substr(0, 1) == '#') xzoomID = querySelector(current.options.position);
            else xzoomID = null;

            if (xzoomID) return true;

            switch (oposition) {
                case 'lens':
                case 'inside':
                    return true;
                case 'top':
                    stop = moffset.top;
                    sleft = moffset.left;
                    mtop = stop - mh;
                    mleft = sleft;
                    break;
                case 'left':
                    stop = moffset.top;
                    sleft = moffset.left;
                    mtop = stop;
                    mleft = sleft - mw;
                    break;
                case 'bottom':
                    stop = moffset.top;
                    sleft = moffset.left;
                    mtop = stop + tsh;
                    mleft = sleft;
                    break;
                case 'right':
                default:
                    stop = moffset.top;
                    sleft = moffset.left;
                    mtop = stop;
                    mleft = sleft + tsw;
            }
            if (mleft + mw > wsw || mleft < 0) return false;
            return true;
        }

        this.xscroll = function (event) {
            event.preventDefault();

            mx = event.pageX || event.originalEvent.pageX;
            my = event.pageY || event.originalEvent.pageY;

            if (event.xscale) {
                scale = event.xscale;
                xscale(mx, my);
            } else {
                var delta = -event.detail || event.wheelDelta || event.xdelta; // event.originalEvent is jQuery specific
                var x = mx;
                var y = my;
                if (ie) {
                    x = iex;
                    y = iey;
                }

                if (delta > 0) delta = -0.05;
                else delta = 0.05;
                scale += delta;
                xscale(x, y);
            }
        };

        function lensShape() {
            if (current.options.lensShape === 'circle' && current.options.position === 'lens') {
                if (current.options.initialLensWidth !== 'auto' && current.options.initialLensHeight !== 'auto' && !isNaN(current.options.initialLensWidth) && !isNaN(current.options.initialLensHeight)) {
                    lw = lh = Math.max(lw, lh);
                } else {
                    lw = lh = Math.max(lw, lh);
                }
                var radius = (lw + Math.max(ltc, llc) * 2) / 2;
                setStyle(lens, {
                    '-moz-border-radius': radius + 'px',
                    '-webkit-border-radius': radius + 'px',
                    'border-radius': radius + 'px'
                });
            }
        }

        function lensOutput(x, y, iw, ih) {
            if (current.options.position === 'lens') {
                // Calculate zoomed image position
                var imgLeft = -(x - sleft) * cofx + (lw / 2);
                var imgTop = -(y - stop) * cofy + (lh / 2);

                // Constrain zoomed image within lens boundaries
                var maxLeft = lw - iw;
                var maxTop = lh - ih;
                imgLeft = Math.max(maxLeft, Math.min(0, imgLeft));
                imgTop = Math.max(maxTop, Math.min(0, imgTop));

                setStyle(imgObj, {
                    top: imgTop + 'px',
                    left: imgLeft + 'px'
                });

                if (current.options.bg) {
                    setStyle(lens, {
                        'background-image': 'url(' + getAttr(imgObj, 'src') + ')',
                        'background-repeat': 'no-repeat',
                        'background-position': (-(x - sleft) * cofx + (lw / 2)) + 'px ' + (-(y - stop) * cofy + (lh / 2)) + 'px'
                    });
                    if (iw && ih) setStyle(lens, {
                        'background-size': iw + 'px ' + ih + 'px'
                    });
                }
            } else {
                // Constrain zoomed image within preview boundaries
                var imgLeft = -ll * cofx;
                var imgTop = -lt * cofy;
                var maxLeft = mw - iw;
                var maxTop = mh - ih;
                imgLeft = Math.max(maxLeft, Math.min(0, imgLeft));
                imgTop = Math.max(maxTop, Math.min(0, imgTop));
                setStyle(imgObj, {
                    top: imgTop + 'px',
                    left: imgLeft + 'px'
                });
            }
        }

        function xscale(x, y) {
            if (scale < -1) scale = -1;
            if (scale > 1) scale = 1;

            var cc, iw, ih;

            if (c1 < c2) {
                cc = c1 - (c1 - 1) * scale;
                iw = mw * cc;
                ih = iw / iwh;
            } else {
                cc = c2 - (c2 - 1) * scale;
                ih = mh * cc;
                iw = ih * iwh;
            }

            if (flag) {
                u = x;
                v = y;
                su = iw;
                sv = ih;
            } else {
                if (!flag) {
                    lsu = su = iw;
                    lsv = sv = ih;
                }
                cofx = iw / sw;
                cofy = ih / sh;

                lw = (current.options.initialLensWidth !== 'auto' && !isNaN(current.options.initialLensWidth)) ?
                    parseFloat(current.options.initialLensWidth) / cc :
                    mw / cofx;
                lh = (current.options.initialLensHeight !== 'auto' && !isNaN(current.options.initialLensHeight)) ?
                    parseFloat(current.options.initialLensHeight) / cc :
                    mh / cofy;

                lensShape();
                set_lens(x, y);

                setWidth(imgObj, iw);
                setHeight(imgObj, ih);
                setWidth(lens, lw);
                setHeight(lens, lh);

                setStyle(lens, {
                    top: (lt - ltc) + 'px',
                    left: (ll - llc) + 'px'
                });
                setStyle(lensImg, {
                    top: (-lt) + 'px',
                    left: (-ll) + 'px'
                });
                lensOutput(x, y, iw, ih);
            }
        }

        function loopZoom() {
            var x = lu;
            var y = lv;
            var x2 = llu;
            var y2 = llv;
            var sx = lsu;
            var sy = lsv;
            x += (u - x) / current.options.smoothLensMove;
            y += (v - y) / current.options.smoothLensMove;

            x2 += (u - x2) / current.options.smoothZoomMove;
            y2 += (v - y2) / current.options.smoothZoomMove;

            sx += (su - sx) / current.options.smoothScale;
            sy += (sv - sy) / current.options.smoothScale;

            cofx = sx / sw;
            cofy = sy / sh;

            lw = (current.options.initialLensWidth !== 'auto' && !isNaN(current.options.initialLensWidth)) ?
                parseFloat(current.options.initialLensWidth) / (c1 < c2 ? (c1 - (c1 - 1) * scale) : (c2 - (c2 - 1) * scale)) :
                mw / cofx;
            lh = (current.options.initialLensHeight !== 'auto' && !isNaN(current.options.initialLensHeight)) ?
                parseFloat(current.options.initialLensHeight) / (c1 < c2 ? (c1 - (c1 - 1) * scale) : (c2 - (c2 - 1) * scale)) :
                mh / cofy;

            lensShape();
            set_lens(x, y);

            setWidth(imgObj, sx);
            setHeight(imgObj, sy);

            setWidth(lens, lw);
            setHeight(lens, lh);

            setStyle(lens, {
                top: (lt - ltc) + 'px',
                left: (ll - llc) + 'px'
            });
            setStyle(lensImg, {
                top: (-lt) + 'px',
                left: (-ll) + 'px'
            });

            set_lens(x2, y2);
            lensOutput(x, y, sx, sy); // Pass smoothed dimensions

            lu = x;
            lv = y;
            llu = x2;
            llv = y2;
            lsu = sx;
            lsv = sy;

            if (flag) requestAnimFrame(loopZoom);
        }

        function set_lens(x, y) {
            x -= sleft;
            y -= stop;
            ll = x - (lw / 2);
            lt = y - (lh / 2);

            if (current.options.position != 'lens' && current.options.lensCollision) {
                if (ll < 0) ll = 0;
                if (sw >= lw && ll > sw - lw) ll = sw - lw;
                if (sw < lw) ll = sw / 2 - lw / 2;
                if (lt < 0) lt = 0;
                if (sh >= lh && lt > sh - lh) lt = sh - lh;
                if (sh < lh) lt = sh / 2 - lh / 2;
            }
        }

        function xremove() {
            if (source) removeElement(source);
            if (preview) removeElement(preview);
            if (caption_container) removeElement(caption_container);
        }

        function prepare_zoom(x, y) {
            if (current.options.position == 'fullscreen') {
                sw = window.innerWidth || document.documentElement.clientWidth || document.body.clientWidth;
                sh = window.innerHeight || document.documentElement.clientHeight || document.body.clientHeight;
            } else {
                sw = getWidth(mObj);
                sh = getHeight(mObj);
            }

            setStyle(loading, {
                top: (sh / 2 - getHeight(loading) / 2) + 'px',
                left: (sw / 2 - getWidth(loading) / 2) + 'px'
            });

            if (current.options.rootOutput || current.options.position == 'fullscreen') {
                moffset = getOffset(mObj);
            } else {
                moffset = getPosition(mObj);
            }

            moffset.top = Math.round(moffset.top);
            moffset.left = Math.round(moffset.left);

            switch (current.options.position) {
                case 'fullscreen':
                    stop = spos().top;
                    sleft = spos().left;
                    mtop = 0;
                    mleft = 0;
                    break;
                case 'inside':
                    stop = moffset.top;
                    sleft = moffset.left;
                    mtop = 0;
                    mleft = 0;
                    break;
                case 'top':
                    stop = moffset.top;
                    sleft = moffset.left;
                    mtop = stop - mh;
                    mleft = sleft;
                    break;
                case 'left':
                    stop = moffset.top;
                    sleft = moffset.left;
                    mtop = stop;
                    mleft = sleft - mw;
                    break;
                case 'bottom':
                    stop = moffset.top;
                    sleft = moffset.left;
                    mtop = stop + sh;
                    mleft = sleft;
                    break;
                case 'right':
                default:
                    stop = moffset.top;
                    sleft = moffset.left;
                    mtop = stop;
                    mleft = sleft + sw;
            }

            stop -= (source.offsetHeight - source.clientHeight) / 2; // Outer height - client height gives vertical padding/border
            sleft -= (source.offsetWidth - source.clientWidth) / 2; // Outer width - client width gives horizontal padding/border

            if (current.options.position.substr(0, 1) == '#') xzoomID = querySelector(current.options.position);
            else xzoomID = null;

            if (!xzoomID && current.options.position != 'inside' && current.options.position != 'fullscreen') {
                if (!current.options.adaptive || !osw || !osh) {
                    osw = sw;
                    osh = sh;
                }
                if (current.options.zoomWidth == 'auto') mw = sw;
                else mw = current.options.zoomWidth;
                if (current.options.zoomHeight == 'auto') mh = sh;
                else mh = current.options.zoomHeight;

                mtop += current.options.Yoffset;
                mleft += current.options.Xoffset;

                setStyle(preview, {
                    width: mw + 'px',
                    height: mh + 'px',
                    top: mtop + 'px',
                    left: mleft + 'px'
                });
                if (current.options.position != 'lens') appendChild(parent, preview);
            } else if (current.options.position == 'inside' || current.options.position == 'fullscreen') {
                mw = sw;
                mh = sh;

                setStyle(preview, {
                    width: mw + 'px',
                    height: mh + 'px'
                });
                appendChild(source, preview);
            } else {
                mw = getWidth(xzoomID);
                mh = getHeight(xzoomID);

                if (current.options.rootOutput) {
                    mtop = getOffset(xzoomID).top;
                    mleft = getOffset(xzoomID).left;
                    appendChild(parent, preview);
                } else {
                    mtop = getPosition(xzoomID).top;
                    mleft = getPosition(xzoomID).left;
                    appendChild(xzoomID.parentNode, preview);
                }

                mtop += (xzoomID.offsetHeight - mh - preview.offsetHeight) / 2;
                mleft += (xzoomID.offsetWidth - mw - preview.offsetWidth) / 2;
                setStyle(preview, {
                    width: mw + 'px',
                    height: mh + 'px',
                    top: mtop + 'px',
                    left: mleft + 'px'
                });
            }

            if (current.options.title && title != '') {
                if (current.options.position == 'inside' || current.options.position == 'lens' || current.options.position == 'fullscreen') {
                    ctop = mtop;
                    cleft = mleft;
                    appendChild(source, caption_container);
                } else {
                    ctop = mtop + (preview.offsetHeight - mh) / 2;
                    cleft = mleft + (preview.offsetWidth - mw) / 2;
                    appendChild(parent, caption_container);
                }
                setStyle(caption_container, {
                    width: mw + 'px',
                    height: mh + 'px',
                    top: ctop + 'px',
                    left: cleft + 'px'
                });
            }

            setStyle(source, {
                width: sw + 'px',
                height: sh + 'px',
                top: stop + 'px',
                left: sleft + 'px'
            });
            setStyle(tint, {
                width: sw + 'px',
                height: sh + 'px'
            });
            if (current.options.tint && (current.options.position != 'inside' && current.options.position != 'fullscreen'))
                setStyle(tint, {
                    'background-color': current.options.tint
                });
            else if (ie) {
                setStyle(tint, {
                    'background-image': 'url(' + getAttr(mObj, 'src') + ')',
                    'background-color': '#fff'
                });
            }

            img = new Image();
            var timestamp = aie ? '?r=' + (new Date()).getTime() : '';
            img.src = getAttr(mObj, 'src') + timestamp;

            imgObj = img;
            setStyle(imgObj, {
                'position': 'absolute'
            });

            img = new Image();
            img.src = getAttr(mObj, 'src');
            lensImg = img;

            setStyle(lensImg, {
                'position': 'absolute'
            });
            setWidth(lensImg, sw);

            switch (current.options.position) {
                case 'fullscreen':
                case 'inside':
                    appendChild(preview, imgObj);
                    break;
                case 'lens':
                    appendChild(lens, imgObj);
                    if (current.options.bg) setStyle(imgObj, {
                        display: 'none'
                    });
                    break;
                default:
                    appendChild(preview, imgObj);
                    appendChild(lens, lensImg);
            }
        }

        this.openzoom = function (event) {
            mx = event.pageX;
            my = event.pageY;

            if (current.options.adaptive) current.adaptive();
            scale = current.options.defaultScale;
            flag = false;

            source = createElement('div');
            if (current.options.sourceClass != '') addClass(source, current.options.sourceClass);
            setStyle(source, {
                'position': 'absolute',
                'z-index': 99999
            });

            loading = createElement('div');
            if (current.options.loadingClass != '') addClass(loading, current.options.loadingClass);
            setStyle(loading, {
                'position': 'absolute'
            });

            tint = createElement('div');
            setStyle(tint, {
                'position': 'absolute',
                'top': 0,
                'left': 0
            });
            appendChild(source, loading);

            preview = createElement('div');
            if (current.options.zoomClass != '' && current.options.position != 'fullscreen') addClass(preview, current.options.zoomClass);
            setStyle(preview, {
                position: 'absolute',
                overflow: 'hidden',
                opacity: 1,
                'z-index': 99999
            });

            if (current.options.title && title != '') {
                caption_container = createElement('div');
                caption = createElement('div');
                setStyle(caption_container, {
                    position: 'absolute',
                    opacity: 1
                });
                if (current.options.titleClass) addClass(caption, current.options.titleClass);
                setHtml(caption, '<span>' + title + '</span>');
                appendChild(caption_container, caption);
                if (current.options.fadeIn) setStyle(caption_container, {
                    opacity: 0
                });
            }

            lens = createElement('div');
            if (current.options.lensClass != '') addClass(lens, current.options.lensClass);
            setStyle(lens, {
                position: 'absolute',
                overflow: 'hidden'
            });

            if (current.options.lens) {
                var lenstint = createElement('div');
                setStyle(lenstint, {
                    position: 'absolute',
                    background: current.options.lens,
                    opacity: current.options.lensOpacity,
                    width: '100%',
                    height: '100%',
                    top: 0,
                    left: 0,
                    'z-index': 9999
                });
                appendChild(lens, lenstint);
            }

            prepare_zoom(mx, my);

            if (current.options.position != 'inside' && current.options.position != 'fullscreen') {
                if (current.options.tint || ie) appendChild(source, tint);
                if (current.options.fadeIn) {
                    setStyle(tint, {
                        opacity: 0
                    });
                    setStyle(lens, {
                        opacity: 0
                    });
                    setStyle(preview, {
                        opacity: 0
                    });
                } else {
                    setStyle(tint, {
                        opacity: current.options.tintOpacity
                    });
                    setStyle(lens, {
                        opacity: 1
                    });
                    setStyle(preview, {
                        opacity: 1
                    });
                }
                appendChild(parent, source);
            } else {
                if (current.options.fadeIn) setStyle(preview, {
                    opacity: 0
                });
                appendChild(parent, source);
            }

            current.eventmove(source);
            current.eventleave(source);

            // Calculate outer dimensions for padding/border adjustments
            var previewOuterHeight = getHeight(preview) + parseFloat(getComputedStyle(preview).marginTop) + parseFloat(getComputedStyle(preview).marginBottom) + parseFloat(getComputedStyle(preview).borderTopWidth) + parseFloat(getComputedStyle(preview).borderBottomWidth) + parseFloat(getComputedStyle(preview).paddingTop) + parseFloat(getComputedStyle(preview).paddingBottom);
            var previewOuterWidth = getWidth(preview) + parseFloat(getComputedStyle(preview).marginLeft) + parseFloat(getComputedStyle(preview).marginRight) + parseFloat(getComputedStyle(preview).borderLeftWidth) + parseFloat(getComputedStyle(preview).borderRightWidth) + parseFloat(getComputedStyle(preview).paddingLeft) + parseFloat(getComputedStyle(preview).paddingRight);


            switch (current.options.position) {
                case 'inside':
                    mtop -= (previewOuterHeight - getHeight(preview)) / 2;
                    mleft -= (previewOuterWidth - getWidth(preview)) / 2;
                    break;
                case 'top':
                    mtop -= previewOuterHeight - getHeight(preview);
                    mleft -= (previewOuterWidth - getWidth(preview)) / 2;
                    break;
                case 'left':
                    mtop -= (previewOuterHeight - getHeight(preview)) / 2;
                    mleft -= previewOuterWidth - getWidth(preview);
                    break;
                case 'bottom':
                    mleft -= (previewOuterWidth - getWidth(preview)) / 2;
                    break;
                case 'right':
                    mtop -= (previewOuterHeight - getHeight(preview)) / 2;
            }

            setStyle(preview, {
                top: mtop + 'px',
                left: mleft + 'px'
            });

            addEvent(imgObj, 'load', function (e) {
                removeElement(loading);

                if (!current.options.openOnSmall && (getWidth(imgObj) < mw || getHeight(imgObj) < mh)) {
                    current.closezoom();
                    e.preventDefault();
                    return false;
                }

                if (current.options.scroll) current.eventscroll(source);

                if (current.options.position != 'inside' && current.options.position != 'fullscreen') {
                    appendChild(source, lens);
                    if (current.options.fadeIn) {
                        fadeTo(tint, 300, current.options.tintOpacity);
                        fadeTo(lens, 300, 1);
                        fadeTo(preview, 300, 1);
                    } else {
                        setStyle(tint, {
                            opacity: current.options.tintOpacity
                        });
                        setStyle(lens, {
                            opacity: 1
                        });
                        setStyle(preview, {
                            opacity: 1
                        });
                    }
                } else {
                    if (current.options.fadeIn) {
                        fadeTo(preview, 300, 1);
                    } else {
                        setStyle(preview, {
                            opacity: 1
                        });
                    }
                }

                if (current.options.title && title != '') {
                    if (current.options.fadeIn) fadeTo(caption_container, 300, 1);
                    else setStyle(caption_container, {
                        opacity: 1
                    });
                }

                var minWidth = 1000;
                imgObjwidth = getWidth(imgObj);
                imgObjheight = getHeight(imgObj);

                if (imgObjwidth < minWidth) {
                    var aspectRatio = getHeight(imgObj) / imgObjwidth;
                    imgObjwidth = minWidth;
                    imgObjheight = minWidth * aspectRatio;
                    setStyle(imgObj, {
                        'width': '400px',
                        'height': imgObjheight + 'px'
                    });
                }

                if (current.options.adaptive) {
                    if (sw < osw || sh < osh) {
                        setWidth(lensImg, sw);
                        setHeight(lensImg, sh);
                        imgObjwidth = sw / osw * imgObjwidth;
                        imgObjheight = sh / osh * imgObjheight;
                        setWidth(imgObj, imgObjwidth);
                        setHeight(imgObj, imgObjheight);
                    }
                }

                lsu = su = imgObjwidth;
                lsv = sv = imgObjheight;
                iwh = imgObjwidth / imgObjheight;
                c1 = imgObjwidth / mw;
                c2 = imgObjheight / mh;

                var t, o = ['padding-', 'border-'];
                ltc = llc = 0;
                for (var i = 0; i < o.length; i++) {
                    t = parseFloat(getComputedStyle(lens)[o[i] + 'top-width']);
                    ltc += t !== t ? 0 : t;
                    t = parseFloat(getComputedStyle(lens)[o[i] + 'bottom-width']);
                    ltc += t !== t ? 0 : t;
                    t = parseFloat(getComputedStyle(lens)[o[i] + 'left-width']);
                    llc += t !== t ? 0 : t;
                    t = parseFloat(getComputedStyle(lens)[o[i] + 'right-width']);
                    llc += t !== t ? 0 : t;
                }
                ltc /= 2;
                llc /= 2;

                llu = lu = u = mx;
                llv = lv = v = my;
                xscale(mx, my);

                if (current.options.smooth) {
                    flag = true;
                    requestAnimFrame(loopZoom);
                }

                current.eventclick(source);
            });
        };

        this.movezoom = function (event) {
            mx = event.pageX;
            my = event.pageY;
            if (ie) {
                iex = mx;
                iey = my;
            }

            var x = mx - sleft;
            var y = my - stop;

            if (reverse) {
                event.pageX -= (x - sw / 2) * 2;
                event.pageY -= (y - sh / 2) * 2;
            }

            if (x < 0 || x > sw || y < 0 || y > sh) triggerEvent(source, 'mouseleave');
            if (current.options.smooth) {
                u = event.pageX;
                v = event.pageY;
            } else {
                lensShape();
                set_lens(event.pageX, event.pageY);
                setStyle(lens, {
                    top: (lt - ltc) + 'px',
                    left: (ll - llc) + 'px'
                });
                setStyle(lensImg, {
                    top: (-lt) + 'px',
                    left: (-ll) + 'px'
                });
                lensOutput(event.pageX, event.pageY, getWidth(imgObj), getHeight(imgObj));
            }
        };

        this.eventdefault = function () {
            current.eventopen = function (element) {
                addEvent(element, 'mouseenter', current.openzoom);
            };

            current.eventleave = function (element) {
                addEvent(element, 'mouseleave', current.closezoom);
            };

            current.eventmove = function (element) {
                addEvent(element, 'mousemove', current.movezoom);
            };

            current.eventscroll = function (element) {
                addEvent(element, 'mousewheel', current.xscroll);
                addEvent(element, 'DOMMouseScroll', current.xscroll);
                addEvent(element, 'wheel', current.xscroll);
            };

            current.eventclick = function (element) {
                addEvent(element, 'click', function (event) {
                    triggerEvent(mObj, 'click');
                });
            };
        };

        this.eventunbind = function () {
            removeEvent(mObj, 'mouseenter', current.openzoom); // Assuming openzoom is initially bound
            current.eventopen = function () {};
            current.eventleave = function () {};
            current.eventmove = function () {};
            current.eventscroll = function () {};
            current.eventclick = function () {};
        };

        this.init = function (options) {
            current.options = Object.assign({}, XObject.defaults, options);

            if (current.options.rootOutput) {
                parent = document.body;
            } else {
                parent = mObj.parentNode;
            }

            oposition = current.options.position;
            reverse = current.options.lensReverse && current.options.position == 'inside';

            if (current.options.smoothZoomMove < 1) current.options.smoothZoomMove = 1;
            if (current.options.smoothLensMove < 1) current.options.smoothLensMove = 1;
            if (current.options.smoothScale < 1) current.options.smoothScale = 1;

            if (current.options.initialLensWidth !== 'auto' && (!isFinite(current.options.initialLensWidth) || current.options.initialLensWidth <= 0)) {
                console.warn('xZoom: Invalid initialLensWidth, falling back to auto');
                current.options.initialLensWidth = 'auto';
            }
            if (current.options.initialLensHeight !== 'auto' && (!isFinite(current.options.initialLensHeight) || current.options.initialLensHeight <= 0)) {
                console.warn('xZoom: Invalid initialLensHeight, falling back to auto');
                current.options.initialLensHeight = 'auto';
            }

            if (current.options.adaptive) {
                addEvent(window, 'load', function () {
                    osw = getWidth(mObj);
                    osh = getHeight(mObj);

                    if (current.options.initialLensWidth !== 'auto' && current.options.initialLensWidth > osw) {
                        console.warn('xZoom: initialLensWidth exceeds source image width, capping to source width');
                        current.options.initialLensWidth = osw;
                    }
                    if (current.options.initialLensHeight !== 'auto' && current.options.initialLensHeight > osh) {
                        console.warn('xZoom: initialLensHeight exceeds source image height, capping to source height');
                        current.options.initialLensHeight = osh;
                    }

                    current.adaptive();
                    addEvent(window, 'resize', current.adaptive);
                });
            }
            current.eventdefault();
            current.eventopen(mObj);
        };

        this.destroy = function () {
            current.eventunbind();
        };

        this.closezoom = function () {
            flag = false;
            if (current.options.fadeOut) {
                if (current.options.title && title != '') fadeOut(caption_container, 299);
                if (current.options.position != 'inside' || current.options.position != 'fullscreen') {
                    fadeOut(preview, 299);
                    fadeOut(source, 300, function () {
                        xremove();
                    });
                } else {
                    fadeOut(source, 300, function () {
                        xremove();
                    });
                }
            } else {
                xremove();
            }
        };

        this.gallery = function () {
            var g = [];
            var i, j = 0;
            for (i = cindex; i < imageGallery.length; i++) {
                g[j] = imageGallery[i];
                j++;
            }
            for (i = 0; i < cindex; i++) {
                g[j] = imageGallery[i];
                j++;
            }
            return {
                index: cindex,
                ogallery: imageGallery,
                cgallery: g
            };
        };

        function get_title(element) {
            var otitle = getAttr(element, 'title');
            var xotitle = getAttr(element, 'xtitle');
            if (xotitle) return xotitle;
            else if (otitle) return otitle;
            else return '';
        }

        this.xappend = function (Obj) {
            var link = Obj.parentNode; // Assuming Obj is an image within an anchor tag
            imageGallery[index] = getAttr(link, 'href');
            setData(link, 'xindex', index);
            if (index == 0 && current.options.activeClass) {
                active = Obj;
                addClass(active, current.options.activeClass);
            }
            if (index == 0 && current.options.title) title = get_title(Obj);
            index++;

            function thumbchange(event) {
                xremove();
                event.preventDefault();
                if (current.options.activeClass) {
                    removeClass(active, current.options.activeClass);
                    active = Obj;
                    addClass(active, current.options.activeClass);
                }
                cindex = getData(link, 'xindex');
                if (current.options.fadeTrans) {
                    transImg = new Image();
                    transImg.src = getAttr(mObj, 'src');
                    trans = transImg;
                    setStyle(trans, {
                        position: 'absolute',
                        top: getOffset(mObj).top + 'px',
                        left: getOffset(mObj).left + 'px',
                        width: getWidth(mObj) + 'px',
                        height: getHeight(mObj) + 'px'
                    });
                    appendChild(document.body, trans);
                    fadeOut(trans, 200, function () {
                        removeElement(trans);
                    });
                }
                var _xorig = getAttr(link, 'href');
                var _prev = getAttr(Obj, 'xpreview') || getAttr(Obj, 'src');

                title = get_title(Obj);
                if (getAttr(Obj, 'title')) setAttr(mObj, 'title', getAttr(Obj, 'title'));

                setAttr(mObj, 'xoriginal', _xorig);
                removeAttr(mObj, 'style');
                setAttr(mObj, 'src', _prev);
                if (current.options.adaptive) {
                    osw = getWidth(mObj);
                    osh = getHeight(mObj);
                }
            }

            if (current.options.hover) {
                addEvent(link, 'mouseenter', thumbchange);
            }
            addEvent(link, 'click', thumbchange);
        };

        this.init(opts);
    }

    XObject.defaults = {
        position: 'right',
        mposition: 'inside',
        rootOutput: true,
        Xoffset: 0,
        Yoffset: 0,
        fadeIn: true,
        fadeTrans: true,
        fadeOut: false,
        smooth: true,
        smoothZoomMove: 3,
        smoothLensMove: 1,
        smoothScale: 6,
        defaultScale: 0,
        scroll: true,
        tint: false,
        tintOpacity: 0.5,
        lens: false,
        lensOpacity: 0.5,
        lensShape: 'box',
        lensCollision: true,
        lensReverse: false,
        openOnSmall: true,
        zoomWidth: 'auto',
        zoomHeight: 'auto',
        sourceClass: 'xzoom-source',
        loadingClass: 'xzoom-loading',
        lensClass: 'xzoom-lens',
        zoomClass: 'xzoom-preview',
        activeClass: 'xactive',
        hover: false,
        adaptive: true,
        adaptiveReverse: false,
        title: false,
        titleClass: 'xzoom-caption',
        bg: false,
        initialLensWidth: 'auto',
        initialLensHeight: 'auto'
    };

    // Global access point for xZoom
    window.xZoom = function (selector, options) {

        var elements;
        if(typeof  selector === 'object'){
            elements = [selector]
        }else{
            elements = querySelectorAll(selector);
        }
        var mainObj;
        var secObj;

        if (elements.length === 0) return false;

        elements.forEach(function (el, index) {
            if (index === 0) {
                mainObj = el;
                if (getData(mainObj, 'xzoom')) return getData(mainObj, 'xzoom');
                mainObj.x = new XObject(mainObj, options);
            } else if (mainObj && mainObj.x) {
                secObj = el;
                mainObj.x.xappend(secObj);
            }
        });

        if (typeof mainObj === 'undefined') return false;

        setData(mainObj, 'xzoom', mainObj.x);
        triggerEvent(mainObj, 'xzoom_ready');
        return mainObj.x;
    };

})();
