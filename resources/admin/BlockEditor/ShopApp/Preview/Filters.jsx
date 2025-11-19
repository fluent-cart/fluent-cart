import blocktranslate from "@/BlockEditor/BlockEditorTranslator";

const {useState} = wp.element;

const Filters = (props) => {
    const {attributes, blockEditorData} = props;
    const [openFilters, setOpenFilters] = useState(Array.from({ length: Object.keys(attributes.default_filter_options).length }, () => true));

    // Function to handle collapsing/expanding of filter sections
    const handleCollapsed = (index) => {
        setOpenFilters((prevValues) => {
            // Create a copy of the previous state array
            const updatedValues = [...prevValues];

            // Toggle the value at the specified index to collapse/expand the filter section
            updatedValues[index] = !updatedValues[index];

            // Return the updated array to set the new state
            return updatedValues;
        });
    };


    return (
        <>

            <div className="fct-product-block-filter-item">
                <div className="relative w-full">
                    <div className="absolute top-0 left-[14px] bottom-0 flex items-center text-system-mid">
                        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 18 18" fill="none">
                            <path d="M13.125 13.125L16.5 16.5" stroke="currentColor" strokeWidth="1.25" strokeLinecap="round" strokeLinejoin="round"></path>
                            <path d="M15 8.25C15 4.52208 11.9779 1.5 8.25 1.5C4.52208 1.5 1.5 4.52208 1.5 8.25C1.5 11.9779 4.52208 15 8.25 15C11.9779 15 15 11.9779 15 8.25Z" stroke="currentColor" strokeWidth="1.25" strokeLinejoin="round"></path>
                        </svg>
                    </div>

                    <input onChange={() => {
                    }}
                           className="fct-product-block-input"
                           type="text" name="wildcard" placeholder={blocktranslate("Search products by name")}
                           value=""/>
                </div>
            </div>


            {(Object.keys(attributes.default_filter_options)).map(function (filterKey, i) {

                    let filter;
                    if (typeof attributes.filters?.[filterKey] == "object") {
                        filter = attributes.filters?.[filterKey];
                    } else {
                        filter = attributes.default_filter_options[filterKey];
                    }

                    let options = [{
                        'label': blocktranslate('Option 1')
                    },
                        {
                            'label': blocktranslate('Option 2')
                        },];

                    if (window.fluent_cart_shop_app_block_editor_data.taxonomies.hasOwnProperty(filterKey)) {
                        options = window.fluent_cart_shop_app_block_editor_data.taxonomies[filterKey].terms;
                    }

                    return filter.enabled ? (
                        <div className="fct-product-block-filter-item"
                             key={i}>
                            <div className={`flex items-center justify-between cursor-pointer ${openFilters[i] ? '' : 'is-collapsed'}`} onClick={() => handleCollapsed(i)}>
                                <h5 className='fct-product-block-filter-title'>{filter.label}</h5>
                                <div className="toggle-icon">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="12" height="7" viewBox="0 0 12 7"
                                         fill="none">
                                        <path
                                            d="M11 1.5L6.70711 5.79289C6.37377 6.12623 6.20711 6.29289 6 6.29289C5.79289 6.29289 5.62623 6.12623 5.29289 5.79289L1 1.5"
                                            stroke="#565865" strokeWidth="1.25" strokeLinecap="round"
                                            strokeLinejoin="round"></path>
                                    </svg>
                                </div>
                            </div>


                            {filter.filter_type === 'options' && !!openFilters[i] && (
                                <div className='pt-4 fct-product-block-filter-options-scroll'>
                                    {options.length > 0 ? (
                                        options.map((option, index) => (
                                            <label key={index} className="fct-product-block-filter-checkbox">
                                                <input type="checkbox" name='' value=''/>
                                                <span>{option.label}</span>
                                            </label>
                                        ))
                                    ) : (
                                        <div className="text-sm font-normal text-system-mid leading-[22px]">
                                            {blocktranslate('In frontend this filter will be Hidden until product types are added.')}
                                        </div>
                                    )}
                                </div>
                            )}

                            {filter.filter_type === 'range' && !!openFilters[i] && (
                                <div className='pt-4'>
                                    <div
                                        className="p-2 bg-yellow-100 text-yellow-600 leading-none rounded flex">

                                        <svg className="flex-shrink-0" xmlns="http://www.w3.org/2000/svg" width="20" height="20"
                                             viewBox="0 0 24 24" fill="none">
                                            <path
                                                d="M12 17.25C12.6213 17.25 13.125 16.7463 13.125 16.125C13.125 15.5037 12.6213 15 12 15C11.3787 15 10.875 15.5037 10.875 16.125C10.875 16.7463 11.3787 17.25 12 17.25Z"
                                                fill="currentColor"/>
                                            <path
                                                d="M12 7.5V12.75M21 12C21 16.9706 16.9706 21 12 21C7.02944 21 3 16.9706 3 12C3 7.02944 7.02944 3 12 3C16.9706 3 21 7.02944 21 12Z"
                                                stroke="currentColor" strokeWidth="1.5" strokeLinecap="round"/>
                                        </svg>
                                        <span className="text-sm font-normal ml-2">
                                            {blocktranslate('Range Filter Only works in pages.')}
                                        </span>
                                    </div>
                                </div>
                            )}

                        </div>
                    ) : (<></>)
                }
            )}

            <div className="flex items-center justify-between gap-2.5">
                <button
                    className="fct-product-block-filter-button">
                    {blocktranslate('Apply Filter')}
                </button>
                <button
                    className="fct-product-block-reset-button">
                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none">
                        <path
                            d="M14.2501 9.75V15.75M9.75012 9.75V15.75M20.2498 5.25L3.74976 5.25001M18.7501 5.25V19.5C18.7501 19.6989 18.6711 19.8897 18.5305 20.0303C18.3898 20.171 18.199 20.25 18.0001 20.25H6.00012C5.80121 20.25 5.61044 20.171 5.46979 20.0303C5.32914 19.8897 5.25012 19.6989 5.25012 19.5V5.25M15.7501 5.25V3.75C15.7501 3.35218 15.5921 2.97064 15.3108 2.68934C15.0295 2.40804 14.6479 2.25 14.2501 2.25H9.75012C9.3523 2.25 8.97077 2.40804 8.68946 2.68934C8.40816 2.97064 8.25012 3.35218 8.25012 3.75V5.25"
                            stroke="currentColor" strokeWidth="1.5" strokeLinecap="round" strokeLinejoin="round"></path>
                    </svg>
                </button>
            </div>


        </>
    );
}
export default Filters;
