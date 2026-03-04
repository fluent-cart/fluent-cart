const { InspectorControls } = wp.blockEditor;
const { CheckboxControl } = wp.components;
const { RangeControl } = wp.components;
const { SelectControl, ToggleControl } = wp.components;

import EditorPanel from "@/BlockEditor/Components/EditorPanel";
import EditorPanelRow from "@/BlockEditor/Components/EditorPanelRow";
import blocktranslate from "@/BlockEditor/BlockEditorTranslator";
import CustomSelect from "@/BlockEditor/Components/CustomSelect";
import SelectProductModal from "@/BlockEditor/Components/ProductPicker/SelectProductModal.jsx";

const InspectorSettings = ({ attributes, setAttributes, selectedProduct, setSelectedProduct, isInsideProductInfo }) => {

const { carousel_settings } = attributes;

    const {useEffect, useState} = wp.element;

    useEffect(() => {
    }, [attributes, selectedProduct]);

    return (
        <InspectorControls>
            <div className="fct-inspector-control-wrap fct-inspector-control-wrap--product-card">
                <div className="fct-inspector-control-group">
                    <div className="fct-inspector-control-body">
                        <EditorPanel title={blocktranslate('Product')}>
                            {/* select product */}
                            {!isInsideProductInfo && (
                                <EditorPanelRow className="flex-col">
                                    <SelectProductModal
                                        onModalClosed={(selectedProducts) => {
                                            const products = Array.isArray(selectedProducts)
                                            ? selectedProducts.filter(p => p?.ID)
                                            : [];

                                            const productIds = products.map(p => p.ID);

                                            setAttributes({
                                                product_ids: productIds
                                            });

                                            setSelectedProduct(products);
                                        }}
                                        selectedProduct={selectedProduct}
                                        setSelectedProduct={setSelectedProduct}
                                        isMultiple={true}
                                    />

                                    {Array.isArray(attributes.product_ids) && attributes.product_ids.length > 0 && selectedProduct && selectedProduct.length > 0 && (
                                        <div className="fct-selected-products">
                                            <span className="fct-selected-products__label">
                                                {blocktranslate('Selected products')}
                                            </span>

                                            <div className="fct-selected-products__list">
                                                {selectedProduct.map((product) => (
                                                    <span key={product.ID} className="fct-product-chip">
                                                        {product.post_title}
                                                    </span>
                                                ))}
                                            </div>
                                        </div>
                                    )}
                                </EditorPanelRow>
                            )}
                        </EditorPanel>
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

                            {/* Pagination */}
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
