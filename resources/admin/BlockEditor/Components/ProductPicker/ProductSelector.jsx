import apiFetch from "@wordpress/api-fetch";
import {addQueryArgs} from '@wordpress/url';
import ProductListItem from "./ProductListItem";
import Input from '@/BlockEditor/Components/Input';
import EmptyBlock from "@/BlockEditor/Components/Empty";
import React from "react";
import blocktranslate from "@/BlockEditor/BlockEditorTranslator";
import ListItem from "./ListItem";

const {useEffect, useState} = wp.element;

const rest = window['fluentCartRestVars'].rest;

const ProductSelector = ({prevSelectedProduct, onProductSelectionUpdated }) => {
    
    const fetchUrl = rest.url + '/products';
    const [loading, setLoading] = useState(false);

    const [products, setProducts] = useState({});
    const [selectedProduct, setSelectedProduct] = useState({});

    const updateSelectedProduct = (product, add) => {

        setSelectedProduct(product);

        if (typeof onProductSelectionUpdated === "function") {
            onProductSelectionUpdated(product);
        }

    }


    const fetchProducts = (searchQuery = null) => {
        setLoading(true);

        setProducts({});

        let queryParams = {
            'with': [
                'detail',
                'variants'
            ],
            'active_view': 'publish',
            "per_page": 10,
            "page": 1,
            "order_by": 'ID',
            "order_type": 'DESC',
            "search": searchQuery
        };

        apiFetch({
            path: addQueryArgs(fetchUrl, {
                ...queryParams
            }),
            headers: {
                'X-WP-Nonce': rest.nonce
            }
        }).then((res) => {

            let products = res.products.data;            

            setProducts(products);
        }).finally(() => {
            setLoading(false);
        });
    }


    useEffect(() => {
        fetchProducts();
    }, [])




    const handleSearchProducts = (value) => {
        fetchProducts(value);
    };

    return (
        <>
            <div className="fct-popup-search-wrap">
                <Input
                    icon
                    name="search"
                    placeholder={blocktranslate('Type to search for products, then hit Enter')}
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
                                {products.map((product, index) => {
                                    
                                    return (

                                        <li className="fct-collapsible-list-item" key={index}>
                                            <div className="fct-collapsible-list-item-inner">
                                                <ListItem 
                                                    variant={product}
                                                    title={product?.post_title}
                                                    checked={prevSelectedProduct?.ID === product.ID}
                                                    media={product?.detail?.featured_media?.url}
                                                    updateSelectedVariations={updateSelectedProduct}
                                                />
                                            </div>
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
export default ProductSelector;
