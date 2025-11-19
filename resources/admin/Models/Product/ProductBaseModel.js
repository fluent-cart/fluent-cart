import Model from "@/utils/model/Model";

class ProductBaseModel extends Model {
    data = {
        options: {
            status: [
                {
                    title: 'Published',
                    value: 'published'
                },
                {
                    title: 'Draft',
                    value: 'draft'
                }
            ],

            fulfilment: [
                {
                    title: 'Physical',
                    value: 'physical'
                },
                {
                    title: 'Digital',
                    value: 'digital'
                }
            ],
            variation: [
                {
                    title: 'Simple',
                    value: 'simple'
                },
                {
                    title: 'Simple Variations',
                    value: 'simple_variations'
                }
            ],
        },
        dummies: {

            productDetail: { //detail
                variation_type: 'simple',
                manage_stock: 1,
                fulfillment_type: 'physical',
                stock_availability: 'in-stock'
            },
            variation: {
                variation_title: '',
                item_price: 0,
                compare_price: 0,
                serial_index: 1,
                fulfillment_type: '',
                manage_stock :  1,
                total_stock :  100,
                available :  100,
                committed : 0,
                on_hold :  0,
                stock_status: 'in-stock',
            },
            variationDetail: {
                description: '',
                payment_type: 'onetime',
                times: '',
                repeat_interval: '',
                billing_summary: '',
                manage_setup_fee: 'no',
                signup_fee_name: '',
                signup_fee: '',
                setup_fee_per_item: 'no',
            },
            variationImage: {
                id: '',
                url: '',
                title: '',
            },
            gallery: {
                id: '',
                url: '',
                title: '',
            },
            downloadableFile: {
                title: '',
                type: '',
                driver: '',
                file_name: '',
                file_path: '',
                file_url: '',
                settings: '',
                serial: 1,
            }
        }
    };


    getDummyVariation(product) {
        const oldVariant = product?.variants;
        const variation = {...this.data.dummies.variation};
        variation['serial_index'] = Array.isArray(oldVariant) ? oldVariant.length + 1 : 1;
        variation['fulfillment_type'] = product?.detail?.fulfillment_type;
        variation['other_info'] = {...this.data.dummies.variationDetail};
        //variation['media'] = {...this.data.dummies.variationImage};
        variation['media'] = [];
        return variation;
    }





    ensureProductVariantIsArray = (product) => {
        if (!Array.isArray(product.variants)) {
            product.variants = [];
        }
    }

    addVariationToProduct(product) {
        this.ensureProductVariantIsArray(product);
            product.variants.push(
                this.getDummyVariation(product)
            )

    }

    handleVariationChanged(product, variationType) {
        if (variationType === 'simple_variations') {
            this.addVariationToProduct(product)
        } else {
            product.variants = [];
        }
    }

    get downloadableFileSchema(){
        return  {
            product_variation_id: [],
            title: '',
            type: '',
            driver: '',
            file_name: '',
            file_path: '',
            file_url: '',
            settings: {
                download_limit: '',
                download_expiry: '',
                bucket: '',
            },
            serial: ''
        };
    }

    addDummyDownloadableFiles(product) {
        const productWithDownloadableFiles = product;
        
        if (!Array.isArray(productWithDownloadableFiles['downloadable_files'])) {
            productWithDownloadableFiles['downloadable_files'] = [];
        }

        productWithDownloadableFiles['downloadable_files'].push(
            this.downloadableFileSchema
        );
    }
}

export default ProductBaseModel;
