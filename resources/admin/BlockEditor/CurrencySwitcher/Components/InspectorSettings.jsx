import blocktranslate from "@/BlockEditor/BlockEditorTranslator";

// The button and the list offer the same label choices; only the default differs
const labelOptions = (defaultValue) => [
    {label: blocktranslate('Name and code'), value: defaultValue === 'name_code' ? '' : 'name_code'},
    {label: blocktranslate('Currency name'), value: 'name'},
    {label: blocktranslate('Code'), value: defaultValue === 'code' ? '' : 'code'},
    {label: blocktranslate('Sign only'), value: 'symbol'},
    {label: blocktranslate('Flag only'), value: 'flag'},
];

const {InspectorControls} = wp.blockEditor;
const {PanelBody, SelectControl, ToggleControl} = wp.components;

// Same options as [fluent_cart_currency_switcher display="…" symbol="yes" flag="no" flag_position="right" list_display="…" list_flag="no" list_symbol="yes" list_flag_position="right"]
const InspectorSettings = ({attributes, setAttributes}) => {
    const {display, symbol, flag, flag_position, list_display, list_flag, list_symbol, list_flag_position} = attributes;
    // An unset sign follows the display: only the default (code) carries it
    const showsSymbol = symbol === 'yes' || (symbol === '' && display === '');

    return (
        <InspectorControls>
            <PanelBody title={blocktranslate('Switcher Settings')} initialOpen={true}>
                <SelectControl
                    label={blocktranslate('Display')}
                    value={display === 'code' ? '' : display}
                    options={labelOptions('code')}
                    onChange={(value) => setAttributes({display: value})}
                    __nextHasNoMarginBottom
                />
                {/* The flag is the whole label, so sign and flag settings don't apply */}
                {display !== 'symbol' && display !== 'flag' && (
                    <ToggleControl
                        label={blocktranslate('Show sign')}
                        checked={showsSymbol}
                        onChange={(checked) => setAttributes({symbol: checked ? 'yes' : 'no'})}
                        __nextHasNoMarginBottom
                    />
                )}
                {display !== 'flag' && (
                    <ToggleControl
                        label={blocktranslate('Show flag')}
                        checked={flag !== 'no'}
                        onChange={(checked) => setAttributes({flag: checked ? 'yes' : 'no'})}
                        __nextHasNoMarginBottom
                    />
                )}
                {display !== 'flag' && flag !== 'no' && (
                    <SelectControl
                        label={blocktranslate('Flag position')}
                        value={flag_position}
                        options={[
                            {label: blocktranslate('Left'), value: 'left'},
                            {label: blocktranslate('Right'), value: 'right'},
                        ]}
                        onChange={(value) => setAttributes({flag_position: value})}
                        __nextHasNoMarginBottom
                    />
                )}
            </PanelBody>
            <PanelBody title={blocktranslate('Dropdown List')} initialOpen={false}>
                <SelectControl
                    label={blocktranslate('Display')}
                    value={list_display === 'name_code' ? '' : list_display}
                    options={labelOptions('name_code')}
                    onChange={(value) => setAttributes({list_display: value})}
                    __nextHasNoMarginBottom
                />
                {list_display !== 'symbol' && list_display !== 'flag' && (
                    <ToggleControl
                        label={blocktranslate('Show sign')}
                        checked={list_symbol === 'yes'}
                        onChange={(checked) => setAttributes({list_symbol: checked ? 'yes' : 'no'})}
                        __nextHasNoMarginBottom
                    />
                )}
                {list_display !== 'flag' && (
                    <ToggleControl
                        label={blocktranslate('Show flag')}
                        checked={list_flag !== 'no'}
                        onChange={(checked) => setAttributes({list_flag: checked ? 'yes' : 'no'})}
                        __nextHasNoMarginBottom
                    />
                )}
                {list_display !== 'flag' && list_flag !== 'no' && (
                    <SelectControl
                        label={blocktranslate('Flag position')}
                        value={list_flag_position}
                        options={[
                            {label: blocktranslate('Left'), value: 'left'},
                            {label: blocktranslate('Right'), value: 'right'},
                        ]}
                        onChange={(value) => setAttributes({list_flag_position: value})}
                        __nextHasNoMarginBottom
                    />
                )}
            </PanelBody>
        </InspectorControls>
    );
};

export default InspectorSettings;
