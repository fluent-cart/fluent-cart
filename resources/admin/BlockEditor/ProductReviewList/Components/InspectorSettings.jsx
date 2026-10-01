const {InspectorControls} = wp.blockEditor;
const {
    Notice,
    RangeControl,
    SelectControl,
    ToggleControl,
    __experimentalToggleGroupControl: ToggleGroupControl,
    __experimentalToggleGroupControlOption: ToggleGroupControlOption,
} = wp.components;
const {useState} = wp.element;

import EditorPanel from "@/BlockEditor/Components/EditorPanel";
import EditorPanelRow from "@/BlockEditor/Components/EditorPanelRow";
import ReviewProductPanel from "@/BlockEditor/ProductReviews/Components/ReviewProductPanel";
import blocktranslate from "@/BlockEditor/BlockEditorTranslator";

/**
 * What is left for the list itself to decide.
 *
 * The per-field toggles are gone: every field is its own block now, so removing
 * one is how a field is turned off, and a toggle beside it would be a second
 * switch for the same thing that disagrees with what List View shows. Star
 * colour moved onto the Review Rating block, Reviews Per Page and Pagination
 * Type onto the Review Pagination block, Default Sort onto the Review Sorting
 * block — each setting sits on the thing it changes. Show Sort Controls went
 * with them: deleting the Review Sorting block is that switch now.
 *
 * What is left is the product the list queries, which is the list's own.
 *
 * A list an editor empties of every block falls back to the fixed row, which
 * is also what the shortcode and the single-product template draw. It has no
 * per-field settings of its own to read — it draws the whole row.
 */
const SLIDER_DEFAULTS = {
    autoplay: 'no',
    autoplayDelay: 3000,
    arrows: 'yes',
    arrowsSize: 'md',
    arrowsPosition: 'overlap',
    pagination: 'no',
    paginationType: 'bullets',
    infinite: 'no',
};

/**
 * The name of a mode this site cannot draw, for the notice that says so.
 *
 * Written out rather than looked up, so the strings stay where the extractor
 * can see them.
 */
const lockedModeLabel = (value) => {
    if (value === 'grid') {
        return blocktranslate('Grid');
    }

    if (value === 'masonry') {
        return blocktranslate('Masonry');
    }

    return blocktranslate('Slider');
};

const InspectorSettings = ({attributes, setAttributes, selectedProduct, setSelectedProduct, insideParent}) => {
    const slider = {...SLIDER_DEFAULTS, ...(attributes.sliderSettings || {})};
    const setSlider = (patch) => setAttributes({sliderSettings: {...slider, ...patch}});

    // Grid, slider and masonry belong to Pro. The control is dimmed to the one
    // mode that will actually be drawn rather than left showing a choice the
    // page then ignores — a saved 'grid' on a site that has lapsed renders as a
    // list, so the editor has to say list too.
    const isProActive = !!(window.fluent_cart_product_review_list_data || {}).is_pro;
    const [lockedMode, setLockedMode] = useState('');
    // ToggleGroupControl keeps its own idea of which option is pressed, so
    // refusing a change is not enough — the button stays lit and the panel says
    // Grid while the page draws a list. Remounting on a refusal is what puts it
    // back on the mode that will actually render.
    const [viewModeKey, setViewModeKey] = useState(0);
    const savedViewMode = attributes.viewMode || 'list';
    const effectiveViewMode = isProActive ? savedViewMode : 'list';

    const onViewModeChange = (value) => {
        if (!isProActive && value !== 'list') {
            // Left unsaved on purpose: storing a mode that cannot render would
            // put the block one upgrade away from silently changing layout.
            setLockedMode(lockedModeLabel(value));
            setViewModeKey((key) => key + 1);

            return;
        }

        setLockedMode('');
        setAttributes({viewMode: value});
    };

    return (
        <InspectorControls>
            <div className="fct-inspector-control-wrap fct-inspector-control-wrap--product-card fct-inspector-control-wrap--review-list">
                <div className="fct-inspector-control-group">
                    <div className="fct-inspector-control-body">

                        {/* Inside the Product Reviews container the parent owns the product */}
                        {!insideParent && (
                            <ReviewProductPanel
                                attributes={attributes}
                                setAttributes={setAttributes}
                                selectedProduct={selectedProduct}
                                setSelectedProduct={setSelectedProduct}
                            />
                        )}

                        <EditorPanel title={blocktranslate('Layout')}>
                            <EditorPanelRow>
                                {/* One word a button. "Grid (Pro)" wrapped onto
                                    a second line and left the control two rows
                                    tall with the qualifier reading as its own
                                    label. Nothing marks the locked modes up
                                    front: choosing one says so, and a standing
                                    line of small print under a control most
                                    people never open is read once and then
                                    becomes furniture. */}
                                <ToggleGroupControl
                                    key={viewModeKey}
                                    isBlock
                                    label={blocktranslate('View Mode')}
                                    value={effectiveViewMode}
                                    onChange={onViewModeChange}
                                >
                                    <ToggleGroupControlOption value="list" label={blocktranslate('List')}/>
                                    <ToggleGroupControlOption value="grid" label={blocktranslate('Grid')}/>
                                    <ToggleGroupControlOption value="masonry" label={blocktranslate('Masonry')}/>
                                    <ToggleGroupControlOption value="slider" label={blocktranslate('Slider')}/>
                                </ToggleGroupControl>
                            </EditorPanelRow>

                            {/* Outside the row on purpose. A control row is a
                                flex line — label one side, control the other —
                                so a notice placed inside it became a second
                                column beside the buttons, squeezed to one word
                                a line and spilling out of the sidebar. Here it
                                is an ordinary block under the control. */}
                            {lockedMode && (
                                <div className="fct-view-mode-notice">
                                    <Notice status="warning" isDismissible onRemove={() => setLockedMode('')}>
                                        {blocktranslate('%s view is a Pro feature. The list stays as a single column without FluentCart Pro.', lockedMode)}
                                    </Notice>
                                </div>
                            )}

                            {/* Every column mode takes a count; only the ones
                                that page mention rounding, which a slider does
                                not do. */}
                            {['grid', 'masonry', 'slider'].includes(effectiveViewMode) && (
                                <EditorPanelRow>
                                    <RangeControl
                                        label={effectiveViewMode === 'slider'
                                            ? blocktranslate('Reviews Per Slide')
                                            : blocktranslate('Reviews Per Row')}
                                        help={effectiveViewMode === 'slider'
                                            ? blocktranslate('Narrow screens always show one per slide')
                                            : blocktranslate('Reviews per page is rounded down to whole rows. Narrow screens always show one per row.')}
                                        value={attributes.gridColumns || 2}
                                        onChange={(value) => setAttributes({gridColumns: value})}
                                        min={2}
                                        max={4}
                                        step={1}
                                    />
                                </EditorPanelRow>
                            )}
                        </EditorPanel>

                        {/* Mirrors the Product Carousel's Behavior panel. */}
                        {effectiveViewMode === 'slider' && (
                            <EditorPanel title={blocktranslate('Behavior')}>
                                <p className="fct-panel-subtitle">
                                    {blocktranslate('Slider interaction & animation')}
                                </p>

                                <EditorPanelRow>
                                    <SelectControl
                                        label={blocktranslate('Autoplay')}
                                        value={slider.autoplay}
                                        options={[
                                            {label: blocktranslate('Disabled'), value: 'no'},
                                            {label: blocktranslate('Always'), value: 'yes'},
                                            {label: blocktranslate('On Hover'), value: 'hover'},
                                        ]}
                                        onChange={(value) => setSlider({autoplay: value})}
                                    />
                                </EditorPanelRow>

                                {['yes', 'hover'].includes(slider.autoplay) && (
                                    <EditorPanelRow className="fct-nested-control">
                                        <RangeControl
                                            label={blocktranslate('Autoplay Delay (ms)')}
                                            help={blocktranslate('Time between slides in milliseconds')}
                                            value={slider.autoplayDelay}
                                            min={300}
                                            max={10000}
                                            step={100}
                                            onChange={(value) => setSlider({autoplayDelay: value})}
                                        />
                                    </EditorPanelRow>
                                )}

                                <div className="fct-inspector-divider"/>

                                <EditorPanelRow>
                                    <ToggleControl
                                        label={blocktranslate('Show arrows')}
                                        checked={slider.arrows === 'yes'}
                                        onChange={(value) => setSlider({arrows: value ? 'yes' : 'no'})}
                                    />
                                </EditorPanelRow>

                                {slider.arrows === 'yes' && (
                                    <EditorPanelRow className="fct-nested-control">
                                        <SelectControl
                                            label={blocktranslate('Arrow Size')}
                                            value={slider.arrowsSize}
                                            options={[
                                                {label: blocktranslate('Small'), value: 'sm'},
                                                {label: blocktranslate('Medium'), value: 'md'},
                                                {label: blocktranslate('Large'), value: 'lg'},
                                            ]}
                                            onChange={(value) => setSlider({arrowsSize: value})}
                                        />
                                    </EditorPanelRow>
                                )}

                                {slider.arrows === 'yes' && (
                                    <EditorPanelRow className="fct-nested-control">
                                        <SelectControl
                                            label={blocktranslate('Arrow Placement')}
                                            help={blocktranslate('On the reviews saves space. Beside the reviews keeps every review clear. Below the reviews works well in narrow columns where side arrows have less room.')}
                                            value={slider.arrowsPosition}
                                            options={[
                                                {label: blocktranslate('On the reviews'), value: 'overlap'},
                                                {label: blocktranslate('Beside the reviews'), value: 'outside'},
                                                {label: blocktranslate('Below the reviews'), value: 'bottom'},
                                            ]}
                                            onChange={(value) => setSlider({arrowsPosition: value})}
                                        />
                                    </EditorPanelRow>
                                )}

                                <div className="fct-inspector-divider"/>

                                <EditorPanelRow>
                                    <ToggleControl
                                        label={blocktranslate('Show pagination')}
                                        checked={slider.pagination === 'yes'}
                                        onChange={(value) => setSlider({pagination: value ? 'yes' : 'no'})}
                                    />
                                </EditorPanelRow>

                                {slider.pagination === 'yes' && (
                                    <EditorPanelRow className="fct-nested-control">
                                        <SelectControl
                                            label={blocktranslate('Pagination Type')}
                                            value={slider.paginationType}
                                            options={[
                                                {label: blocktranslate('Dots'), value: 'bullets'},
                                                {label: blocktranslate('Fraction'), value: 'fraction'},
                                                {label: blocktranslate('Progress Bar'), value: 'progressbar'},
                                                {label: blocktranslate('Segmented'), value: 'segmented'},
                                            ]}
                                            onChange={(value) => setSlider({paginationType: value})}
                                        />
                                    </EditorPanelRow>
                                )}


                                <div className="fct-inspector-divider"/>

                                <EditorPanelRow>
                                    <ToggleControl
                                        label={blocktranslate('Infinite loop')}
                                        checked={slider.infinite === 'yes'}
                                        onChange={(value) => setSlider({infinite: value ? 'yes' : 'no'})}
                                    />
                                </EditorPanelRow>
                            </EditorPanel>
                        )}

                    </div>
                </div>
            </div>
        </InspectorControls>
    );
};

export default InspectorSettings;
