import InspectorSettings from "./Components/InspectorSettings";
import ProNotice from "@/BlockEditor/Components/Pro/ProNotice";
import ProBlockIcon from "@/BlockEditor/Components/Pro/ProBlockIcon";
import {CaretDown} from "@/BlockEditor/Icons";

const {registerBlockType} = wp.blocks;
const blockEditorData = window.fluent_cart_currency_switcher_data;
const isProActive = !!blockEditorData.is_pro_active;

const buttonStyle = {
    display: 'inline-flex',
    alignItems: 'center',
    gap: '6px',
    padding: '6px 10px',
    border: '1px solid #e5e7eb',
    borderRadius: '6px',
    background: '#ffffff',
    color: '#2F3448',
    fontSize: '14px',
    lineHeight: 1.4,
};

// Mirrors the frontend label, so the preview reads like the live switcher
const currencyLabel = ({code, symbol, name}, display, showSymbol = '') => {
    const hasSymbol = symbol && symbol !== code;
    let label;

    switch (display) {
        case 'flag':
            return code;
        case 'symbol':
            return hasSymbol ? symbol : code;
        case 'name':
            label = name || code;
            break;
        case 'name_code':
            label = name ? `${name} (${code})` : code;
            break;
        default:
            label = code;
    }

    const addSymbol = showSymbol === 'yes' || (!showSymbol && !display);

    return addSymbol && hasSymbol ? `${label} ${symbol}` : label;
};

const ButtonPreview = ({currency, attributes}) => {
    // Flag only falls back to the code when the currency has no flag, like the frontend
    const flagOnly = attributes.display === 'flag' && currency.flag;
    const flag = (flagOnly || attributes.flag !== 'no') && currency.flag
        ? <img src={currency.flag} alt="" width="18" height="14"/>
        : null;
    const flagRight = attributes.flag_position === 'right';

    if (flagOnly) {
        return (
            <span className="fct-currency-switcher-button" style={buttonStyle}>
                {flag}
                <CaretDown/>
            </span>
        );
    }

    return (
        <span className="fct-currency-switcher-button" style={buttonStyle}>
            {!flagRight && flag}
            <span>{currencyLabel(currency, attributes.display, attributes.symbol)}</span>
            {flagRight && flag}
            <CaretDown/>
        </span>
    );
};

const SelectPreview = ({currencies}) => (
    <select className="fct-currency-switcher" disabled>
        {currencies.map(currency => (
            <option key={currency.code} value={currency.code}>{currencyLabel(currency, '')}</option>
        ))}
    </select>
);

registerBlockType(blockEditorData.slug + '/' + blockEditorData.name, {
    apiVersion: 3,
    title: blockEditorData.title,
    icon: isProActive ? 'money-alt' : <ProBlockIcon icon="money-alt"/>,
    category: 'fluent-cart',
    supports: {
        html: false,
    },
    attributes: {
        display: {type: 'string', default: ''},
        flag: {type: 'string', default: 'yes'},
        symbol: {type: 'string', default: ''},
        flag_position: {type: 'string', default: 'left'},
        list_display: {type: 'string', default: ''},
        list_flag: {type: 'string', default: 'yes'},
        list_symbol: {type: 'string', default: 'no'},
        list_flag_position: {type: 'string', default: 'left'},
    },

    edit: function ({attributes, setAttributes}) {
        const {useBlockProps} = wp.blockEditor;
        const blockProps = useBlockProps();
        const preview = blockEditorData.preview || {};
        const currencies = preview.currencies || [];
        const isButton = preview.style === 'button';

        // Without Pro the switcher renders nothing, so the canvas shows the Pro notice
        // instead of a preview. Settings are kept for when Pro returns.
        if (!isProActive) {
            return (
                <div {...blockProps}>
                    <ProNotice note={blockEditorData.pro_note}/>
                </div>
            );
        }

        if (currencies.length < 2) {
            return (
                <div {...blockProps}>
                    <p className="fct-currency-switcher-empty">{blockEditorData.empty_note}</p>
                </div>
            );
        }

        return (
            <div {...blockProps}>
                {/* Core's select has no display options; only the button reads them */}
                {isButton && <InspectorSettings attributes={attributes} setAttributes={setAttributes}/>}
                {isButton ? (
                    <ButtonPreview currency={currencies[0]} attributes={attributes}/>
                ) : (
                    <SelectPreview currencies={currencies}/>
                )}
            </div>
        );
    },

    save: function () {
        return null;
    },
});
