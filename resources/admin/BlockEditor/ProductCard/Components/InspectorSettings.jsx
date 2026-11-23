const { InspectorControls } = wp.blockEditor;
const { RangeControl, SelectControl, __experimentalNumberControl: NumberControl } = wp.components;
import EditorPanel from "@/BlockEditor/Components/EditorPanel";
import EditorPanelRow from "@/BlockEditor/Components/EditorPanelRow";
import blocktranslate from "@/BlockEditor/BlockEditorTranslator";
import SegmentedToggle from "@/BlockEditor/Components/SegmentedToggle";
import {priceFormatPreview} from "@/BlockEditor/ProductCard/priceFormatPreview";
import ProductSourceControl from "@/BlockEditor/Components/ProductPicker/ProductSourceControl";


const CARD_WIDTH_MIN = 216;
const CARD_WIDTH_MAX = 1000;
const CARD_WIDTH_STEP = 10;

// ProductCardBlockEditor::renderTemplate(): '100%' fills the column; anything else is pixels, clamped.
const isFillColumn = (cardWidth) => cardWidth === '100%';
const fixedWidthOf = (cardWidth) => Math.min(CARD_WIDTH_MAX, Math.max(CARD_WIDTH_MIN, parseInt(cardWidth, 10) || CARD_WIDTH_MIN));

// The slider and a number box with its unit. The box keeps what is typed and saves only a value in range.
const CardWidthField = ({value, onChange}) => {
    const {useState, useEffect} = wp.element;
    const [draft, setDraft] = useState(String(value));
    useEffect(() => setDraft(String(value)), [value]);

    return (
        <div className="fct-card-width">
            <RangeControl
                className="fct-card-width__slider"
                label={blocktranslate('Card width slider')}
                hideLabelFromVision
                value={value}
                onChange={(next) => {
                    if (typeof next === 'number') {
                        onChange(next);
                    }
                }}
                min={CARD_WIDTH_MIN}
                max={CARD_WIDTH_MAX}
                step={CARD_WIDTH_STEP}
                withInputField={false}
                __nextHasNoMarginBottom
            />
            <NumberControl
                className="fct-card-width__input"
                label={blocktranslate('Card width in pixels')}
                hideLabelFromVision
                value={draft}
                min={CARD_WIDTH_MIN}
                max={CARD_WIDTH_MAX}
                step={1}
                suffix={<span className="fct-card-width__unit">{blocktranslate('px')}</span>}
                onChange={(next) => {
                    setDraft(next);
                    const number = parseInt(next, 10);
                    if (!Number.isNaN(number) && number >= CARD_WIDTH_MIN && number <= CARD_WIDTH_MAX) {
                        onChange(number);
                    }
                }}
                onBlur={() => setDraft(String(value))}
                __next40pxDefaultSize
            />
        </div>
    );
};

// displayedProduct: the product the card shows, when that is not selectedProduct (a page or loop product in Default mode).
const InspectorSettings = ({ attributes, setAttributes, selectedProduct, setSelectedProduct, displayedProduct }) => {
    const priceProduct = displayedProduct || selectedProduct;



    return (
        <InspectorControls>
            <div className="fct-inspector-control-wrap fct-inspector-control-wrap--product-card">
                <div className="fct-inspector-control-group">
                    <div className="fct-inspector-control-body">
                        <EditorPanel title={blocktranslate('Product')}>

                            <EditorPanelRow className="flex-col">
                                <ProductSourceControl
                                    queryType={attributes.query_type}
                                    onQueryTypeChange={(value) => setAttributes({query_type: value})}
                                    productId={attributes.product_id ? String(attributes.product_id) : ''}
                                    selectedProduct={selectedProduct}
                                    setSelectedProduct={setSelectedProduct}
                                    onSelectionConfirmed={(product) => {
                                        // A product that failed to load has no id; confirming it must not clear the saved one.
                                        if (product && !product.ID) {
                                            return;
                                        }
                                        setAttributes({product_id: product ? String(product.ID) : ''});
                                    }}
                                    onRemove={() => {
                                        setAttributes({product_id: ''});
                                        setSelectedProduct({});
                                    }}
                                />
                            </EditorPanelRow>

                            {/* Price format */}
                            <EditorPanelRow className="flex-col fct-card-setting">
                                <SelectControl
                                    label={blocktranslate('Price Format')}
                                    labelPosition="top"
                                    className="fct-sidebar-select"
                                    value={attributes.price_format}
                                    options={[
                                        {label: blocktranslate('Starts from'), value: 'starts_from'},
                                        {label: blocktranslate('Range'), value: 'range'},
                                    ]}
                                    onChange={(value) => setAttributes({price_format: value})}
                                    __nextHasNoMarginBottom
                                    __next40pxDefaultSize
                                />
                                {priceFormatPreview(priceProduct, attributes.price_format) && (
                                    <p className="fct-card-setting__preview">
                                        {blocktranslate('Shows as:')}{' '}
                                        <strong>{priceFormatPreview(priceProduct, attributes.price_format)}</strong>
                                    </p>
                                )}
                            </EditorPanelRow>

                            {/* Card width */}
                            <EditorPanelRow className="flex-col fct-card-setting">
                                <SegmentedToggle
                                    label={blocktranslate('Card width')}
                                    options={[
                                        {value: 'full', label: blocktranslate('Fill column')},
                                        {value: 'fixed', label: blocktranslate('Fixed')},
                                    ]}
                                    value={isFillColumn(attributes.card_width) ? 'full' : 'fixed'}
                                    onChange={(value) => setAttributes({card_width: value === 'full' ? '100%' : CARD_WIDTH_MIN})}
                                />

                                {!isFillColumn(attributes.card_width) && (
                                    <CardWidthField
                                        value={fixedWidthOf(attributes.card_width)}
                                        onChange={(value) => setAttributes({card_width: value})}
                                    />
                                )}
                            </EditorPanelRow>

                        </EditorPanel>
                    </div>
                </div>

            </div>

        </InspectorControls>
    );
};

export default InspectorSettings;
