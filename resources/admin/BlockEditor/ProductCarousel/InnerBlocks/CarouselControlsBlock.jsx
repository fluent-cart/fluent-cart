const {
  useBlockProps,
} = wp.blockEditor;
const { useContext } = wp.element;

const CarouselControlsBlock = {
    edit: ({ context }) => {
        const settings = context['fluent-cart/carousel_settings'] || {};
        const isEnabled = settings.arrows !== 'no';

        const blockProps = useBlockProps({
            className: 'fct-carousel-controls-editor',
        });

        if (!isEnabled) {
            return (
                <div {...blockProps} style={{ opacity: 0.5 }}></div>
            );
        }

        return (
            <div {...blockProps}>
                <button className="editor-carousel-nav prev" aria-hidden="true">‹</button>
                <button className="editor-carousel-nav next" aria-hidden="true">›</button>
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

export default CarouselControlsBlock;
