import InspectorSettings from "@/BlockEditor/ProductSwatches/Components/InspectorSettings";
import {Swatches} from "@/BlockEditor/Icons";
import ErrorBoundary from "@/BlockEditor/Components/ErrorBoundary";
import useProductSwatchPreview from '@/BlockEditor/ProductSwatches/useProductSwatchPreview';
import blocktranslate from "@/BlockEditor/BlockEditorTranslator";

const {useBlockProps} = wp.blockEditor;
const {registerBlockType} = wp.blocks;

const blockEditorData = window.fluent_cart_product_swatches_data;

const PreviewSwatch = ({group, term, pressed}) => {
    let fill = <span className="fct-card-swatch-text">{term.title}</span>;
    if (group.type === 'color') {
        fill = <span className="fct-card-swatch-fill" style={{backgroundColor: term.color || undefined}}></span>;
    } else if (group.type === 'image' && term.image) {
        fill = <img className="fct-card-swatch-fill" src={term.image} alt=""/>;
    }
    // data-sold-out draws Cross out as on the page.
    return <span className="fct-card-swatch" aria-pressed={pressed ? 'true' : 'false'} data-sold-out={term.soldOut ? '' : undefined}>{fill}</span>;
};

registerBlockType(blockEditorData.slug + '/' + blockEditorData.name, {
    apiVersion: 3,
    title: blockEditorData.title,
    description: blockEditorData.description,
    icon: {
        src: Swatches,
    },
    category: 'fluent-cart',
    attributes: {
        product_id: {type: 'number', default: 0},
        group_ids: {type: 'array', default: []},
        // No default: blocks saved before it keep "no pick shows every attribute".
        show_other_groups: {type: 'boolean'},
        sold_out_display: {type: 'string', default: 'normal', enum: ['normal', 'cross', 'hide']},
        // Attributes the merchant unticked: kept in the list, and left out of the other attributes.
        hidden_group_ids: {type: 'array', default: []},
        max_visible: {type: 'number', default: 3},
        show_label: {type: 'boolean', default: false},
        show_group_name: {type: 'boolean', default: false},
        advanced_enabled: {type: 'boolean', default: true},
        simple_enabled: {type: 'boolean', default: true},
        simple_label: {type: 'string', default: ''},
        simple_max_visible: {type: 'number', default: 4},
        simple_show_label: {type: 'boolean', default: false},
        // No default: blocks saved before the split keep following show_label.
        simple_show_selected: {type: 'boolean'},
        selected_position: {type: 'string', default: 'name', enum: ['name', 'below']},
        simple_selected_position: {type: 'string', default: 'name', enum: ['name', 'below']},
    },
    // The loops hand each card's product over as block context.
    usesContext: ['ID', 'detail', 'variants'],
    // Only allowed inside product loops (see the PHP ancestor list), so it always shows the loop's product.
    edit: ({attributes, setAttributes, context}) => {
        const blockProps = useBlockProps({className: 'fct-card-swatch-rows'});
        const productId = attributes.product_id || context?.ID;
        const preview = useProductSwatchPreview(productId, [attributes]);
        const rows = preview?.previews?.[0]?.rows || [];
        const selected = preview?.previews?.[0]?.selected || '';
        const loading = !!productId && !preview;

        return (
            <div {...blockProps}>
                <ErrorBoundary>
                    <InspectorSettings
                        attributes={attributes}
                        setAttributes={setAttributes}
                        attributeGroups={blockEditorData.attribute_groups || []}
                    />

                    {/* Kept visible so the block can still be selected on a card without swatches. */}
                    {loading && <span role="status">{blocktranslate('Loading...')}</span>}
                    {preview?.error && <span role="status">{preview.error}</span>}
                    {!loading && !preview?.error && !rows.length && (
                        <span className="fct-card-swatch-empty">
                            {blocktranslate('No swatches for this product.')}
                        </span>
                    )}

                    {/* A preview, not controls: kept out of the accessibility tree. */}
                    {rows.map((group) => (
                        <div key={group.id} className={'fct-card-swatches is-' + group.type} aria-hidden="true">
                            {(group.showName || group.selectedName) && (
                                <div className="fct-card-swatch-heading">
                                    {group.showName && (
                                        <span className="fct-card-swatch-group-name">{group.title}</span>
                                    )}
                                    {group.selectedName && (
                                        <span className="fct-card-swatch-label">{group.selectedName}</span>
                                    )}
                                </div>
                            )}
                            <div className="fct-card-swatch-list">
                                {group.terms.map((term) => (
                                    <PreviewSwatch key={term.id} group={group} term={term} pressed={term.pressed}/>
                                ))}
                                {group.more && (
                                    <span className="fct-card-swatch-more">{group.more}</span>
                                )}
                            </div>
                        </div>
                    ))}
                    {selected && <div className="fct-card-swatch-selected" aria-hidden="true">{selected}</div>}
                </ErrorBoundary>
            </div>
        );
    },

    save: function () {
        return null;
    },

    supports: {
        html: false,
        align: ['left', 'center', 'right'],
        spacing: {
            margin: true,
            padding: true,
        },
    },
});
