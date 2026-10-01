const {InspectorControls} = wp.blockEditor;
const {Notice, PanelBody} = wp.components;
const {useSelect} = wp.data;
const {useEffect, useRef, useState} = wp.element;

import ReviewProductPanel from "@/BlockEditor/ProductReviews/Components/ReviewProductPanel";
import blocktranslate from "@/BlockEditor/BlockEditorTranslator";
import {applyPreset, defaultPreset, presetCategory, presetForBlocks, presetHelp, presetIsPro, presetOptions} from "@/BlockEditor/ProductReviews/Components/reviewPresets";
import PresetThumb from "@/BlockEditor/ProductReviews/Components/PresetThumb";

// How many cards to a row, matching `grid-cols-2` on .fct-review-preset-grid in
// the stylesheet. Up and down move by a row, so this has to agree with the CSS;
// there is no way to read a grid's column count back out of it.
const PRESET_COLUMNS = 2;

// The container owns the product query and the layout. Display options, star
// colour and per-section toggles still live on the child blocks (Rating
// Summary, Write a Review, Review List) — a layout here is which blocks the
// section is built from, not a second place those settings are kept.
const InspectorSettings = ({attributes, setAttributes, selectedProduct, setSelectedProduct, insideParent, clientId}) => {
    const childBlocks = useSelect((select) => select('core/block-editor').getBlocks(clientId), [clientId]);
    const [activeCategory, setActiveCategory] = useState('all');
    const detectedPreset = presetForBlocks(childBlocks);
    const preset = !childBlocks.length
        ? (attributes.preset || defaultPreset())
        : (detectedPreset === 'custom' ? 'custom' : detectedPreset);

    const onPresetChange = (value, label) => {
        if (value === 'custom') {
            return;
        }

        if (isLocked(value)) {
            setLockedLabel(label);
            return;
        }

        setLockedLabel('');

        // Already the layout in force. Applying it again rebuilds every block
        // in the section to the shape it is already in — the canvas flashes,
        // the editor's selection is thrown away and an undo step is pushed, to
        // arrive exactly where it started.
        if (value === preset) {
            // The stored name can still be behind the blocks: a layout that was
            // recognised from them rather than chosen here was never written
            // down. Clicking the card it matches is a fair moment to record it.
            if (attributes.preset !== value) {
                setAttributes({preset: value});
            }

            return;
        }

        setAttributes({preset: value});
        applyPreset(value, clientId);
    };

    // Roving tabindex. Every card used to be a tab stop, so reaching the panel
    // below this one meant pressing Tab eleven times. One stop for the whole
    // group, arrow keys within it.
    const cardRefs = useRef([]);
    const [focusedIndex, setFocusedIndex] = useState(0);

    // Read per render rather than at import: the localized payload is printed
    // before this script runs, but a missing global must not take the panel
    // down with it.
    const isProActive = !!(window.fluent_cart_product_reviews_data || {}).is_pro;
    const isLocked = (value) => !isProActive && presetIsPro(value);
    const [lockedLabel, setLockedLabel] = useState('');

    // Already translated: the labels come from PHP now, through __(), so a
    // second lookup here would be a miss that happens to fall back correctly.
    const options = presetOptions();
    const visibleOptions = activeCategory === 'all'
        ? options
        : options.filter((option) => presetCategory(option.value) === activeCategory);
    const selectedIndex = visibleOptions.findIndex((option) => option.value === preset);

    // Entering the group lands on the chosen layout rather than on the first
    // card, which is what a radio group does and what makes the current choice
    // findable without reading every card. A custom layout selects nothing, so
    // the first card keeps the stop.
    useEffect(() => {
        setFocusedIndex(selectedIndex > -1 ? selectedIndex : 0);
    }, [selectedIndex]);

    // Filtering by category shortens the list under the cursor.
    useEffect(() => {
        setFocusedIndex((index) => Math.min(index, Math.max(visibleOptions.length - 1, 0)));
    }, [visibleOptions.length]);

    const onPresetKeyDown = (event, index) => {
        const last = visibleOptions.length - 1;
        const moves = {
            ArrowRight: Math.min(index + 1, last),
            ArrowLeft: Math.max(index - 1, 0),
            ArrowDown: Math.min(index + PRESET_COLUMNS, last),
            ArrowUp: Math.max(index - PRESET_COLUMNS, 0),
            Home: 0,
            End: last,
        };

        // hasOwnProperty, not `in`: `in` walks the prototype chain, so a key
        // named for anything on Object.prototype would read as a move.
        if (!Object.prototype.hasOwnProperty.call(moves, event.key)) {
            return;
        }

        event.preventDefault();
        event.stopPropagation();

        const next = moves[event.key];
        setFocusedIndex(next);

        if (cardRefs.current[next]) {
            cardRefs.current[next].focus();
        }

        // Arrow keys only move focus. Applying a preset replaces the current
        // inner blocks, so it must require an explicit activation key or click.
    };

    const onPresetCardKeyDown = (event, index) => {
        if (event.key !== 'Enter' && event.key !== ' ') {
            return;
        }

        event.preventDefault();
        event.stopPropagation();

        const option = visibleOptions[index];
        if (option) {
            onPresetChange(option.value, option.label);
        }
    };

    return (
        <InspectorControls>
            <div className="fct-inspector-control-wrap fct-inspector-control-wrap--product-card fct-inspector-control-wrap--product-reviews">
                <div className="fct-inspector-control-group">
                    <div className="fct-inspector-control-body">

                        {/* Inside Product Info or a product loop that container owns the product */}
                        {!insideParent && (
                            <ReviewProductPanel
                                attributes={attributes}
                                setAttributes={setAttributes}
                                selectedProduct={selectedProduct}
                                setSelectedProduct={setSelectedProduct}
                            />
                        )}
                        
                        <PanelBody title={blocktranslate('Layout')} initialOpen={true}>
                            <p className="fct-review-preset-label">{blocktranslate('Layout preset')}</p>
                            <div className="fct-review-preset-tabs" role="group" aria-label={blocktranslate('Review layout categories')}>
                                {['all', 'list', 'grid', 'carousel', 'photo'].map((category) => (
                                    <button
                                        type="button"
                                        aria-pressed={activeCategory === category}
                                        className={`fct-review-preset-tab${activeCategory === category ? ' is-active' : ''}`}
                                        key={category}
                                        onClick={() => setActiveCategory(category)}
                                    >
                                        {blocktranslate(category === 'all' ? 'All layouts' : category[0].toUpperCase() + category.slice(1))}
                                    </button>
                                ))}
                            </div>
                            {preset === 'custom' && (
                                <div className="fct-review-preset-current">
                                    <span className="fct-review-preset-current-mark">✓</span>
                                    {blocktranslate('Custom layout')}
                                </div>
                            )}
                            {/* A radio group, not a row of toggles: exactly one
                                layout is in force, and aria-pressed described
                                each card as independently on or off. */}
                            <div className="fct-review-preset-grid" role="radiogroup" aria-label={blocktranslate('Review layout presets')}>
                                {visibleOptions.map((option, index) => (
                                    <button
                                        type="button"
                                        role="radio"
                                        aria-checked={preset === option.value}
                                        /* aria-disabled, never disabled: a
                                           disabled button cannot be focused,
                                           which would drop these out of the
                                           arrow-key grid and out of a screen
                                           reader's count of the group. */
                                        aria-disabled={isLocked(option.value) || undefined}
                                        tabIndex={index === focusedIndex ? 0 : -1}
                                        ref={(node) => {
                                            cardRefs.current[index] = node;
                                        }}
                                        onKeyDown={(event) => {
                                            onPresetKeyDown(event, index);
                                            onPresetCardKeyDown(event, index);
                                        }}
                                        className={[
                                            'fct-review-preset-card',
                                            preset === option.value ? 'is-selected' : '',
                                            isLocked(option.value) ? 'is-locked' : '',
                                        ].filter(Boolean).join(' ')}
                                        key={option.value}
                                        onClick={() => onPresetChange(option.value, option.label)}
                                    >
                                        <PresetThumb value={option.value}/>
                                        <span className="fct-review-preset-card-name">{option.label}</span>
                                        {/* Part of the card's accessible name,
                                            so it is announced with it rather
                                            than left as a visual-only mark. */}
                                        {isLocked(option.value) && (
                                            <span className="fct-review-preset-pro">{blocktranslate('Pro')}</span>
                                        )}
                                        {preset === option.value && <span className="fct-review-preset-check" aria-hidden="true">✓</span>}
                                    </button>
                                ))}
                            </div>
                            {lockedLabel && (
                                <div className="fct-review-preset-locked">
                                    <Notice status="warning" isDismissible onRemove={() => setLockedLabel('')}>
                                        {blocktranslate('%s is a Pro layout. Upgrade to FluentCart Pro to build the section from it.', lockedLabel)}
                                    </Notice>
                                </div>
                            )}
                            <p className="fct-review-preset-help">
                                {preset === 'custom'
                                    ? blocktranslate('Custom layout. Choose a preset to replace it with a starter layout.')
                                    : presetHelp(preset)}
                            </p>
                            <p className="fct-preset-note">
                                {blocktranslate('Builds the section from these blocks. Anything arranged inside it is replaced.')}
                            </p>
                        </PanelBody>

                    </div>
                </div>
            </div>
        </InspectorControls>
    );
};

export default InspectorSettings;
