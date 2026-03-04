import blocktranslate from "@/BlockEditor/BlockEditorTranslator";
import {ButtonIcon, UserIcon} from "@/BlockEditor/Icons";
import CustomerDashboardButtonInspectorSettings from "./Components/CustomerDashboardButtonInspectorSettings";


const {useBlockProps, RichText} = wp.blockEditor;
const {registerBlockType} = wp.blocks;

const blockEditorData = window.fluent_cart_customer_dashboard_button_data;

registerBlockType(blockEditorData.slug + '/' + blockEditorData.name, {
    apiVersion: 3,
    title: blockEditorData.title,
    description: blockEditorData.description,
    icon: {
        src: ButtonIcon,
    },
    category: "fluent-cart",
    attributes: {
        display_type: {
            type: 'string',
            default: 'button' // button | link
        },
        button_text: {
            type: 'string',
            default: ''
        },
        link_target: {
            type: 'string',
            default: '_self' // _self | _blank
        },
        show_icon: {
            type: 'boolean',
            default: true
        }
    },
    supports: {
        html: false,
        align: ["left", "center", "right"],
        alignWide: false,
        typography: {
            fontSize: true,
            fontFamily: true,
            fontWeight: true,
            lineHeight: true
        },
        color: {
            text: true,
            background: true
        },
        spacing: {
            margin: true,
            padding: true
        },
        __experimentalBorder: {
            color: true,
            radius: true,
            width: true,
            style: true,
            __experimentalDefaultControls: {
                color: true,
                radius: true,
                width: true
            }
        },
        border: {
            color: true,
            radius: true,
            width: true,
            style: true
        },
        shadow: true,
    },
    edit: (props) => {
        const {attributes, setAttributes, clientId} = props;
        const {display_type, button_text, show_icon} = attributes;

        const blockProps = useBlockProps({
            className: display_type === 'button'
                ? 'wp-block-button__link wp-element-button fct-customer-dashboard-btn'
                : 'fct-customer-dashboard-link'
        });

        const defaultText = blocktranslate('My Account', 'fluent-cart');

        return (
            <div>
                <CustomerDashboardButtonInspectorSettings
                    attributes={attributes}
                    setAttributes={setAttributes}
                    clientId={clientId}
                />

                <a
                    {...blockProps}
                >
                    {show_icon && (
                        <span className="fct-customer-dashboard-icon">
                            <UserIcon />
                        </span>
                    )}
                    <RichText
                        tagName="span"
                        value={button_text}
                        onChange={(value) => setAttributes({button_text: value})}
                        placeholder={defaultText}
                        allowedFormats={['core/bold', 'core/italic']}
                    />
                </a>
            </div>
        );
    },

    save: function (props) {
        return null;
    },
});
