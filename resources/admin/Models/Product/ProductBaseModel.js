import Model from "@/utils/model/Model";
import {generateCid} from "@/utils/cid";
import Storage from "@/utils/Storage";

export const STOCK_STATUS = {
    IN_STOCK: 'in-stock',
    OUT_OF_STOCK: 'out-of-stock',
};

class ProductBaseModel extends Model {
    data = {
        visibleColumns: [],
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
                stock_availability: STOCK_STATUS.IN_STOCK
            },
            variation: {
                variation_title: '',
                sku: '',
                item_price: 0,
                compare_price: 0,
                serial_index: 1,
                fulfillment_type: '',
                manage_stock :  1,
                total_stock :  100,
                available :  100,
                committed : 0,
                on_hold :  0,
                stock_status: STOCK_STATUS.IN_STOCK,
            },
            variationDetail: {
                description: '',
                payment_type: 'onetime',
                installment: 'no',
                times: '',
                repeat_interval: '',
                trial_days: '',
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
        variation._cid = generateCid();
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

    // --- Column width persistence ---

    getColumnWidthStorageKey() {
        return '';
    }

    saveColumnWidths(columns) {
        const key = this.getColumnWidthStorageKey();
        if (!key) return;
        const widths = {};
        columns.forEach(col => {
            if (col.key) widths[col.key] = col.width;
        });
        Storage.set(key, widths);
    }

    restoreColumnWidths(columns) {
        const key = this.getColumnWidthStorageKey();
        if (!key) return;
        const stored = Storage.get(key);
        if (stored && typeof stored === 'object') {
            columns.forEach(col => {
                if (col.key && stored[col.key] !== undefined) {
                    col.width = stored[col.key];
                }
            });
        }
    }

    // --- Column visibility ---

    getColumnStorageKey() {
        return '';
    }

    getToggleableColumns() {
        return [];
    }

    setupColumnVisibility() {
        const key = this.getColumnStorageKey();
        if (!key) return;
        const stored = Storage.get(key);
        if (Array.isArray(stored)) {
            this.data.visibleColumns = stored;
        } else {
            this.data.visibleColumns = this.getToggleableColumns().map(c => c.value);
        }
    }

    handleColumnVisibilityChange() {
        const key = this.getColumnStorageKey();
        if (key) {
            Storage.set(key, this.data.visibleColumns);
        }
    }

    isColumnVisible(key) {
        return this.data.visibleColumns.includes(key);
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

    /**
     * Recalculate stock_status on each variant and stock_availability on detail
     * when stock values change.
     */
    syncStockStatus(product) {
        if (!Array.isArray(product.variants)) return;
        const manageStock = product.detail?.manage_stock;

        if (!manageStock) {
            for (const v of product.variants) {
                v.stock_status = STOCK_STATUS.IN_STOCK;
            }
            if (product.detail) {
                product.detail.stock_availability = STOCK_STATUS.IN_STOCK;
            }
            return;
        }

        let allOutOfStock = true;
        for (const v of product.variants) {
            const avail = parseInt(v.available, 10) || 0;
            v.stock_status = avail > 0 ? STOCK_STATUS.IN_STOCK : STOCK_STATUS.OUT_OF_STOCK;
            if (avail > 0) allOutOfStock = false;
        }

        if (product.detail) {
            product.detail.stock_availability = allOutOfStock ? STOCK_STATUS.OUT_OF_STOCK : STOCK_STATUS.IN_STOCK;
        }
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
