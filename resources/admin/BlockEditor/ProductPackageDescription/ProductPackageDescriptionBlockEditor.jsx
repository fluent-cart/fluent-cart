import blocktranslate from "@/BlockEditor/BlockEditorTranslator";
import apiFetch from "@wordpress/api-fetch";
import InspectorSettings from "@/BlockEditor/ProductPackageDescription/Components/InspectorSettings";
import ErrorBoundary from "@/BlockEditor/Components/ErrorBoundary";
import {useSingleProductData} from "@/BlockEditor/ShopApp/Context/SingleProductContext";
import {Package} from "@/BlockEditor/Icons";

const {useBlockProps} = wp.blockEditor;
const {registerBlockType} = wp.blocks;
const {useEffect, useState, useMemo} = wp.element;
const {useSelect} = wp.data;
const {store: blockEditorStore} = wp.blockEditor;

const blockEditorData = window.fluent_cart_product_package_description_data;
const rest = window['fluentCartRestVars'].rest;

function formatDimensions(pkg) {
    const parts = [pkg.length, pkg.width, pkg.height].filter(v => v !== '' && v !== null && v !== undefined);
    if (!parts.length) return '';
    return parts.join(' \u00d7 ') + ' ' + (pkg.dimension_unit || 'cm');
}

registerBlockType(blockEditorData.slug + '/' + blockEditorData.name, {
    apiVersion: 3,
    title: blockEditorData.title,
    description: blockEditorData.description,
    icon: {
        src: Package,
    },
    category: "fluent-cart",
    example: {
        attributes: {
            show_name: true,
            show_dimensions: true,
            show_product_weight: true,
            show_total_weight: true,
        },
    },
    attributes: {
        show_name: {
            type: 'boolean',
            default: true,
        },
        show_dimensions: {
            type: 'boolean',
            default: true,
        },
        show_product_weight: {
            type: 'boolean',
            default: true,
        },
        show_total_weight: {
            type: 'boolean',
            default: true,
        },
        query_type: {
            type: 'string',
            default: 'default',
        },
        inside_product_info: {
            type: 'string',
            default: 'yes',
        },
    },
    edit: ({attributes, setAttributes, clientId}) => {
        const blockProps = useBlockProps({
            className: 'fct-package-description',
        });

        const [packages, setPackages] = useState([]);

        const singleProductData = useSingleProductData();

        const isInsideProductInfo = useSelect((select) => {
            const {getBlockParents, getBlockName} = select(blockEditorStore);
            const parents = getBlockParents(clientId);
            return parents.some((parentId) => {
                const name = getBlockName(parentId);
                return [
                    'fluent-cart/product-info',
                    'fluent-cart/products',
                    'fluent-cart/shopapp-product-container',
                    'fluent-cart/shopapp-product-loop',
                    'fluent-cart/product-carousel',
                    'fluent-cart/related-product',
                    'fluent-cart/product-template',
                ].includes(name);
            });
        }, [clientId]);

        useEffect(() => {
            setAttributes({
                inside_product_info: isInsideProductInfo ? 'yes' : 'no',
            });
        }, [isInsideProductInfo]);

        // Fetch shipping packages
        const [packagesFetchError, setPackagesFetchError] = useState(false);
        useEffect(() => {
            apiFetch({
                path: rest.url + '/shipping/packages',
                headers: {'X-WP-Nonce': rest.nonce},
            }).then((response) => {
                setPackages(response.packages || []);
                setPackagesFetchError(false);
            }).catch(() => {
                setPackagesFetchError(true);
            });
        }, []);

        // Resolve package for the current variant
        const packageData = useMemo(() => {
            if (!packages.length) return null;

            const product = singleProductData?.product;
            if (!product?.variants?.length) return null;

            const defaultVariantId = product?.detail?.default_variation_id;
            const variant = defaultVariantId
                ? (product.variants.find(v => v.id === defaultVariantId) || product.variants[0])
                : product.variants[0];

            if (!variant) return null;

            const packageSlug = variant?.other_info?.package_slug || '';
            if (packageSlug) {
                return packages.find(p => p.slug === packageSlug) || null;
            }

            // Fallback: default package
            const defaultPkg = packages.find(p => p.is_default);
            return defaultPkg || packages[0] || null;
        }, [singleProductData?.product, packages]);

        // Get variant's product weight
        const variantWeight = useMemo(() => {
            const product = singleProductData?.product;
            if (!product?.variants?.length) return 0;

            const defaultVariantId = product?.detail?.default_variation_id;
            const variant = defaultVariantId
                ? (product.variants.find(v => v.id === defaultVariantId) || product.variants[0])
                : product.variants[0];

            return parseFloat(variant?.other_info?.weight || 0);
        }, [singleProductData?.product]);

        const productWeightVal = variantWeight || 0;
        const packageWeightVal = packageData ? parseFloat(packageData.weight || 0) : 0;
        const totalWeightVal = productWeightVal + packageWeightVal;
        const weightUnit = packageData?.weight_unit || 'kg';

        const hasContent = packageData && (
            (attributes.show_name && packageData.name) ||
            (attributes.show_dimensions && (packageData.length || packageData.width || packageData.height)) ||
            (attributes.show_product_weight && productWeightVal) ||
            (attributes.show_total_weight && totalWeightVal)
        );

        return (
            <div {...blockProps}>
                <ErrorBoundary>
                    <InspectorSettings
                        attributes={attributes}
                        setAttributes={setAttributes}
                        packageData={packageData}
                    />

                    {!hasContent && (
                        <table className="fct-package-description__table fct-package-description__table--placeholder" role="presentation">
                            <tbody>
                                <tr>
                                    <th>{blocktranslate('Package')}</th>
                                    <td>{packagesFetchError ? blocktranslate('Failed to load') : blocktranslate('N/A')}</td>
                                </tr>
                            </tbody>
                        </table>
                    )}
                    {hasContent && (
                        <table className="fct-package-description__table" role="presentation">
                            <tbody>
                                {attributes.show_name && packageData.name && (
                                    <tr>
                                        <th>{blocktranslate('Package')}</th>
                                        <td>{packageData.name}</td>
                                    </tr>
                                )}
                                {attributes.show_dimensions && formatDimensions(packageData) && (
                                    <tr>
                                        <th>{blocktranslate('Dimensions')}</th>
                                        <td>{formatDimensions(packageData)}</td>
                                    </tr>
                                )}
                                {attributes.show_product_weight && productWeightVal > 0 && (
                                    <tr>
                                        <th>{blocktranslate('Weight')}</th>
                                        <td>{productWeightVal + ' ' + weightUnit}</td>
                                    </tr>
                                )}
                                {attributes.show_total_weight && totalWeightVal > 0 && packageWeightVal > 0 && (
                                    <tr>
                                        <th>{blocktranslate('Shipping Weight')}</th>
                                        <td>{totalWeightVal + ' ' + weightUnit}</td>
                                    </tr>
                                )}
                            </tbody>
                        </table>
                    )}
                </ErrorBoundary>
            </div>
        );
    },

    save: () => null,

    supports: {
        html: false,
        align: ["left", "center", "right"],
        typography: {
            fontSize: true,
            lineHeight: true,
            __experimentalFontFamily: true,
            __experimentalFontWeight: true,
            __experimentalFontStyle: true,
            __experimentalTextTransform: true,
            __experimentalLetterSpacing: true,
            __experimentalDefaultControls: {
                fontSize: true,
            },
        },
        color: {
            text: true,
            background: true,
        },
        spacing: {
            margin: true,
            padding: true,
        },
        __experimentalBorder: {
            color: true,
            radius: true,
            style: true,
            width: true,
        },
        shadow: true,
    },
});
