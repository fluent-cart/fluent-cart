import blocktranslate from "@/BlockEditor/BlockEditorTranslator";
import InspectorSettings from "@/BlockEditor/StoreLogo/Components/InspectorSettings";
import {StoreLogo} from "@/BlockEditor/Icons";

const {useBlockProps, MediaUpload, MediaUploadCheck} = wp.blockEditor;
const {registerBlockType} = wp.blocks;
const {Placeholder, Button} = wp.components;

const blockEditorData = window.fluent_cart_store_logo_data;

registerBlockType(blockEditorData.slug + '/' + blockEditorData.name, {
    title: blockEditorData.title,
    description: blockEditorData.description,
    icon: {
        src: StoreLogo,
    },
    category: "fluent-cart",
    attributes: {
        is_link: {
            type: 'boolean',
            default: true
        },
        link_target: {
            type: 'string',
            default: '_self'
        },
        max_width: {
            type: 'string',
            default: '150'
        },
        max_height: {
            type: 'string',
            default: '70'
        },
        logo_id: {
            type: 'number',
            default: 0
        },
        logo_url: {
            type: 'string',
            default: ''
        }
    },
    supports: {},
    edit: ({attributes, setAttributes}) => {
        const blockProps = useBlockProps();
        const {is_link, link_target, max_width, max_height, logo_id, logo_url} = attributes;

        const storeLogo = blockEditorData.store_logo;
        const storeName = blockEditorData.store_name;
        const homeUrl = blockEditorData.home_url;

        // Use custom logo if set, otherwise use store logo from settings
        const displayLogo = logo_url || storeLogo;

        const imgStyle = {};
        if (max_width) {
            imgStyle.maxWidth = max_width + 'px';
        }
        if (max_height) {
            imgStyle.maxHeight = max_height + 'px';
        }
        
        const linkProps = is_link ? {
            href: homeUrl,
            target: link_target,
            rel: link_target === '_blank' ? 'noopener noreferrer' : undefined,
            onClick: (e) => e.preventDefault()
        } : {};

        const onSelectImage = (media) => {
            setAttributes({
                logo_id: media.id,
                logo_url: media.url
            });
        };

        const onRemoveImage = () => {
            setAttributes({
                logo_id: 0,
                logo_url: ''
            });
        };

        // Show placeholder with media upload when no logo is available
        if (!displayLogo) {
            return (
                <div {...blockProps}>
                    <InspectorSettings
                        attributes={attributes}
                        setAttributes={setAttributes}
                        hasLogo={false}
                        onRemoveImage={onRemoveImage}
                    />

                    <MediaUploadCheck>
                        <Placeholder
                            icon={StoreLogo}
                            label={blocktranslate('Store Logo')}
                            instructions={blocktranslate('Upload a logo or select one from your media library. You can also set your store logo in Settings.')}
                            className="fct-store-logo-placeholder"
                        >
                            <MediaUpload
                                onSelect={onSelectImage}
                                allowedTypes={['image']}
                                value={logo_id}
                                render={({open}) => (
                                    <Button
                                        variant="primary"
                                        onClick={open}
                                    >
                                        {blocktranslate('Upload Logo')}
                                    </Button>
                                )}
                            />
                        </Placeholder>
                    </MediaUploadCheck>
                </div>
            );
        }

        return (
            <div {...blockProps}>
                <InspectorSettings
                    attributes={attributes}
                    setAttributes={setAttributes}
                    hasLogo={true}
                    onRemoveImage={onRemoveImage}
                />

                <div className="fct-store-logo-wrapper">

                    {is_link && (
                        <a {...linkProps} className="fct-store-logo-link">
                            <img
                                src={displayLogo}
                                alt={storeName}
                                className="fct-store-logo-img"
                                style={imgStyle}
                            />
                        </a>
                    )}

                    {!is_link && (
                        <div className="fct-store-logo-without-link">
                            <img
                                src={displayLogo}
                                alt={storeName}
                                className="fct-store-logo-img"
                                style={imgStyle}
                            />
                        </div>
                    )}
                    
                </div>
            </div>
        );
    },
    save: function () {
        return null;
    }
});
