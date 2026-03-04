const { InspectorControls } = wp.blockEditor;
const { CheckboxControl } = wp.components;
const { RangeControl } = wp.components;
const { SelectControl, ToggleControl, __experimentalToggleGroupControl, __experimentalToggleGroupControlOption } = wp.components;
const ToggleGroupControl = __experimentalToggleGroupControl;
const ToggleGroupControlOption = __experimentalToggleGroupControlOption;

import EditorPanel from "@/BlockEditor/Components/EditorPanel";
import EditorPanelRow from "@/BlockEditor/Components/EditorPanelRow";
import blocktranslate from "@/BlockEditor/BlockEditorTranslator";
import SelectVariationModal from "@/BlockEditor/Components/ProductPicker/SelectVariationModal.jsx";

const InspectorSettings = ({ attributes, setAttributes, selectedProduct, setSelectedProduct, selectedVariations, setSelectedVariations, isInsideProductInfo }) => {
    
const { carousel_settings } = attributes;

    return (
        <InspectorControls>
            <div className="fct-inspector-control-wrap fct-inspector-control-wrap--product-card">
                <div className="fct-inspector-control-group">
                    <div className="fct-inspector-control-body">
                            {/* select product */}
                            {!isInsideProductInfo && (
                                <EditorPanel title={blocktranslate('Product')}>
                                    <EditorPanelRow>
                                        <ToggleGroupControl
                                            isBlock
                                            label={blocktranslate('Query type')}
                                            value={attributes.query_type}
                                            onChange={(value) => {
                                                setAttributes({query_type: value});
                                            }}
                                        >
                                            <ToggleGroupControlOption value="default" label={blocktranslate('Default')} />
                                            <ToggleGroupControlOption value="custom" label={blocktranslate('Custom')} />
                                        </ToggleGroupControl>
                                    </EditorPanelRow>
                                    {attributes.query_type === 'custom' && (
                                        <EditorPanelRow className="flex-col">
                                            <SelectVariationModal
                                                setAttributes={setAttributes}
                                                preSelectedVariations={selectedVariations}
                                                onModalClosed={(selectedVariations) => {
                                                    // let variations = {...selectedVariations};
                                                    // setAttributes({variations: Object.keys(variations)});
                                                    // setAttributes({variation_ids: Object.keys(variations)});
                                                    // setAttributes({product_id:Object.values(selectedVariations)[0].post_id});
                                                    // setSelectedVariations(variations);

                                                    // Empty selection → reset everything
                                                    if (!selectedVariations || Object.keys(selectedVariations).length === 0) {
                                                        setAttributes({
                                                            variation_ids: [],
                                                            product_id: '',
                                                        });
                                                        setSelectedVariations({});
                                                        return;
                                                    }

                                                    const variations = { ...selectedVariations };

                                                    // variation_ids (numbers, not strings)
                                                    const variationIds = Object.keys(variations).map(id => Number(id));

                                                    // product_id from FIRST variant
                                                    const firstVariant = Object.values(variations)[0];
                                                    const productId = firstVariant?.post_id ?? '';

                                                    setAttributes({
                                                        variation_ids: variationIds,
                                                        product_id: productId,
                                                    });

                                                    // sync local state
                                                    setSelectedVariations(variations);
                                                }}
                                                selectedVariations={selectedVariations}
                                                setSelectedVariations={setSelectedVariations}
                                                button={true}
                                                isMultiple={true}
                                            />
                                            
                                            {selectedProduct && selectedProduct.length > 0 && (
                                                <div className="fct-selected-products">
                                                    <span className="fct-selected-products__label">
                                                        {blocktranslate('Selected products')}
                                                    </span>

                                                    <div className="fct-selected-products__list">
                                                        {selectedProduct.map((product) => (
                                                            <div
                                                                key={product.ID}
                                                                className="fct-product-chip-group"
                                                            >
                                                                {/* Parent chip */}
                                                                <span className="fct-product-chip fct-product-chip--parent">
                                                                    {product.post_title}
                                                                </span>

                                                                {/* Child chips */}
                                                                {product?.detail?.variation_type === "simple_variations" && Array.isArray(product.variants) &&
                                                                    product.variants.length > 0 && (
                                                                        <div className="fct-variation-chip-group">
                                                                            {product.variants.map((variant) => (
                                                                                <span
                                                                                    key={variant.id}
                                                                                    className="fct-variation-chip"
                                                                                >
                                                                                    {variant.variation_title}
                                                                                </span>
                                                                            ))}
                                                                        </div>
                                                                    )}
                                                            </div>
                                                        ))}
                                                    </div>
                                                </div>
                                            )}

                                        </EditorPanelRow>
                                    )}
                                </EditorPanel>
                            )}
                        <EditorPanel title={blocktranslate('Layout')}>
                            {/* Slides to show */}
                            <EditorPanelRow>
                                <span className="fct-inspector-control-label">
                                    {blocktranslate('Slides per view')}
                                </span>
                                <RangeControl
                                    value={carousel_settings.slidesToShow}
                                    onChange={(val) =>
                                        setAttributes({
                                            carousel_settings: {
                                                ...carousel_settings,
                                                slidesToShow: val,
                                            },
                                        })
                                    }
                                    min={1}
                                    max={6}
                                />
                            </EditorPanelRow>
                        </EditorPanel>
                        <EditorPanel title={blocktranslate('Behavior')}>
                            <p className="fct-panel-subtitle">
                                {blocktranslate('Carousel interaction & animation')}
                            </p>
                            {/* Autoplay */}
                            <EditorPanelRow>
                                <SelectControl
                                    label="Autoplay"
                                    value={carousel_settings.autoplay}
                                    options={[
                                        { label: 'Disabled', value: 'no' },
                                        { label: 'Always', value: 'yes' },
                                        { label: 'On Hover', value: 'hover' },
                                    ]}
                                    onChange={(value) => {
                                        setAttributes({
                                            carousel_settings: {
                                                ...carousel_settings,
                                                autoplay: value,
                                            },
                                        });
                                    }}
                                />
                            </EditorPanelRow>
                            
                            {carousel_settings.autoplay === 'hover' && carousel_settings.slidesToShow > 1 && (
                                <p className="fct-inspector-hint">
                                    Hover autoplay works best with single slide view
                                </p>
                            )}

                            {['yes', 'hover'].includes(carousel_settings.autoplay) && (
                                <EditorPanelRow className="fct-nested-control">
                                    <RangeControl
                                        label="Autoplay Delay (ms)"
                                        help="Time between slides in milliseconds"
                                        value={carousel_settings.autoplayDelay || 3000}
                                        min={300}
                                        max={10000}
                                        step={100}
                                        onChange={(value) =>
                                            setAttributes({
                                                carousel_settings: {
                                                    ...carousel_settings,
                                                    autoplayDelay: value,
                                                },
                                            })
                                        }
                                    />
                                </EditorPanelRow>
                            )}

                            <div className="fct-inspector-divider" />

                            {/* Arrows */}
                            <EditorPanelRow>
                                <ToggleControl
                                    label="Show arrows"
                                    checked={carousel_settings.arrows === 'yes'}
                                    onChange={(value) =>
                                        setAttributes({
                                            carousel_settings: {
                                                ...carousel_settings,
                                                arrows: value ? 'yes' : 'no',
                                            },
                                        })
                                    }
                                />
                            </EditorPanelRow>

                            {carousel_settings.arrows === 'yes' && (
                                <EditorPanelRow className="fct-nested-control">
                                <SelectControl
                                    label="Arrow Size"
                                    value={carousel_settings.arrowsSize}
                                    options={[
                                        { label: 'Small', value: 'sm' },
                                        { label: 'Medium', value: 'md' },
                                        { label: 'Large', value: 'lg' },
                                    ]}
                                    onChange={(value) =>
                                        setAttributes({
                                            carousel_settings: {
                                                ...carousel_settings,
                                                arrowsSize: value,
                                            },
                                        })
                                    }
                                />
                                </EditorPanelRow>
                            )}

                            <div className="fct-inspector-divider" />

                            {/* pagination */}
                            <EditorPanelRow>
                                <ToggleControl
                                    label="Show Pagination"
                                    checked={carousel_settings.pagination === 'yes'}
                                    onChange={(value) =>
                                        setAttributes({
                                            carousel_settings: {
                                                ...carousel_settings,
                                                pagination: value ? 'yes' : 'no',
                                            },
                                        })
                                    }
                                />
                            </EditorPanelRow>

                            {carousel_settings.pagination === 'yes' && (
                                <EditorPanelRow className="fct-nested-control">
                                    <SelectControl
                                        label="Pagination Type"
                                        value={carousel_settings.paginationType}
                                        options={[
                                            { label: 'Bullets', value: 'bullets' },
                                            { label: 'Fraction', value: 'fraction' },
                                            { label: 'Progress Bar', value: 'progress' },
                                            { label: 'Segmented (Modern)', value: 'segmented' },
                                        ]}
                                        onChange={(value) =>
                                            setAttributes({
                                                carousel_settings: {
                                                    ...carousel_settings,
                                                    paginationType: value,
                                                },
                                            })
                                        }
                                    />
                                </EditorPanelRow>
                            )}

                            <div className="fct-inspector-divider" />

                            {/* Infinite loop */}
                            <EditorPanelRow>
                                <CheckboxControl
                                    label={blocktranslate('Infinite loop')}
                                    checked={carousel_settings.infinite === 'yes'}
                                    onChange={(isChecked) =>
                                        setAttributes({
                                            carousel_settings: {
                                                ...carousel_settings,
                                                infinite: isChecked ? 'yes' : 'no',
                                            },
                                        })
                                    }
                                />
                            </EditorPanelRow>
                        </EditorPanel>
                    </div>
                </div>
            </div>
        </InspectorControls>
    );
};

export default InspectorSettings;