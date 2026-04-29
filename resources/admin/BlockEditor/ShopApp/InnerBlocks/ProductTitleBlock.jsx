import { useSingleProductData } from "@/BlockEditor/ShopApp/Context/SingleProductContext";
import blocktranslate from "@/BlockEditor/BlockEditorTranslator";
const { useBlockProps, InspectorControls } = wp.blockEditor;
const { PanelBody, ToggleControl } = wp.components;

const ProductTitleBlock = {
    attributes: {
        isLink: {
            type: 'boolean',
            default: true
        },
        linkTarget: {
            type: 'string',
            default: '_self'
        }
    },
    edit: (props) => {
        const { attributes, setAttributes } = props;
        const { isLink, linkTarget } = attributes;

        const blockProps = useBlockProps({
            className: 'fct-product-block-editor-product-card-title',
        });

        const singleProductData = useSingleProductData();
        const titleText = singleProductData?.product?.post_title || blocktranslate('Product Title');
        const productUrl = singleProductData?.product?.view_url || '#';

        const TagName = isLink ? 'a' : 'h5';
        const linkProps = isLink ? {
            href: productUrl,
            target: linkTarget,
            rel: linkTarget === '_blank' ? 'noopener noreferrer' : undefined,
            onClick: (e) => e.preventDefault()
        } : {};



        return <>
            <InspectorControls>
                <PanelBody title={blocktranslate('Settings')}>
                    <ToggleControl
                        label={blocktranslate('Make title a link')}
                        checked={isLink}
                        onChange={(value) => setAttributes({ isLink: value })}
                    />
                    {isLink && (
                        <ToggleControl
                            label={blocktranslate('Open in new tab')}
                            checked={linkTarget === '_blank'}
                            onChange={(value) =>
                                setAttributes({ linkTarget: value ? '_blank' : '_self' })
                            }
                        />
                    )}
                </PanelBody>
            </InspectorControls>

            <TagName {...blockProps} {...linkProps}>
                {titleText}
            </TagName>
        </>;
    },
    save: (props) => {
        return null;
    },
    supports: {
        html: false,
        align: ["left", "center", "right"],
        typography: {
            fontSize: true,
            lineHeight: true
        },
        spacing: {
            margin: true
        },
        color: {
            text: true,
            link: true
        }
    }
};

export default ProductTitleBlock;
