const { useEffect, useRef, useMemo, useState, useCallback } = wp.element;
const { select, dispatch, useSelect } = wp.data;
import { addQueryArgs } from '@wordpress/url';
import blocktranslate from "@/BlockEditor/BlockEditorTranslator";

const ServerSidePreview = ({ block, attributes }) => {
    const postId = select('core/editor').getCurrentPostId();
    const iframeRef = useRef(null);
    const [isLoading, setIsLoading] = useState(false);
    const [iframeKey, setIframeKey] = useState(0);
    const [pendingUpdate, setPendingUpdate] = useState(false);
    const previousAttributesRef = useRef(attributes);
    const updateTimeoutRef = useRef(null);


    const src = useMemo(() => {
        return addQueryArgs('', {
            'fluent-cart': 'block-render',
            block,
            context: 'edit',
            attributes,
            postId: postId,
            _locale: 'user',
            timestamp: Date.now() // Force fresh load when needed
        });
    }, [attributes, block, postId, iframeKey]);

    // Debounced update to prevent rapid successive updates
    const debouncedUpdate = useCallback((newAttributes) => {
        if (updateTimeoutRef.current) {
            clearTimeout(updateTimeoutRef.current);
        }

        updateTimeoutRef.current = setTimeout(() => {
            performUpdate(newAttributes);
        }, 300); // 300ms debounce
    }, []);

    // Store iframe scroll position before update
    const getScrollPosition = useCallback(() => {
        try {
            if (iframeRef.current?.contentWindow) {
                return {
                    scrollTop: iframeRef.current.contentDocument.documentElement.scrollTop ||
                        iframeRef.current.contentDocument.body.scrollTop,
                    scrollLeft: iframeRef.current.contentDocument.documentElement.scrollLeft ||
                        iframeRef.current.contentDocument.body.scrollLeft
                };
            }
        } catch (error) {
            // Cross-origin restrictions, return null
            return null;
        }
        return null;
    }, []);

    // Restore iframe scroll position after update
    const restoreScrollPosition = useCallback((scrollPosition) => {
        if (!scrollPosition) return;

        try {
            setTimeout(() => {
                if (iframeRef.current?.contentWindow) {
                    iframeRef.current.contentWindow.scrollTo(
                        scrollPosition.scrollLeft,
                        scrollPosition.scrollTop
                    );
                }
            }, 100); // Small delay to ensure content is loaded
        } catch (error) {
            // Ignore cross-origin errors
        }
    }, []);

    // Smooth update with preserved scroll position
    const performUpdate = useCallback(async (newAttributes) => {
        if (!iframeRef.current) return;

        setIsLoading(true);
        const scrollPosition = getScrollPosition();

        // Method 1: Update src and wait for load
        const updateViaSrc = () => {
            return new Promise((resolve) => {
                const onLoad = () => {
                    iframeRef.current?.removeEventListener('load', onLoad);
                    resolve();
                };

                if (iframeRef.current) {
                    iframeRef.current.addEventListener('load', onLoad);
                    // Force iframe to reload with new attributes
                    setIframeKey(prev => prev + 1);
                }
            });
        };

        await updateViaSrc();

        // Restore scroll position
        restoreScrollPosition(scrollPosition);

        setIsLoading(false);
        setPendingUpdate(false);
        previousAttributesRef.current = newAttributes;
    }, [getScrollPosition, restoreScrollPosition]);

    // Handle attribute changes
    useEffect(() => {
        const attributesChanged = JSON.stringify(attributes) !== JSON.stringify(previousAttributesRef.current);

        if (attributesChanged && !pendingUpdate) {
            setPendingUpdate(true);

            // Choose update method:
            // Option 1: Simple update with scroll preservation
            debouncedUpdate(attributes);

            // Option 2: Seamless transition (uncomment to use)
             //seamlessUpdate(attributes);

            // Option 3: Preload approach (uncomment to use)
            // preloadUpdate(attributes);
        }
    }, [attributes, pendingUpdate, debouncedUpdate]);

    // Cleanup timeout on unmount
    useEffect(() => {
        return () => {
            if (updateTimeoutRef.current) {
                clearTimeout(updateTimeoutRef.current);
            }
        };
    }, []);

    // Handle iframe load events
    const handleIframeLoad = useCallback(() => {
        // Iframe has loaded, we can perform any post-load actions here
        if (isLoading) {
            // If we were showing loading, keep it for a bit longer for smooth UX
            setTimeout(() => {
                setIsLoading(false);
            }, 200);
        }
    }, [isLoading]);

    // Force reload function (for manual use if needed)
    const forceReload = useCallback(() => {
        setIsLoading(true);
        setIframeKey(prev => prev + 1);
    }, []);

    return (
        <div style={{ position: 'relative', width: '100%', height: '600px' }}>
            {/* Loading overlay with smooth animation */}
            <div
                style={{
                    position: 'absolute',
                    top: 0,
                    left: 0,
                    width: '100%',
                    height: '100%',
                    background: 'rgba(248, 249, 250, 0.95)',
                    display: 'flex',
                    alignItems: 'center',
                    justifyContent: 'center',
                    zIndex: 20,
                    opacity: isLoading ? 1 : 0,
                    visibility: isLoading ? 'visible' : 'hidden',
                    transition: 'opacity 0.2s ease-in-out, visibility 0.2s ease-in-out',
                    borderRadius: '4px',
                    backdropFilter: 'blur(2px)'
                }}
            >
                <div style={{
                    padding: '16px 24px',
                    background: 'white',
                    borderRadius: '6px',
                    boxShadow: '0 4px 12px rgba(0,0,0,0.15)',
                    display: 'flex',
                    alignItems: 'center',
                    gap: '10px',
                    fontSize: '14px',
                    color: '#1e1e1e'
                }}>
                    {/* Simple loading spinner */}
                    <div
                        style={{
                            width: '16px',
                            height: '16px',
                            border: '2px solid #e1e1e1',
                            borderTop: '2px solid #0073aa',
                            borderRadius: '50%',
                            animation: 'spin 1s linear infinite'
                        }}
                    />
                    {blocktranslate('Updating preview...')}
                </div>
            </div>

            {/* Main iframe */}
            <iframe
                key={iframeKey} // Force re-render when key changes
                ref={iframeRef}
                src={src}
                onLoad={handleIframeLoad}
                style={{
                    width: '100%',
                    height: '100%',
                    border: 'none',
                    transition: 'opacity 0.2s ease-in-out',
                    opacity: isLoading ? 0.7 : 1
                }}
            />

            {/* Transparent overlay */}
            <div
                style={{
                    position: 'absolute',
                    top: 0,
                    left: 0,
                    width: '100%',
                    height: '100%',
                    cursor: 'pointer',
                    background: 'transparent',
                    zIndex: 10,
                }}
                onClick={() => {
                    // Handle overlay click - open inspector or other actions
                    // Example: dispatch('core/block-editor').selectBlock(clientId);
                }}
            />

            {/* Add CSS keyframes for loading spinner */}
            <style>{`
                @keyframes spin {
                    0% { transform: rotate(0deg); }
                    100% { transform: rotate(360deg); }
                }
            `}</style>
        </div>
    );
};

export default ServerSidePreview;