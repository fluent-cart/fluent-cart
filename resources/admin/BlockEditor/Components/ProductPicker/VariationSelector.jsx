import apiFetch from "@wordpress/api-fetch";
import {addQueryArgs} from '@wordpress/url';
import ProductListItem from "./ProductListItem";
import Input from '@/BlockEditor/Components/Input';
import EmptyBlock from "@/BlockEditor/Components/Empty";
import React from "react";
import blocktranslate from "@/BlockEditor/BlockEditorTranslator";
import {resolveVariationSelection} from "./variationSelection";

const {useEffect, useState} = wp.element;

const rest = window['fluentCartRestVars'].rest;

const VariationSelector = (props) => {

    const {
        preSelectedVariations,
        onVariationSelectionUpdated,
        isMultiple = true,
        translationKey = 'fluent_cart_pricing_table_block_editor_data',
        allow_subscription = true,
        not_subscribable = false,
        allowMultipleProducts = false
    } = props;

    const fetchUrl = rest.url + '/products';
    const [loading, setLoading] = useState(false);

    const [paginate, setPaginate] = useState({
        per_page: 10,
        current_page: 1,
        total: 0,
        last_page: 1
    });

    const [products, setProducts] = useState({});
    const [selectedVariations, setSelectedVariations] = useState(preSelectedVariations || {});

    const updateSelectedVariationsOld = (variation, add) => {

        let updatedVariations = {...selectedVariations};

        if (add) {
            if (!isMultiple) {
                updatedVariations = {};
            }

            updatedVariations[variation.id] = {
                ...variation
            };


        } else {
            if (updatedVariations.hasOwnProperty(variation.id)) {
                delete updatedVariations[variation.id];
            }
        }
        setSelectedVariations(updatedVariations);

        if (typeof onVariationSelectionUpdated === "function") {
            onVariationSelectionUpdated(updatedVariations);
        }

    }

    const updateSelectedVariations = (variant, checked) => {
        const options = {isMultiple, allowMultipleProducts};

        setSelectedVariations(prev =>
            resolveVariationSelection(prev, variant, checked, options)
        );

        if (typeof onVariationSelectionUpdated === "function") {
            onVariationSelectionUpdated(prev =>
                resolveVariationSelection(prev, variant, checked, options)
            );
        }
    };

    const fetchProducts = (searchQuery = null) => {
        setLoading(true);

        setProducts({});

        let queryParams = {
            "admin_filters": {
                // "other_info": {
                //     column: "other_info",
                //     operator: "like_all",
                //     value: '"use_pricing_table": "yes"'
                // }
            },
            // A screen key, not a relation name — ProductFilter::allowedWiths()
            // decides what it loads for the block-editor pickers.
            'with': [
                'block_picker'
            ],
            'active_view': 'publish',
            "per_page": 10,
            "page": 1,
            "order_by": 'ID',
            "order_type": 'DESC',
            "search": searchQuery
        };

        if (!allow_subscription) {
            queryParams['scopes'] = ['cartable'];
        }

        if (not_subscribable) {
            queryParams['active_view'] = 'not_subscribable'
        }

        apiFetch({
            path: addQueryArgs(fetchUrl, {
                ...queryParams
            }),
            headers: {
                'X-WP-Nonce': rest.nonce
            }
        }).then((res) => {
            let currentPaginate = {
                ...paginate,
                per_page: res.products.per_page,
                current_page: res.products.current_page,
                total: res.products.total,
                last_page: res.products.last_page
            };
            let products = res.products.data;
            console.log(products, 'products');
            

            setPaginate(prevPaginate => ({
                ...prevPaginate,
                ...currentPaginate
            }));
            setProducts(prevProducts => ({
                ...prevProducts,
                ...products
            }));
        }).finally(() => {
            setLoading(false);
        });
    }


    useEffect(() => {
        fetchProducts();
    }, [])


    useEffect(() => {
        setSelectedVariations(preSelectedVariations || {});
    }, [preSelectedVariations]);


    const handleSearchProducts = (value) => {
        fetchProducts(value);
    };

    return (
        <>
            <div className="fct-popup-search-wrap">
                <Input
                    icon
                    name="search"
                    placeholder={blocktranslate('Search for products...')}
                    onKeyDown={(event) => {
                        const value = event.target.value;
                        if (event.key === 'Enter') {
                            handleSearchProducts(value);
                        }
                    }}
                />
            </div>

            <div className="fct-popup-body-inner">
                {!loading ? (
                    Object.keys(products).length > 0 ? (
                        <div className="fct-popup-product-list-container">
                            <ul className="fct-collapsible-list">
                                {Object.entries(products).map((product, index) => {
                                    return (

                                        <li className="fct-collapsible-list-item" key={index}>
                                            <ProductListItem
                                                allow_subscription={allow_subscription}
                                                isMultiple={isMultiple}
                                                products={products}
                                                index={index}
                                                selectedVariations={{
                                                    ...selectedVariations
                                                }}
                                                updateSelectedVariations={updateSelectedVariations}
                                            />
                                        </li>
                                    );
                                })}
                            </ul>
                        </div>
                    ) : (
                        <EmptyBlock text={blocktranslate('We couldn\'t find any products matching your search')}/>
                    )
                ) : (
                    <div className="fct-skeleton-container">
                        {[...Array(9)].map((_, index) => (
                            <div className="skeleton skeleton-item" key={index}></div>
                        ))}
                    </div>
                )}
            </div>
        </>
    );
}
export default VariationSelector;
