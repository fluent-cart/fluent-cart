import { nextTick } from 'vue';
import ProductBaseModel from "@/Models/Product/ProductBaseModel";
import Rest from "@/utils/http/Rest";
import Notify from "@/utils/Notify";
import translate from "@/utils/translator/Translator";

class BulkEditModel extends ProductBaseModel {

    beforeInit() {
        this.data['products'] = [];
        this.data['dirty'] = new Set();
        this.data['loading'] = false;
        this.data['saving'] = false;
        this.data['pagination'] = {
            page: 1,
            per_page: 5,
            total: 0,
            hasMore: false,
        };
        this.data['saveProgress'] = {
            total: 0,
            completed: 0,
            errors: [],
            updatedIds: [],
        };
        this.data['savingIds'] = new Set();
        this.data['savedIds'] = new Set();
        this.data['productErrors'] = new Map();
        // Filter state
        this.data['advanceFilters'] = [[]];
        this.data['filterType'] = 'simple';
        this.data['selectedView'] = 'all';
        this.data['searching'] = false;
        this.data['search'] = '';
        this.data['duplicatingIds'] = new Set();
        this.data['deletingIds'] = new Set();
        this.data['categoryOptions'] = [];
    }

    // --- Tab methods (FilterTabs adapter) ---

    getTabs() {
        return {
            all: translate("All"),
            publish: translate("Published"),
            draft: translate("Draft"),
            physical: translate('Physical'),
            digital: translate("Digital"),
        };
    }

    getTabsCount() {
        return Object.values(this.getTabs()).length;
    }

    getSelectedTab() {
        return this.data.selectedView;
    }

    handleTabChanged(viewKey) {
        if (viewKey === this.data.selectedView) return;
        this.data.selectedView = viewKey;
        this.fetchProducts({}, false);
    }

    // --- Search methods ---

    isSearching() {
        return this.data.searching !== false;
    }

    openSearch() {
        this.data.searching = true;
    }

    closeSearch() {
        this.data.searching = false;
        this.data.search = '';
        this.fetchProducts({}, false);
    }

    search() {
        this.fetchProducts({}, false);
    }

    useFullWidthSearch() {
        return false;
    }

    getSearchHint() {
        return translate("Search by Id, product title or variation title");
    }

    getSearchGuideOptions() {
        return null;
    }

    // --- Advanced filter methods ---

    isUsingAdvanceFilter() {
        return this.data.filterType === 'advanced';
    }

    isUsingSimpleFilter() {
        return this.data.filterType === 'simple';
    }

    isAdvanceFilterEnabled() {
        return this.getAdvanceFilterOptions() !== null;
    }

    getAdvanceFilterOptions() {
        return window.fluentCartAdminApp?.filter_options?.product_filter_options?.advance || null;
    }

    onFilterTypeChanged(filterType) {
        if (filterType === 'advanced') {
            this.data.searching = false;
            this.data.search = '';
        } else {
            this.data.advanceFilters = [[]];
        }
        this.fetchProducts({}, false);
    }

    applyAdvancedFilter(isRemoving = false) {
        this.fetchProducts({}, false);
    }

    addAdvanceFilterGroup() {
        this.data.advanceFilters.push([]);
    }

    removeAdvanceFilterGroup(index) {
        if (this.data.advanceFilters.length > 1) {
            this.data.advanceFilters.splice(index, 1);
        }
    }

    clearAdvanceFilter() {
        this.data.advanceFilters = [[]];
        this.fetchProducts({}, false);
    }

    // --- Stub methods for FilterTabs compatibility ---

    getColumnWidthStorageKey() {
        return 'bulk_edit_column_widths';
    }

    getColumnStorageKey() {
        return 'bulk_edit_columns';
    }

    getToggleableColumns() {
        return [
            { label: 'Image', value: 'image' },
            { label: 'SKU', value: 'sku' },
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

    getSortableColumns() {
        return [];
    }

    markDirty(postId) {
        if (this._suppressDirty) return;
        this.data.dirty.add(postId);
        this.data.dirty = new Set(this.data.dirty);
        if (this.data.savedIds.has(postId)) {
            this.data.savedIds.delete(postId);
            this.data.savedIds = new Set(this.data.savedIds);
        }
        if (this.data.productErrors.has(postId)) {
            this.data.productErrors.delete(postId);
            this.data.productErrors = new Map(this.data.productErrors);
        }
    }

    get dirtyCount() {
        return this.data.dirty.size;
    }

    getDirtyProducts() {
        return this.data.products.filter(p => this.data.dirty.has(p.ID));
    }

    chunkArray(array, size) {
        const chunks = [];
        for (let i = 0; i < array.length; i += size) {
            chunks.push(array.slice(i, i + size));
        }
        return chunks;
    }

    async fetchProducts(filters = {}, append = false) {
        this.data.loading = true;

        try {
            const params = {
                per_page: this.data.pagination.per_page,
                page: append ? this.data.pagination.page + 1 : 1,
            };

            if (this.data.filterType === 'advanced') {
                params.advanced_filters = JSON.stringify(this.data.advanceFilters);
                params.filter_type = 'advanced';
            } else {
                if (this.data.search) params.search = this.data.search;
                if (this.data.selectedView && this.data.selectedView !== 'all') {
                    params.active_view = this.data.selectedView;
                }
            }

            Object.assign(params, filters);

            const response = await Rest.get('products/bulk-edit-data', params);

            const products = response.products || [];

            // Suppress markDirty while new components mount (el-switch/el-select
            // can fire @change on mount when value types don't strictly match)
            this._suppressDirty = true;

            if (append) {
                this.data.products.push(...products);
            } else {
                this.data.products = products;
                this.data.dirty = new Set();
                this.data.savedIds = new Set();
                this.data.productErrors = new Map();
            }

            nextTick(() => { this._suppressDirty = false; });

            this.data.pagination = {
                page: response.page || params.page,
                per_page: response.per_page || params.per_page,
                total: response.total || 0,
                hasMore: (response.page * response.per_page) < response.total,
            };
        } catch (error) {
            const message = error?.data?.message || 'Failed to fetch products';
            Notify.error(message);
        } finally {
            this.data.loading = false;
        }
    }

    isSavingProduct(postId) {
        return this.data.savingIds.has(postId);
    }

    isDirty(postId) {
        return this.data.dirty.has(postId);
    }

    isSaved(postId) {
        return this.data.savedIds.has(postId);
    }

    isRowDisabled(postId) {
        return this.isSavingProduct(postId);
    }

    isDuplicating(postId) {
        return this.data.duplicatingIds.has(postId);
    }

    isDeleting(postId) {
        return this.data.deletingIds.has(postId);
    }

    // --- Error helpers ---

    hasError(postId) {
        return this.data.productErrors.has(postId);
    }

    getError(postId) {
        const err = this.data.productErrors.get(postId);
        if (!err) return '';
        if (err.fields && Object.keys(err.fields).length) {
            return Object.values(err.fields).join('; ');
        }
        return err.message || '';
    }

    hasProductLevelError(postId) {
        const err = this.data.productErrors.get(postId);
        if (!err) return false;
        if (!err.fields || !Object.keys(err.fields).length) return !!err.message;
        return Object.keys(err.fields).some(key => !key.startsWith('variants.'));
    }

    getProductLevelError(postId) {
        const err = this.data.productErrors.get(postId);
        if (!err) return '';
        if (!err.fields || !Object.keys(err.fields).length) return err.message || '';
        const productFieldErrors = Object.entries(err.fields)
            .filter(([key]) => !key.startsWith('variants.'))
            .map(([, msg]) => msg);
        return productFieldErrors.join('; ') || '';
    }

    hasVariantError(postId, variantIndex) {
        const err = this.data.productErrors.get(postId);
        if (!err?.fields) return false;
        const prefix = `variants.${variantIndex}.`;
        return Object.keys(err.fields).some(key => key.startsWith(prefix));
    }

    getVariantError(postId, variantIndex) {
        const err = this.data.productErrors.get(postId);
        if (!err?.fields) return '';
        const prefix = `variants.${variantIndex}.`;
        const variantErrors = Object.entries(err.fields)
            .filter(([key]) => key.startsWith(prefix))
            .map(([, msg]) => msg);
        return variantErrors.join('; ');
    }

    hasFieldError(postId, field) {
        const err = this.data.productErrors.get(postId);
        return !!(err?.fields?.[field]);
    }

    hasVariantFieldError(postId, vi, field) {
        return this.hasFieldError(postId, `variants.${vi}.${field}`);
    }

    async saveProduct(product) {
        const postId = product.ID;
        if (this.data.savingIds.has(postId)) return;

        this.data.savingIds.add(postId);
        this.data.savingIds = new Set(this.data.savingIds);

        try {
            const response = await Rest.post('products/bulk-update', { products: [product] });

            if (response.updated?.length) {
                this.data.dirty.delete(postId);
                this.data.dirty = new Set(this.data.dirty);
                this.data.savedIds.add(postId);
                this.data.savedIds = new Set(this.data.savedIds);
                this.data.productErrors.delete(postId);
                this.data.productErrors = new Map(this.data.productErrors);
                Notify.success('Product updated successfully');
            } else if (response.errors?.length) {
                const err = response.errors[0];
                if (err?.fields) {
                    this.data.productErrors.set(postId, err);
                    this.data.productErrors = new Map(this.data.productErrors);
                }
                Notify.error(err?.message || 'Failed to save product');
            }
        } catch (error) {
            const message = error?.data?.message || 'Failed to save product';
            // Extract field errors from 422 response
            const errors = error?.data?.errors;
            if (errors?.length) {
                const err = errors[0];
                if (err?.fields) {
                    this.data.productErrors.set(postId, err);
                    this.data.productErrors = new Map(this.data.productErrors);
                }
            }
            Notify.error(message);
        } finally {
            this.data.savingIds.delete(postId);
            this.data.savingIds = new Set(this.data.savingIds);
        }
    }

    async saveProducts() {
        // Exclude already-saved products
        const dirtyProducts = this.getDirtyProducts().filter(p => !this.data.savedIds.has(p.ID));

        if (dirtyProducts.length === 0) {
            Notify.info('No changes to save');
            return;
        }

        const chunks = this.chunkArray(dirtyProducts, 10);

        this.data.saving = true;
        this.data.saveProgress = {
            total: chunks.length,
            completed: 0,
            errors: [],
            updatedIds: [],
        };

        try {
            for (const chunk of chunks) {
                // Mark current chunk as saving
                const chunkIds = chunk.map(p => p.ID);
                for (const id of chunkIds) {
                    this.data.savingIds.add(id);
                }
                this.data.savingIds = new Set(this.data.savingIds);

                try {
                    const response = await Rest.post('products/bulk-update', { products: chunk });

                    if (response.updated) {
                        this.data.saveProgress.updatedIds.push(...response.updated);
                        // Move to savedIds, remove from dirty
                        for (const id of response.updated) {
                            this.data.savedIds.add(id);
                            this.data.dirty.delete(id);
                            this.data.productErrors.delete(id);
                        }
                        this.data.savedIds = new Set(this.data.savedIds);
                        this.data.dirty = new Set(this.data.dirty);
                        this.data.productErrors = new Map(this.data.productErrors);
                    }
                    if (response.errors && response.errors.length) {
                        this.data.saveProgress.errors.push(...response.errors);
                        for (const err of response.errors) {
                            if (err.post_id && err.fields) {
                                this.data.productErrors.set(err.post_id, err);
                            }
                        }
                        this.data.productErrors = new Map(this.data.productErrors);
                    }
                } catch (error) {
                    const message = error?.data?.message || 'A chunk failed to save';
                    this.data.saveProgress.errors.push({ message });
                    // Extract field errors from 422 response
                    const errors = error?.data?.errors;
                    if (errors?.length) {
                        for (const err of errors) {
                            if (err.post_id && err.fields) {
                                this.data.productErrors.set(err.post_id, err);
                            }
                        }
                        this.data.productErrors = new Map(this.data.productErrors);
                    }
                }

                // Remove current chunk from savingIds
                for (const id of chunkIds) {
                    this.data.savingIds.delete(id);
                }
                this.data.savingIds = new Set(this.data.savingIds);

                this.data.saveProgress.completed++;
            }

            const { updatedIds, errors } = this.data.saveProgress;

            if (errors.length === 0) {
                Notify.success(`${updatedIds.length} product(s) updated successfully`);
            } else if (updatedIds.length > 0) {
                Notify.info(`${updatedIds.length} product(s) updated, ${errors.length} error(s)`);
            } else {
                Notify.error('All products failed to save');
            }
        } finally {
            this.data.saving = false;
        }
    }

    // --- Product operations ---

    async duplicateProduct(product) {
        const productId = product.ID;
        if (!productId || this.data.duplicatingIds.has(productId)) return;

        this.data.duplicatingIds.add(productId);
        this.data.duplicatingIds = new Set(this.data.duplicatingIds);

        try {
            const res = await Rest.post(`products/${productId}/duplicate`, {});
            const newProductId = res.product_id;

            if (newProductId) {
                const fetchRes = await Rest.get('products/bulk-edit-data', { search: newProductId, per_page: 1 });
                const newProducts = fetchRes.products || [];

                if (newProducts.length > 0) {
                    const index = this.data.products.indexOf(product);
                    this.data.products.splice(index + 1, 0, newProducts[0]);
                }
            }
        } catch (error) {
            const message = error?.data?.message || 'Failed to duplicate product';
            Notify.error(message);
        } finally {
            this.data.duplicatingIds.delete(productId);
            this.data.duplicatingIds = new Set(this.data.duplicatingIds);
        }
    }

    async duplicateProductWithVariants(product, keepVariantIds) {
        const productId = product.ID;
        if (!productId || this.data.duplicatingIds.has(productId)) return;

        this.data.duplicatingIds.add(productId);
        this.data.duplicatingIds = new Set(this.data.duplicatingIds);

        try {
            const res = await Rest.post(`products/${productId}/duplicate`, {});
            const newProductId = res.product_id;
            if (!newProductId) return;

            const fetchRes = await Rest.get('products/bulk-edit-data', { search: newProductId, per_page: 1 });
            const newProducts = fetchRes.products || [];
            if (!newProducts.length) return;

            const newProduct = newProducts[0];

            // Map by serial_index for stable matching (array position can shift after client-side edits)
            const originalVariants = product.variants || [];
            const keepSerialIndexes = new Set();
            for (const v of originalVariants) {
                if (keepVariantIds.includes(v.id) && v.serial_index != null) {
                    keepSerialIndexes.add(v.serial_index);
                }
            }

            if (newProduct.variants && keepSerialIndexes.size > 0) {
                newProduct.variants = newProduct.variants.filter(v => keepSerialIndexes.has(v.serial_index));
            }

            const index = this.data.products.indexOf(product);
            this.data.products.splice(index + 1, 0, newProduct);

            // Mark dirty so user can save the trimmed variant list
            this.markDirty(newProduct.ID);
        } catch (error) {
            const message = error?.data?.message || 'Failed to duplicate product';
            Notify.error(message);
        } finally {
            this.data.duplicatingIds.delete(productId);
            this.data.duplicatingIds = new Set(this.data.duplicatingIds);
        }
    }

    duplicateVariant(product, variant) {
        const clone = JSON.parse(JSON.stringify(variant));
        clone.variation_title = clone.variation_title ? clone.variation_title + ' (Copy)' : '';
        clone.sku = '';
        delete clone.id;
        delete clone.post_id;
        const index = product.variants.indexOf(variant);
        product.variants.splice(index + 1, 0, clone);
        this.markDirty(product.ID);
    }

    async deleteProduct(product) {
        const productId = product.ID;
        if (!productId || this.data.deletingIds.has(productId)) return;

        this.data.deletingIds.add(productId);
        this.data.deletingIds = new Set(this.data.deletingIds);

        try {
            await Rest.delete(`products/${productId}`);
            const index = this.data.products.indexOf(product);
            if (index !== -1) {
                this.data.products.splice(index, 1);
            }
            this.data.dirty.delete(productId);
            this.data.savedIds.delete(productId);
        } catch (error) {
            const msg = error?.data?.message || 'Failed to delete product';
            Notify.error(msg);
        } finally {
            this.data.deletingIds.delete(productId);
            this.data.deletingIds = new Set(this.data.deletingIds);
        }
    }

    removeVariant(product, variant) {
        if (product.variants.length <= 1) {
            Notify.error(translate('A product must have at least one variant.'));
            return false;
        }
        const index = product.variants.indexOf(variant);
        if (index !== -1) {
            product.variants.splice(index, 1);
        }
        this.markDirty(product.ID);
        return true;
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

export default BulkEditModel.init();
