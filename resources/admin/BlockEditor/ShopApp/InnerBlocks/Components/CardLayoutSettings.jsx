import EditorPanel from '@/BlockEditor/Components/EditorPanel';
import EditorPanelRow from '@/BlockEditor/Components/EditorPanelRow';
import blocktranslate from '@/BlockEditor/BlockEditorTranslator';
import {applyCardPreset, cardPresets, detectCardPreset} from '../ProductCardLayoutPresets';

const {InspectorControls} = wp.blockEditor;
const {FormToggle} = wp.components;
const {useSelect} = wp.data;
const {useState} = wp.element;

// onMasonry is set only in a grid loop (Products, Related Products), where Masonry has a grid to stagger.
export default function CardLayoutSettings({clientId, layout, masonry = false, onMasonry = null}) {
    const blocks = useSelect(select => select('core/block-editor').getBlocks(clientId), [clientId]);
    const selected = detectCardPreset(blocks, layout);
    // Masonry is a switch on every layout here; the layout of that name is kept for the page builders.
    const presets = Object.fromEntries(Object.entries(cardPresets()).filter(([, preset]) => !preset.builder_only));
    const current = presets[selected];
    // The layout just switched to, with what it replaced: Undo restores that, not whatever was edited last.
    const [switched, setSwitched] = useState(null);

    const choose = (name) => {
        const editor = wp.data.select('core/block-editor');
        const card = editor.getBlock?.(clientId);
        const [productsId] = editor.getBlockParentsByBlockName?.(clientId, 'fluent-cart/products', true) || [];
        const before = {
            card: card?.attributes?.template_version === 1 ? card : null,
            innerBlocks: editor.getBlocks(clientId),
            productsId,
            products: productsId ? editor.getBlockAttributes(productsId) : null,
        };
        const undoState = {};
        if (applyCardPreset(name, clientId, undoState)) {
            setSwitched({name, before: {...before, switches: undoState.switches || [], added: undoState.added || []}});
        }
    };
    const undo = () => {
        const {before} = switched;
        const editor = wp.data.dispatch('core/block-editor');
        if (before.card) {
            editor.replaceBlocks(clientId, before.card);
        } else {
            editor.replaceInnerBlocks(clientId, before.innerBlocks, false);
        }
        const changed = presets[switched.name]?.products;
        if (changed && before.productsId && before.products) {
            editor.updateBlockAttributes(before.productsId, Object.fromEntries(Object.keys(changed).map(key => [key, before.products[key]])));
        }
        // Put back the grid/list switches the layout removed, where they were.
        if (before.added.length) editor.removeBlocks(before.added, false);
        before.switches.forEach(({block, parentId, index}) => editor.insertBlocks(block, index, parentId, false));
        setSwitched(null);
    };

    const headingId = 'fct-card-style-' + clientId;
    const masonryId = 'fct-card-masonry-' + clientId;
    const justSwitched = switched && presets[switched.name] && switched.name === selected;

    return <InspectorControls>
        <div className="fct-inspector-control-wrap">
            <div className="fct-inspector-control-group">
                <div className="fct-inspector-control-body">
                    <EditorPanel title={blocktranslate('Layout')}>
                        <EditorPanelRow className="flex-col items-stretch">
                            <div className="fct-card-style">
                                <div className="fct-card-style-head">
                                    <span id={headingId} className="fct-card-style-title">{blocktranslate('Card style')}</span>
                                    {justSwitched && <button type="button" className="fct-card-preset-undo" onClick={undo}>{blocktranslate('Undo')}</button>}
                                </div>
                                {/* The visible Undo has no message of its own; screen readers hear the change. */}
                                {justSwitched && <span className="screen-reader-text" role="status">
                                    {/* translators: %s - card layout name, e.g. Floating */ blocktranslate('Layout changed to %s.', presets[switched.name].label)}
                                </span>}
                                {selected === 'custom' && <p className="fct-card-style-note">{blocktranslate('Current / custom layout')}</p>}
                                <div className="fct-card-preset-picker" role="group" aria-labelledby={headingId}>
                                    {Object.entries(presets).map(([name, preset]) => <button
                                        type="button"
                                        key={name}
                                        aria-pressed={selected === name}
                                        className="fct-card-preset-choice"
                                        onClick={() => choose(name)}
                                    >
                                        <span className={'fct-card-preset-thumb fct-card-preset-thumb--' + name} aria-hidden="true">
                                            <span className="fct-card-preset-thumb-image"/>
                                            <span className="fct-card-preset-thumb-details">
                                                <span className="fct-card-preset-thumb-title"/>
                                                {name === 'compact'
                                                    ? <span className="fct-card-preset-thumb-row"><span className="fct-card-preset-thumb-price"/><span className="fct-card-preset-thumb-button"/></span>
                                                    : <span className="fct-card-preset-thumb-button"/>}
                                            </span>
                                        </span>
                                        <strong>{preset.label}</strong>
                                    </button>)}
                                </div>
                                {current?.description && <p className="fct-card-style-note">{current.description}</p>}
                            </div>
                            {onMasonry && <div className="fct-card-masonry-toggle">
                                <div className="fct-card-masonry-text">
                                    <label htmlFor={masonryId} className="fct-card-masonry-title">{blocktranslate('Masonry grid')}</label>
                                    <span id={masonryId + '-help'} className="fct-card-masonry-help">{blocktranslate('Images keep their own height, so cards stagger.')}</span>
                                </div>
                                <FormToggle
                                    id={masonryId}
                                    checked={masonry}
                                    aria-describedby={masonryId + '-help'}
                                    onChange={(event) => onMasonry(!!event.target.checked)}
                                />
                            </div>}
                        </EditorPanelRow>
                    </EditorPanel>
                </div>
            </div>
        </div>
    </InspectorControls>;
}
