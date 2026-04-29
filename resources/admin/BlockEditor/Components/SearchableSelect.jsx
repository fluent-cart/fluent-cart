import {useDebounce} from "@/mixin/useDebounce";
import blocktranslate from "@/BlockEditor/BlockEditorTranslator";

const {useState} = wp.element;
const {TextControl} = wp.components;
const {apiFetch} = wp;
const {addQueryArgs} = wp.url;
const rest = window['fluentCartRestVars'].rest;

const styles = {
    container: {
        position: 'relative',
    },
    optionsList: {
        margin: '0',
        padding: '0',
        listStyle: 'none',
        border: '1px solid #ccc',
        borderRadius: '4px',
        maxHeight: '200px',
        overflowY: 'auto',
    },
    optionItem: {
        padding: '8px 12px',
        cursor: 'pointer',
        transition: 'background-color 0.2s',
        backgroundColor: '#fff',
    },
    optionItemHover: {
        backgroundColor: '#f0f0f0',
    },
    optionItemSelected: {
        backgroundColor: '#e0e0e0',
    },
    noResults: {
        padding: '8px 12px',
        color: '#666',
        fontStyle: 'italic',
    },
    loading: {
        padding: '8px 12px',
        color: '#666',
        textAlign: 'center',
    }
};

const SearchableSelect = ({value, onChange}) => {
    const [searchTerm, setSearchTerm] = useState('');
    const [options, setOptions] = useState({});
    const [hoveredIndex, setHoveredIndex] = useState(null);
    const [loading, setLoading] = useState(false);
    const searchProducts = async (search, includeIds) => {

        setLoading(true);
        try {
            const queryParams = {
                "active_view": 'publish',
                "per_page": 10,
                "page": 1,
                "search": search,
                'filter_type': 'simple',
                'sort_by': 'ID',
                'with': ['detail', 'variants']
            };

            if (includeIds) {
                queryParams['include_ids'] = [includeIds];
            }

            const response = await apiFetch({
                path: addQueryArgs('fluent-cart/v2/products', queryParams),
                headers: {
                    'X-WP-Nonce': rest.nonce
                }
            });

            const newOptions = response.products.data.reduce((acc, product) => {
                acc[product.ID.toString()] = {
                    value: product.ID.toString(),
                    label: product.post_title,
                    product: product
                };
                return acc;
            }, {});

            setOptions(newOptions);

        } catch (error) {
            console.error('Error loading products:', error);
        } finally {
            setLoading(false);
        }
    };
    const debouncedSearch = useDebounce((term) => {
        searchProducts(searchTerm)
    }, 500);



    const {useEffect} = wp.element;

    useEffect(() => {
        searchProducts('', value);
    }, []);

    return (
        <div>
            <div style={styles.container}>
                <TextControl
                    placeholder={blocktranslate("Search products...")}
                    value={searchTerm}
                    onChange={(newSearch) => {
                        setSearchTerm(newSearch);
                        debouncedSearch(newSearch);
                    }}
                />
                {loading ? (
                    <div style={styles.loading}>{blocktranslate("Loading...")}</div>
                ) : (
                    <ul style={styles.optionsList}>
                        {Object.values(options).map((option, index) => (
                            <li
                                key={option.value}
                                onClick={() => onChange(option)}
                                onMouseEnter={() => setHoveredIndex(index)}
                                onMouseLeave={() => setHoveredIndex(null)}
                                style={{
                                    ...styles.optionItem,
                                    ...(hoveredIndex === index ? styles.optionItemHover : {}),
                                    ...(value === option.value ? styles.optionItemSelected : {})
                                }}
                            >
                                {option.label}
                            </li>
                        ))}
                        {options.length === 0 && searchTerm && (
                            <li style={styles.noResults}>{blocktranslate("No products found")}</li>
                        )}
                    </ul>
                )}
            </div>
        </div>

    );
};

export default SearchableSelect;