import ProductBaseModel from "@/Models/Product/ProductBaseModel";
import Rest from "@/utils/http/Rest";
import Notify from "@/utils/Notify";
import {generateCid} from "@/utils/cid";

class BulkInsertModel extends ProductBaseModel {

    beforeInit() {
        this.data['products'] = [];
        this.data['saving'] = false;
        this.data['saveProgress'] = {
            total: 0,
            completed: 0,
            errors: [],
            createdIds: [],
        };
        this.data['savingIds'] = new Set();
        this.data['savedIds'] = new Set();
        this.data['productErrors'] = new Map();
        this.data['categoryOptions'] = [];
        this.data.dummies['product'] = {
            ID: '',
            post_title: '',
            post_name: '',
            post_content: '',
            post_excerpt: '',
            post_status: 'published',
            post_date: new Date(),
            comment_status: 'close',
            categories: [],
            variants: false,
        };
    }

    populateDummyProduct() {

        const product = {
            ...this.data.dummies.product
        };

        product._cid = generateCid();

        //This is important, or this will keep the object reference
        product['detail'] = {
            ...this.data.dummies.productDetail
        };

        // product['gallery'] = {
        //     ...this.data.dummies.gallery
        // };

        product['gallery'] = [];

        product['downloadable_files'] = {
            ...this.data.dummies.downloadableFile
        };

        // Create a default variant so SKU, payment type, etc. are editable for simple products
        product['variants'] = [this.getDummyVariation(product)];

        this.data.products.push(product)
    }

    clearProducts() {
        this.data.products = [];
    }

    // --- Status checks ---

    isSaving(cid) {
        return this.data.savingIds.has(cid);
    }

    isSaved(cid) {
        return this.data.savedIds.has(cid);
    }

    isRowDisabled(cid) {
        return this.isSaving(cid) || this.isSaved(cid);
    }

    // --- Error helpers ---

    hasError(cid) {
        return this.data.productErrors.has(cid);
    }

    getError(cid) {
        const err = this.data.productErrors.get(cid);
        if (!err) return '';
        if (err.fields && Object.keys(err.fields).length) {
            return Object.values(err.fields).join('; ');
        }
        return err.message || '';
    }

    hasProductLevelError(cid) {
        const err = this.data.productErrors.get(cid);
        if (!err) return false;
        if (!err.fields || !Object.keys(err.fields).length) return !!err.message;
        return Object.keys(err.fields).some(key => !key.startsWith('variants.'));
    }

    getProductLevelError(cid) {
        const err = this.data.productErrors.get(cid);
        if (!err) return '';
        if (!err.fields || !Object.keys(err.fields).length) return err.message || '';
        const productFieldErrors = Object.entries(err.fields)
            .filter(([key]) => !key.startsWith('variants.'))
            .map(([, msg]) => msg);
        return productFieldErrors.join('; ') || '';
    }

    hasVariantError(cid, variantIndex) {
        const err = this.data.productErrors.get(cid);
        if (!err?.fields) return false;
        const prefix = `variants.${variantIndex}.`;
        return Object.keys(err.fields).some(key => key.startsWith(prefix));
    }

    getVariantError(cid, variantIndex) {
        const err = this.data.productErrors.get(cid);
        if (!err?.fields) return '';
        const prefix = `variants.${variantIndex}.`;
        const variantErrors = Object.entries(err.fields)
            .filter(([key]) => key.startsWith(prefix))
            .map(([, msg]) => msg);
        return variantErrors.join('; ');
    }

    hasFieldError(cid, field) {
        const err = this.data.productErrors.get(cid);
        return !!(err?.fields?.[field]);
    }

    hasVariantFieldError(cid, vi, field) {
        return this.hasFieldError(cid, `variants.${vi}.${field}`);
    }

    chunkArray(array, size) {
        const chunks = [];
        for (let i = 0; i < array.length; i += size) {
            chunks.push(array.slice(i, i + size));
        }
        return chunks;
    }

    async saveProducts() {
        // Filter products that haven't been saved yet
        const allProducts = this.data.products;
        const unsaved = allProducts.filter(p => !this.data.savedIds.has(p._cid));

        if (unsaved.length === 0) {
            Notify.error('No products to save.');
            return;
        }

        const chunks = this.chunkArray(unsaved, 10);

        this.data.saving = true;
        this.data.productErrors = new Map();
        this.data.saveProgress = {
            total: chunks.length,
            completed: 0,
            errors: [],
            createdIds: [],
        };

        try {
            for (const chunk of chunks) {
                // Mark current chunk as saving
                const chunkCids = chunk.map(p => p._cid);
                for (const cid of chunkCids) {
                    this.data.savingIds.add(cid);
                }
                this.data.savingIds = new Set(this.data.savingIds);

                try {
                    const response = await Rest.post('products/bulk-insert', { products: chunk });

                    // Handle individually created products
                    if (response.created && response.created.length) {
                        this.data.saveProgress.createdIds.push(...response.created);
                        for (const item of response.created) {
                            this.data.savedIds.add(item._cid);
                            // Write back the DB id and view URL
                            const product = allProducts.find(p => p._cid === item._cid);
                            if (product) {
                                product.ID = item.id;
                                product.view_url = item.view_url || '';
                            }
                        }
                        this.data.savedIds = new Set(this.data.savedIds);
                    }

                    // Handle individual product errors
                    if (response.errors && response.errors.length) {
                        this.data.saveProgress.errors.push(...response.errors);
                        for (const err of response.errors) {
                            if (err._cid) {
                                this.data.productErrors.set(err._cid, {
                                    message: err.message,
                                    fields: err.fields || {},
                                });
                            }
                        }
                        this.data.productErrors = new Map(this.data.productErrors);
                    }
                } catch (error) {
                    // The error response may contain per-product errors with _cid
                    const perProductErrors = error?.data?.errors;
                    if (Array.isArray(perProductErrors) && perProductErrors.length) {
                        this.data.saveProgress.errors.push(...perProductErrors);
                        for (const err of perProductErrors) {
                            if (err._cid) {
                                this.data.productErrors.set(err._cid, {
                                    message: err.message,
                                    fields: err.fields || {},
                                });
                            }
                        }
                    } else {
                        const message = error?.data?.message || 'A chunk failed to save';
                        this.data.saveProgress.errors.push({ message });
                        for (const cid of chunkCids) {
                            this.data.productErrors.set(cid, { message, fields: {} });
                        }
                    }
                    this.data.productErrors = new Map(this.data.productErrors);
                }

                // Remove current chunk from savingIds
                for (const cid of chunkCids) {
                    this.data.savingIds.delete(cid);
                }
                this.data.savingIds = new Set(this.data.savingIds);

                this.data.saveProgress.completed++;
            }

            const { createdIds, errors } = this.data.saveProgress;

            if (errors.length === 0) {
                Notify.success(`${createdIds.length} product(s) created successfully`);
            } else if (createdIds.length > 0) {
                Notify.info(`${createdIds.length} product(s) created, ${errors.length} error(s)`);
            } else {
                Notify.error('All products failed to save');
            }
        } finally {
            this.data.saving = false;
        }
    }

    // --- Product operations ---

    async removeProduct(product) {
        // If this product was already saved to the database, delete it server-side too
        if (product.ID && this.data.savedIds.has(product._cid)) {
            try {
                await Rest.delete(`products/${product.ID}`);
            } catch (error) {
                const msg = error?.data?.message || 'Failed to delete product from server';
                Notify.error(msg);
                return;
            }
            this.data.savedIds.delete(product._cid);
            this.data.savedIds = new Set(this.data.savedIds);
        }
        const index = this.data.products.indexOf(product);
        if (index !== -1) {
            this.data.products.splice(index, 1);
        }
    }

    removeVariant(product, variant) {
        const index = product.variants.indexOf(variant);
        if (index !== -1) {
            product.variants.splice(index, 1);
        }
    }

    duplicateProduct(product) {
        const clone = JSON.parse(JSON.stringify(product));
        clone._cid = generateCid();
        clone.post_title = clone.post_title ? clone.post_title + ' (Copy)' : '';
        if (clone.variants && Array.isArray(clone.variants)) {
            clone.variants.forEach(v => { v.sku = ''; v._cid = generateCid(); });
        }
        const index = this.data.products.indexOf(product);
        this.data.products.splice(index + 1, 0, clone);
    }

    duplicateProductWithVariants(product, keepVariantIndexes) {
        const clone = JSON.parse(JSON.stringify(product));
        clone._cid = generateCid();
        clone.post_title = clone.post_title ? clone.post_title + ' (Copy)' : '';

        if (clone.variants && Array.isArray(clone.variants)) {
            const keepSet = new Set(keepVariantIndexes);
            clone.variants = clone.variants.filter((_, i) => keepSet.has(i));
            clone.variants.forEach(v => { v.sku = ''; v._cid = generateCid(); });
        }

        const index = this.data.products.indexOf(product);
        this.data.products.splice(index + 1, 0, clone);
    }

    duplicateVariant(product, variant) {
        const clone = JSON.parse(JSON.stringify(variant));
        clone._cid = generateCid();
        clone.variation_title = clone.variation_title ? clone.variation_title + ' (Copy)' : '';
        clone.sku = '';
        const index = product.variants.indexOf(variant);
        product.variants.splice(index + 1, 0, clone);
    }

    resetPaymentTypeDefaults(variant) {
        if (variant.other_info.payment_type === 'onetime') {
            variant.other_info.installment = 'no';
            variant.other_info.times = '';
            variant.other_info.manage_setup_fee = 'no';
            variant.other_info.signup_fee = '';
            variant.other_info.signup_fee_name = '';
        }
    }

    resetState() {
        this.data.products.splice(0);
        this.data.savedIds = new Set();
        this.data.savingIds = new Set();
        this.data.productErrors = new Map();
    }

    // --- Column visibility ---

    getColumnWidthStorageKey() {
        return 'bulk_insert_column_widths';
    }

    getColumnStorageKey() {
        return 'bulk_insert_columns';
    }

    getToggleableColumns() {
        return [
            { label: 'SKU', value: 'sku' },
            { label: 'Media', value: 'media' },
            { label: 'Categories', value: 'categories' },
            { label: 'Description', value: 'description' },
            { label: 'Short Description', value: 'short_description' },
            { label: 'Status', value: 'status' },
            { label: 'Product Type', value: 'product_type' },
            { label: 'Pricing Type', value: 'pricing_type' },
            { label: 'Payment Type', value: 'payment_type' },
            { label: 'Interval', value: 'interval' },
            { label: 'Trial Days', value: 'trial_days' },
            { label: 'Best Price', value: 'best_price' },
            { label: 'Compare-at Price', value: 'compare_price' },
            { label: 'Track Quantity', value: 'track_quantity' },
            { label: 'Stock', value: 'stock' },
        ];
    }

    // --- Categories ---

    flattenCategoryTree(terms, prefix = '') {
        const result = [];
        for (const term of terms) {
            const path = prefix ? `${prefix} > ${term.label}` : term.label;
            result.push({ label: path, value: path });
            if (term.children?.length) {
                result.push(...this.flattenCategoryTree(term.children, path));
            }
        }
        return result;
    }

    async fetchCategories() {
        try {
            const res = await Rest.get('products/fetch-term');
            const taxonomies = res.taxonomies || {};
            const catTaxonomy = Object.values(taxonomies).find(t => t.name === 'product-categories');
            if (catTaxonomy?.terms) {
                this.data.categoryOptions = this.flattenCategoryTree(catTaxonomy.terms);
            }
        } catch (e) { /* silent */ }
    }
}

export default BulkInsertModel.init();
