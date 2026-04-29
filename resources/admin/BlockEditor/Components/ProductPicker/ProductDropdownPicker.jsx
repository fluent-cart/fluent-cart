import blocktranslate from "@/BlockEditor/BlockEditorTranslator";
import apiFetch from "@wordpress/api-fetch";
import {addQueryArgs} from "@wordpress/url";

const {Button, TextControl, Spinner, Icon} = wp.components;
const {useState, useEffect, useRef} = wp.element;

const rest = window['fluentCartRestVars'].rest;
const placeholderImage = window.fluent_cart_block_editor_asset?.placeholder_image;

const ProductDropdownList = ({onSelect}) => {
    const [search, setSearch] = useState('');
    const [products, setProducts] = useState([]);
    const [loading, setLoading] = useState(false);

    const requestIdRef = useRef(0);

    const fetchProducts = (searchQuery = '') => {
        const currentRequestId = ++requestIdRef.current;
        setLoading(true);
        apiFetch({
            path: addQueryArgs(rest.url + '/products', {
                'with': ['detail', 'variants'],
                'active_view': 'publish',
                'per_page': 10,
                'page': 1,
                'order_by': 'ID',
                'order_type': 'DESC',
                'search': searchQuery || undefined,
            }),
            headers: {
                'X-WP-Nonce': rest.nonce
            }
        }).then((res) => {
            if (currentRequestId !== requestIdRef.current) return;
            setProducts(res.products?.data || []);
        }).catch(() => {
            if (currentRequestId !== requestIdRef.current) return;
            setProducts([]);
        }).finally(() => {
            if (currentRequestId !== requestIdRef.current) return;
            setLoading(false);
        });
    };

    useEffect(() => {
        fetchProducts();
    }, []);

    const debounceRef = useRef(null);

    const handleSearch = (value) => {
        setSearch(value);
        if (debounceRef.current) {
            clearTimeout(debounceRef.current);
        }
        debounceRef.current = setTimeout(() => {
            fetchProducts(value);
        }, 300);
    };

    useEffect(() => {
        return () => {
            if (debounceRef.current) {
                clearTimeout(debounceRef.current);
            }
        };
    }, []);

    return (
        <div className="fct-product-picker-dropdown">
            <TextControl
                placeholder={blocktranslate("Search products...")}
                value={search}
                onChange={handleSearch}
                className="fct-product-picker-search"
            />
            <div className="fct-product-picker-list">
                {loading ? (
                    <div className="fct-product-picker-loading">
                        <Spinner />
                    </div>
                ) : products.length > 0 ? (
                    products.map((product) => (
                        <button
                            key={product.ID}
                            className="fct-product-picker-item"
                            onClick={() => onSelect(product)}
                            type="button"
                        >
                            <img
                                className="fct-product-picker-item-img"
                                src={product?.detail?.featured_media?.url || placeholderImage}
                                alt={product.post_title}
                            />
                            <span className="fct-product-picker-item-title">
                                {product.post_title}
                            </span>
                        </button>
                    ))
                ) : (
                    <p className="fct-product-picker-empty">
                        {blocktranslate("No products found")}
                    </p>
                )}
            </div>
        </div>
    );
};

const ProductDropdownPicker = ({
    selectedProduct = null,
    onSelect,
    buttonLabel = null,
    changeLabel = null,
}) => {
    const [isDropdownOpen, setIsDropdownOpen] = useState(false);
    const dropdownRef = useRef(null);

    const selectLabel = buttonLabel || blocktranslate("Select Product");
    const updateLabel = changeLabel || blocktranslate("Change Product");

    useEffect(() => {
        const handleClickOutside = (event) => {
            if (dropdownRef.current && !dropdownRef.current.contains(event.target)) {
                setIsDropdownOpen(false);
            }
        };
        document.addEventListener('mousedown', handleClickOutside);
        return () => document.removeEventListener('mousedown', handleClickOutside);
    }, []);

    const handleProductSelect = (product) => {
        onSelect(product);
        setIsDropdownOpen(false);
    };

    const productImage = selectedProduct?.detail?.featured_media?.url
        || selectedProduct?.thumbnail
        || placeholderImage;

    return (
        <div>
            {selectedProduct?.post_title && (
                <div className="fct-product-picker-selected">
                    <img
                        className="fct-product-picker-selected-img"
                        src={productImage}
                        alt={selectedProduct.post_title}
                        width={40}
                        height={40}
                        style={{ width: '40px', height: '40px', maxWidth: '40px', objectFit: 'cover' }}
                    />
                    <span className="fct-product-picker-selected-title">
                        {selectedProduct.post_title}
                    </span>
                </div>
            )}

            <div className="fct-product-picker-button-wrap" ref={dropdownRef}>
                <Button
                    variant="secondary"
                    onClick={() => setIsDropdownOpen(!isDropdownOpen)}
                    className="fct-product-picker-btn"
                >
                    <Icon icon={selectedProduct?.post_title ? "edit" : "search"} size={16} />
                    {selectedProduct?.post_title ? updateLabel : selectLabel}
                </Button>

                {isDropdownOpen && (
                    <ProductDropdownList onSelect={handleProductSelect} />
                )}
            </div>
        </div>
    );
};

export default ProductDropdownPicker;
