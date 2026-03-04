import blocktranslate from "@/BlockEditor/BlockEditorTranslator";

const {InspectorControls, MediaUpload, MediaUploadCheck} = wp.blockEditor;
const {
    TextControl, 
    ToggleControl, 
    Button, 
    PanelBody,
    __experimentalToggleGroupControl,
    __experimentalToggleGroupControlOption
} = wp.components;
const ToggleGroupControl = __experimentalToggleGroupControl;
const ToggleGroupControlOption = __experimentalToggleGroupControlOption;

const InspectorSettings = ({attributes, setAttributes, hasLogo, onRemoveImage}) => {
    const {is_link, link_target, max_width, max_height, logo_id} = attributes;

    const onSelectImage = (media) => {
        setAttributes({
            logo_id: media.id,
            logo_url: media.url
        });
    };
    

    return (
        <InspectorControls>
        
            {/* Logo Image Panel */}
            {hasLogo && (
                <PanelBody
                    title={blocktranslate('Logo Image')}
                    initialOpen={true}
                >
                    <div className="fct-logo-actions flex items-center justify-between gap-2">
                        <MediaUploadCheck>
                            <MediaUpload
                                onSelect={onSelectImage}
                                allowedTypes={['image']}
                                value={logo_id}
                                render={({ open }) => (
                                    <Button
                                        variant="secondary"
                                        onClick={open}
                                    >
                                        {blocktranslate('Replace Logo')}
                                    </Button>
                                )}
                            />
                        </MediaUploadCheck>

                        {logo_id > 0 && (
                            <Button
                                variant="link"
                                isDestructive
                                onClick={onRemoveImage}
                            >
                                {blocktranslate('Remove')}
                            </Button>
                        )}
                    </div>
                </PanelBody>
            )}


            {/* Logo Settings */}
            <PanelBody
                title={blocktranslate('Logo Settings')}
                initialOpen={!hasLogo}
            >
                <TextControl
                    label={blocktranslate('Max Width (px)')}
                    value={max_width}
                    type="number"
                    min="0"
                    onChange={(value) =>
                        setAttributes({ max_width: value })
                    }
                />

                <TextControl
                    label={blocktranslate('Max Height (px)')}
                    value={max_height}
                    type="number"
                    min="0"
                    onChange={(value) =>
                        setAttributes({ max_height: value })
                    }
                />
            </PanelBody>


            {/* Link Settings */}
            <PanelBody
                title={blocktranslate('Link Settings')}
                initialOpen={false}
            >
                <ToggleControl
                    label={blocktranslate('Link to Home')}
                    checked={is_link}
                    onChange={(value) =>
                        setAttributes({ is_link: value })
                    }
                />

                {is_link && (
                    <ToggleGroupControl
                        label={blocktranslate('Open in')}
                        value={link_target}
                        isBlock
                        onChange={(value) =>
                            setAttributes({ link_target: value })
                        }
                    >
                        <ToggleGroupControlOption
                            value="_self"
                            label={blocktranslate('Same Tab')}
                        />
                        <ToggleGroupControlOption
                            value="_blank"
                            label={blocktranslate('New Tab')}
                        />
                    </ToggleGroupControl>
                )}
            </PanelBody>
        </InspectorControls>
    );
};

export default InspectorSettings;
