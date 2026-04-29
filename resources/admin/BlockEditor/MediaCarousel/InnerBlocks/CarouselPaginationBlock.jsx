const {
  useBlockProps,
} = wp.blockEditor;

const CarouselPaginationBlock = {
    edit: ({ context }) => {
        const settings = context['fluent-cart/carousel_settings'] || {};
        const isEnabled = settings.pagination !== 'no';
        const paginationType = settings.paginationType || 'bullets';

        const blockProps = useBlockProps({
            className: 'fct-carousel-pagination-editor',
        });

        if (!isEnabled) {
            return (
                <div {...blockProps} style={{ opacity: 0.5 }}></div>
            );
        }

        // Render based on pagination type
        const renderPagination = () => {
            switch (paginationType) {
                case 'fraction':
                    return (
                        <div className="editor-carousel-pagination editor-carousel-pagination--fraction">
                            <span className="fraction-current" style={{
                                fontSize: '18px',
                                fontWeight: '700',
                                color: '#111827'
                            }}>1</span>
                            <span style={{
                                margin: '0 8px',
                                fontSize: '14px',
                                color: '#6b7280'
                            }}>—</span>
                            <span className="fraction-total" style={{
                                fontSize: '14px',
                                fontWeight: '400',
                                color: '#9ca3af'
                            }}>5</span>
                        </div>
                    );

                case 'progress':
                    return (
                        <div className="editor-carousel-pagination editor-carousel-pagination--progress">
                            <div className="progress-bar" style={{ width: '40%' }}></div>
                        </div>
                    );

                case 'segmented':
                    return (
                        <div className="editor-carousel-pagination editor-carousel-pagination--segmented">
                            <div className="progress-segment is-completed"></div>
                            <div className="progress-segment is-active"></div>
                            <div className="progress-segment"></div>
                            <div className="progress-segment"></div>
                            <div className="progress-segment"></div>
                            <span className="segment-counter">1 / 5</span>
                        </div>
                    );

                case 'bullets':
                default:
                    return (
                        <div className="editor-carousel-pagination">
                            <span className="editor-carousel-dot is-active" />
                            <span className="editor-carousel-dot" />
                            <span className="editor-carousel-dot" />
                        </div>
                    );
            }
        };

        return (
            <div {...blockProps}>
                {renderPagination()}
            </div>
        );
    },

    save: () => {
        return null;
    },

    supports: {
        html: false,
    },

    usesContext: [
        'fluent-cart/carousel_settings',
    ],
};

export default CarouselPaginationBlock;
