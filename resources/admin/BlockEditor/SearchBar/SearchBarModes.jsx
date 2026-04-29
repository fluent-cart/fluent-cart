import blocktranslate from "@/BlockEditor/BlockEditorTranslator";

const { InspectorControls } = wp.blockEditor;
const { CheckboxControl } = wp.components;

const SearchBarModes = ({ attributes, setAttributes }) => {

    const url_mode = attributes.url_mode;
    const newTabValue = 'new-tab';
    const currentTabValue = 'current-tab';
    const category_mode = attributes.category_mode;
    const link_with_shop_app = attributes.link_with_shop_app;

    const handleUrlMode = (value) => {
        setAttributes({ url_mode: value });
    };

    const handleCategoryMode = () => {
        const newCategoryMode = !attributes.category_mode;
        setAttributes({ category_mode: newCategoryMode ? true : false });
    };

    const handleLinkWithShopApp = () => {
        const linkWithShopApp = !attributes.link_with_shop_app;
        setAttributes({ link_with_shop_app: linkWithShopApp ? true : false });
    };

    return (
        <InspectorControls>
            <div className={'fluent-cart-searchbar-block-editor'}>
                <div className={'pb-4 px-4'}>
                    <div className={'max-w-xs pl-5 mx-auto bg-white p-6 border border-solid border-neutral-200 rounded-md shadow-md'}>
                        <h3 className={'text-xs font-semibold mb-4'}>{blocktranslate('SearchBar')}</h3>

                        <CheckboxControl
                            type="checkbox"
                            label={blocktranslate('New Tab')}
                            value={newTabValue}
                            checked={url_mode === newTabValue}
                            onChange={() => handleUrlMode(newTabValue)}
                        />

                        <CheckboxControl
                            type="checkbox"
                            label={blocktranslate('Current Tab')}
                            value={currentTabValue}
                            checked={url_mode === currentTabValue}
                            onChange={() => handleUrlMode(currentTabValue)}
                        />
                    </div>
                </div>

                <div className="pb-4 px-4">
                    <div className={'max-w-xs pl-5 mx-auto bg-white p-6 border border-solid border-neutral-200 rounded-md shadow-md'}>
                        <h3 className="text-sm font-semibold mb-4">{blocktranslate('Category')}</h3>
                        <CheckboxControl
                            label={blocktranslate('Category')}
                            type="checkbox"
                            checked={category_mode === true}
                            onChange={handleCategoryMode}
                        />
                    </div>
                </div>

                <div className="pb-4 px-4">
                    <div className={'max-w-xs pl-5 mx-auto bg-white p-6 border border-solid border-neutral-200 rounded-md shadow-md'}>
                        <h3 className="text-sm font-semibold mb-4">{blocktranslate('Link With Shop App')}</h3>
                        <CheckboxControl
                            label={blocktranslate('Link With Shop App')}
                            type="checkbox"
                            checked={link_with_shop_app === true}
                            onChange={handleLinkWithShopApp}
                        />
                    </div>
                </div>
            </div>
        </InspectorControls>
    );
};

export default SearchBarModes;
