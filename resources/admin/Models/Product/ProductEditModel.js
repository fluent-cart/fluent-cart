import ProductBaseModel from "@/Models/Product/ProductBaseModel";
import {$confirm, handleSuccess, handleError, formatDate} from "@/Bits/common";
import {useRouter} from "vue-router";
import {getCurrentInstance} from "vue";
import {formatNumber} from "@/Bits/productService";
import translate from "@/utils/translator/Translator";
import Notify from "@/utils/Notify";
import dayjs from "dayjs";
import Rest from "@/utils/http/Rest";
import Confirmation from "@/utils/Confirmation";
import AppConfig from "@/utils/Config/AppConfig";
import Arr from "@/utils/support/Arr";
import {
    buildSimpleVariantUpdatePayload,
    buildVariantUpdatePayload,
    normalizeDefaultVariationIdForSave,
} from "@/Models/Product/productUpdatePayload";

class ProductEditModel extends ProductBaseModel {

    data = {
        product: {},
        original_product: {},
        product_changes: {},
        validationErrors: {},
        excerptWordCount: 0,
        maxExcerptWordCount: 0,
        hasChange: false,
        saving: false,
        product_snapshot: {},
        discardKey: 0,
        savedVariationLength: 0,
        productDownloadableModel: null,
        metaValue: null,
        hasChangeLongDescEditor: false,
        reloader: () => {
        },
        onProductUpdatedListener: {},
    };

    //productDownloadableModel = null;

    router = useRouter();

    addOnProductUpdatedListener(name, callback) {
        this.data.onProductUpdatedListener[name] = callback;
    }

    // Fire registered product-updated listeners. The main save paths notify all
    // listeners inline (a full save legitimately resets forms, refetches data,
    // etc.). Flows that only change part of the product — e.g. advanced-variation
    // regenerate, which persists variant options but nothing else — pass `names`
    // to notify just the listeners that need it (the bundle/variant-list refetch)
    // without invoking unrelated ones like the form-reset, which would clobber a
    // merchant's unsaved edits in other panels. Omit `names` to notify all.
    triggerProductUpdated(names = null) {
        const listeners = this.data.onProductUpdatedListener;
        const targets = Array.isArray(names) ? names : Object.keys(listeners);
        targets.forEach(name => {
            const callback = listeners[name];
            if (typeof callback === 'function') {
                callback(this.product);
            }
        });
    }

    setProductDownloadableModel(model) {
        this.data.productDownloadableModel = model;
    }

    setReloader(reloader) {
        this.data.reloader = reloader;
    }

    saveSnapshot() {
        this.data.product_snapshot = JSON.parse(JSON.stringify(this.data.product));
    }

    _mergeOtherInfo = (target, source) => {
        const src = JSON.parse(JSON.stringify(source));
        Object.keys(src).forEach(key => { target[key] = src[key]; });
        Object.keys(target).forEach(key => { if (!(key in src)) delete target[key]; });
    }

    _mergeSnapshot = (snapshot) => {
        const product = this.data.product;

        // Skip fields that are either identifiers or managed by their own endpoints
        const skipTop = new Set(['ID', 'guid', 'variants', 'detail', 'downloadable_files', 'product_terms', 'taxonomies', 'variantOptions']);

        Object.keys(snapshot).forEach(field => {
            if (skipTop.has(field)) return;
            const val = snapshot[field];
            product[field] = (val !== null && typeof val === 'object')
                ? JSON.parse(JSON.stringify(val))
                : val;
        });

        // Mutate detail in-place so Vue's reactive proxy chain is preserved for deeply
        // nested bindings like product.detail.other_info.sold_individually
        if (snapshot.detail && product.detail) {
            const snapshotDetail = JSON.parse(JSON.stringify(snapshot.detail));
            Object.keys(snapshotDetail).forEach(key => {
                if (key === 'other_info') return;
                product.detail[key] = snapshotDetail[key];
            });
            if (snapshotDetail.other_info) {
                if (!product.detail.other_info) product.detail.other_info = {};
                this._mergeOtherInfo(product.detail.other_info, snapshotDetail.other_info);
            }
        }

        // Merge variants by ID — variants added/deleted via their own endpoints are preserved
        // Mutate variant fields in-place (same reason as detail above)
        if (Array.isArray(snapshot.variants) && Array.isArray(product.variants)) {
            const snapById = {};
            snapshot.variants.forEach(v => { if (v.id) snapById[v.id] = v; });

            const skipVariant = new Set(['id', 'post_id', 'rowId', 'media', 'created_at', 'updated_at']);
            product.variants.forEach(variant => {
                const snap = snapById[variant.id];
                if (!snap) return; // created after snapshot via its own endpoint — preserve it
                Object.keys(snap).forEach(key => {
                    if (skipVariant.has(key)) return;
                    const val = snap[key];
                    if (key === 'other_info' && variant.other_info) {
                        this._mergeOtherInfo(variant.other_info, val);
                    } else {
                        variant[key] = (val !== null && typeof val === 'object')
                            ? JSON.parse(JSON.stringify(val))
                            : val;
                    }
                });
            });
        }
    }

    get product() {
        return this.data.product;
    }

    get validationErrors() {
        return this.data.validationErrors;
    }

    get excerptWordCount() {
        return this.data.excerptWordCount
    }

    get maxExcerptWordCount() {
        return this.data.maxExcerptWordCount
    }

    get hasChange() {
        return this.data.hasChange;
    }

    get hasChangeLongDescEditor() {
        return this.data.hasChangeLongDescEditor;
    }

    get saving() {
        return this.data.saving;
    }

    beforeInit() {

        this.data.product = {
            ID: '',
            post_title: '',
            post_name: '',
            post_content: '',
            post_excerpt: '',
            post_status: 'publish',
            post_date: new Date(),
            comment_status: 'close',
            variants: false,
        };

        this.vueInstance = getCurrentInstance().ctx;
    }

    setProduct(product) {
        let productBadge = document.querySelector('.fct-admin-product-info .badge');
        if (productBadge && product.post_status === 'future') {
            /* translators: %s is the post date */
            productBadge.innerText = translate('Publishes on: %s', formatDate(product.post_date, true));
            productBadge.classList.add('warning');
        }
        this.data.product = product;
        this.data.original_product = {...product};
    }

    setValidationErrors(validationErrors) {
        this.data.validationErrors = validationErrors;
    }

    setExcerptWordCount(excerptWordCount) {
        this.data.excerptWordCount = excerptWordCount;
    }

    setMaxExcerptWordCount(maxExcerptWordCount) {
        this.data.maxExcerptWordCount = maxExcerptWordCount;
    }

    setHasChange(hasChange) {
        this.data.hasChange = hasChange;
    }

    setHasChangeLongDescEditor(hasChangeLongDescEditor) {
        this.data.hasChangeLongDescEditor = hasChangeLongDescEditor;
    }

    setSaving(saving) {
        this.data.saving = saving;
    }

    isInDraft() {
        return this.product.post_status === 'draft';
    }

    viewUrl() {
        return this.product.view_url ?? "";
    }

    variantsLength() {
        return this.product.variants.length ?? 0;
    }

    /* Pricing start */

    addDummyVariant = () => {
        return {
            post_id: this.product.detail.post_id,
            serial_index: this.variantsLength() + 1,
            variation_title: '',
            item_price: '',
            compare_price: '',
            manage_cost: 'yes',
            item_cost: '',
            profit: '--',
            profit_margin: '--',
            manage_stock: this.product.detail.manage_stock,
            stock_status: 'in-stock',
            total_stock: 1,
            on_hold: 0,
            committed: 0,
            available: 1,
            fulfillment_type: this.product.detail.fulfillment_type,
            media: [],
            other_info: {
                description: '',
                payment_type: 'onetime',
                tax_class: 'standard',
                tax_exempt: 'no',
                tax_inclusion: '',
                times: '',
                repeat_interval: 'yearly',
                billing_summary: '',
                manage_setup_fee: 'no',
                signup_fee_name: '',
                signup_fee: '',
                setup_fee_per_item: 'no',
                package_slug: '',
                weight: null,
                weight_unit: window.fluentCartAdminApp?.shop?.weight_unit || 'kg',
                //purchasable: 'yes',
            },
            downloadable: 'true',
        };
    }

    // Amounts stay in CENTS end to end — see the note on ProductRoute.vue's
    // formatPricing. This used to divide by 100 for the old dollars-in contract.
    formatPricing = (prices) => {
        prices.forEach((pricing, idx) => {
            // Cents, end to end. #2223 fixed the same round-trip bug by storing a
            // canonical dot-decimal string here; taking cents removes the class of
            // bug instead — there is no string to mis-parse and no separator to
            // truncate at. PriceInput renders these as dollars for the merchant.
            pricing.item_price = (pricing.item_price) ? Number(pricing.item_price) : 0;
            pricing.item_cost = (pricing.item_cost) ? Number(pricing.item_cost) : 0;
            pricing.compare_price = (pricing.compare_price) ? Number(pricing.compare_price) : 0;

            if (pricing.other_info) {
                pricing.other_info.signup_fee = (pricing.other_info.signup_fee) ? Number(pricing.other_info.signup_fee) : 0;
            }

            pricing.rowId = idx;
        });

        return prices;
    }

    onChangePricing = (name, index, value) => {
        this.ensureVariationIndex(index);

        this.product.variants[index][name] = value;

        if (name === 'item_price') {
            // this.product.variants[index]['item_price'] = ( value <= 0 )? 1 : value
            this.onChangePricingPayment(this.product.variants[index], value, index);
        }
        if (!this.data.product_changes.detail) {
            this.data.product_changes.detail['id'] = this.product.detail.id;
        }
        this.data.product_changes.variants[index][name] = value;
        this.data.product_changes.variants[index]['id'] = this.product.variants[index]['id'];

        if (name === 'item_price' || name === 'compare_price') {
            this.validateComparePrice(index);
        }

        this.setHasChange(true)
    }

    updateLongDescEditorChange = (activeEditor) => {
        this.setHasChangeLongDescEditor(true);

        // add a request to update the long description editor mode
        Rest.post(`products/${this.product.ID}/update-long-desc-editor-mode`, {
            active_editor: activeEditor ?? 'wp-editor'
        })
            .then(response => {
                Notify.success(response.message);
            })
            .catch((errors) => {
                if (errors && errors.message) {
                    Notify.error(errors);
                }
            })
            .finally(() => {
                this.setHasChangeLongDescEditor(false);
            });
    }

    onChangePricingPayment = (variant, value, index) => {
        if (variant.item_price !== "" && variant.other_info.repeat_interval !== "") {
            let occurrence = !parseInt(variant.other_info.times) ? translate('Until Cancel') :
                /* translators: %s is the number of times */
                translate('for %s Times', variant?.other_info?.times);
            // item_price is in cents; this string is shown to the merchant and
            // stored for display, so it has to be formatted as currency —
            // interpolating the raw value rendered "1999 yearly" for $19.99.
            const billingSummary = `${formatNumber(variant.item_price, true)} ${variant.other_info.repeat_interval} ${occurrence}`;
            this.data.product_changes.variants[index]['billing_summary'] = billingSummary;
            this.data.product_changes.variants[index]['id'] = variant.id;
            this.data.product_changes.detail['id'] = this.product.detail.id;
            return variant.other_info.billing_summary = billingSummary;
        }
        this.data.product_changes.variants[index]['billing_summary'] = '';
        this.data.product_changes.variants[index]['id'] = variant.id;
        this.data.product_changes.detail['id'] = this.product.detail.id;
        return variant.other_info.billing_summary = "";
    }

    /**
     * Renumber the variations after a drag-reorder.
     *
     * The new order is merged into whatever is already staged rather than
     * replacing it: this used to overwrite product_changes.variants outright, so
     * editing three prices and then dragging a row sent only the serial_index
     * values. The prices stayed visible in the grid — they live on
     * this.product.variants — and were silently never saved.
     *
     * Rebuilding the array in the new order is safe because
     * buildVariantUpdatePayload matches records by id, not by array position.
     */
    updateVariantSerialIndexes(variants) {
        variants.forEach((variant, index) => {
            variant.serial_index = index + 1;
        });

        const staged = Array.isArray(this.data.product_changes.variants)
            ? this.data.product_changes.variants
            : [];

        const stagedById = new Map();
        staged.filter(Boolean).forEach((record) => {
            if (record.id !== undefined && record.id !== null) {
                stagedById.set(String(record.id), record);
            }
        });

        this.data.product_changes.variants = variants.map((variant) => ({
            ...(stagedById.get(String(variant.id)) || {}),
            id: variant.id,
            serial_index: variant.serial_index,
        }));

        this.setHasChange(true);
    }

    // Bulk-apply field changes (price, compare price, status, stock) to many
    // variants at once. Used by the Advanced Variation editor's bulk action
    // bar; served by ProductVariationController::bulkUpdate.
    bulkUpdateVariants(updates) {
        return Rest.post('products/variants/bulk-update', {updates});
    }

    onUploadPricingMedia = (name, index, value) => {
        this.product.variants[index][name] = [];
        this.product.variants[index][name] = [...this.product.variants[index][name], ...value]

        const variantId = this.product.variants[index].id;

        Rest.post(`products/variants/${variantId}/setMedia`, {'media': this.product.variants[index]['media']})
            .then(response => {
                Notify.success(response.message);
                //this.saveSnapshot();
            })
            .catch((errors) => {
                if (errors.status_code == '422') {
                    Notify.validationErrors(errors);
                } else {
                    Notify.error(errors.data?.message);
                }
            })
            .finally(() => {

            });
    }

    afterCreatingOrUpdatingPricing = (index, row) => {
        row['created_at'] = row['created_at'] ?? (
            new Date().toLocaleDateString() + '' + new Date().toLocaleTimeString()
        )
        if (index !== undefined && index != null) {
            this.product.variants[index] = row;
        } else {
            row.rowId = this.variantsLength() > 0 ?
                this.data.product.variants[this.variantsLength() - 1].rowId + 1
                : this.variantsLength();
            this.data.product.variants.push(row);
            index = this.variantsLength() - 1;
        }


        //also update the downloadable product of productDownloadableModel
        this.data.productDownloadableModel.setDownloadableFiles(
            this.data.product.downloadable_files
        );


    }

    createOrUpdatePricing = (variants) => {
        this.setSaving(true)
        this.setValidationErrors({})

        let req = '';

        if (variants.id != null) {
            req = Rest.post(`products/variants/${variants.id}`, {'variants': variants});
        } else {
            req = Rest.post(`products/variants`, {'variants': variants});
        }

        return req.then(response => {
            Notify.success(response.message);
            Object.values(this.data.onProductUpdatedListener).forEach(callback => {
                if(typeof callback === 'function'){
                    callback(this.product);
                }
            })
            this.saveSnapshot();
            return response;
        })
            .catch((errors) => {
                if (errors.status_code == '422') {
                    Notify.validationErrors(errors);
                    this.setValidationErrors(errors.data)
                } else {
                    Notify.error(errors.data?.message);
                }
            })
            .finally(() => {
                this.setSaving(false)
            });
    }

    updatePricingValue = (name, value, index, variant, modeType) => {
        if (index.includes('.')) {
            index = index.split('.').pop(); // Extract the last part (e.g., "0" from "variants.0")
        }
        this.ensureVariationIndex(index);
        if (index !== undefined) {
            this.clearValidationError(`${index}.${name}`)
        }

        variant[name] = value

        if (name === 'item_price') {
            // variant['item_price'] = ( value <= 0 )? 1 : value
            this.onChangePricingPayment(variant, value, index)
        }

        // No product-level write here. Fulfilment is per variation on every type
        // except simple, where the single variation IS the product — the simple
        // branch below stages detail.fulfillment_type for that case. Writing it
        // unconditionally meant flipping one variant to Digital in the drawer and
        // closing without saving still posted the whole product as digital on the
        // next Update. The variant's own column is staged generically below.

        // if(name === 'compare_price') {
        //   variant['compare_price'] = ( value <= 0 )? 1 : value
        // }

        // if(parseInt(variant['compare_price']) < parseInt(variant['item_price'])) {
        //   variant['compare_price'] = variant['item_price']
        // }

        if (name === 'item_cost') {
            const parsed = parseFloat(value);
            variant['item_cost'] = (isNaN(parsed) || parsed < 0) ? 0 : parsed;
        }

        if (parseFloat(variant['item_cost']) > parseFloat(variant['item_price'])) {
            variant['item_cost'] = 0;
        }

        this.data.product_changes.variants[index][name] = (name === 'item_cost') ? variant[name] : value;
        this.data.product_changes.variants[index]['id'] = variant.id;
        this.data.product_changes.detail['id'] = this.product.detail.id;


        if (this.product.detail.variation_type === 'simple') {

            if (name === 'fulfillment_type') {
                // check if fulfillment_type exist in product_changes.details
                if (!this.data.product_changes.detail) {
                    this.data.product_changes.detail = {};
                }

                this.data.product_changes.detail.fulfillment_type = value;
                this.data.product_changes.variants[index]['fulfillment_type'] = value;
            }

            this.product.variants[0] = variant;
            this.setHasChange(true)
        }

        if (name === 'item_price' || name === 'compare_price') {
            this.validateComparePrice(index);
        }
    }

    updatePricingOtherValue = (name, value, index = null, variant, modeType = '') => {
        this.ensureVariationIndex(index);

        // trial_days and installment were missing, so updatePricingOtherValue ran no
        // branch for them at all and staged nothing. They survived only on simple
        // products, where the fall-through below copies the whole live variant, and
        // in the drawer, which saves through its own endpoint — anywhere else the
        // edit was silently dropped. manage_cost is deliberately NOT here: it is a
        // top-level column, not an other_info key, and routes through
        // updatePricingValue.
        const variantOtherInfoFields = ['payment_type', 'times', 'repeat_interval', 'trial_days', 'installment', 'manage_setup_fee', 'signup_fee_name', 'signup_fee', 'billing_summary', 'setup_fee_per_item', 'purchasable', 'tax_class', 'tax_exempt', 'tax_inclusion', 'weight', 'length', 'width', 'height', 'package_slug', 'weight_unit', 'manage_stock'];

        if (variantOtherInfoFields.includes(name)) {
            // Tax settings belong to the variation payload so product saves and
            // the calculator both read the same contract.
            if (index !== undefined) {
                this.clearValidationError(`${index}.other_info.${name}`)
            }


            variant['other_info'][name] = value;
            //if other info is not present add this
            if (!this.data.product_changes.variants) {
                this.data.product_changes.variants = [];
            }
            if (!this.data.product_changes.variants[index]['other_info']) {
                this.data.product_changes.variants[index]['other_info'] = {};
            }
            this.data.product_changes.variants[index]['other_info'][name] = value;

            if (variant['other_info']['payment_type'] === 'subscription') {
                if (name === 'times' && parseInt(value) < 0) {
                    variant['other_info']['times'] = 0;
                }
                variant['other_info']['times'] = variant['other_info']['times'] ?? '';
                variant['other_info']['repeat_interval'] = variant['other_info']['repeat_interval'] ?? '';
                variant['other_info']['trial_days'] = variant['other_info']['trial_days'] ?? 0;
                variant['other_info']['billing_summary'] = variant['other_info']['billing_summary'] ?? '';
                variant['other_info']['manage_setup_fee'] = variant['other_info']['manage_setup_fee'] ?? 'no';
                variant['other_info']['signup_fee_name'] = variant['other_info']['signup_fee_name'] ?? '';
                variant['other_info']['signup_fee'] = variant['other_info']['signup_fee'] ?? '';
                variant['other_info']['setup_fee_per_item'] = variant['other_info']['setup_fee_per_item'] ?? 'no';
            }

            this.data.product_changes.variants[index]['other_info'] = variant['other_info'];
            this.data.product_changes.variants[index]['id'] = variant.id;
            this.data.product_changes.detail['id'] = this.product.detail.id;

            // Tax fields are not payment fields; routing them through the
            // billing-summary logic corrupts the pending save payload.
            if (['payment_type', 'times', 'repeat_interval', 'manage_setup_fee', 'signup_fee_name', 'signup_fee', 'billing_summary', 'setup_fee_per_item', 'purchasable'].includes(name)) {
                this.onChangePricingPayment(variant, value, index);
            }
        }

        if (['media'].includes(name)) {
            variant.media = [];
            variant.media = [...variant.media, ...value];
            this.data.product_changes.variants[index]['media'] = variant['media'];
            this.data.product_changes.variants[index]['id'] = variant.id;
            this.data.product_changes.detail['id'] = this.product.detail.id;
        }

        if (['description'].includes(name)) {
            if (index !== undefined) {
                this.clearValidationError(`${index}.other_info.${name}`)
            }
            if (!this.data.product_changes.variants[index]['other_info']) {
                this.data.product_changes.variants[index]['other_info'] = {};
            }
            variant['other_info'][name] = value;
            this.data.product_changes.variants[index]['other_info'][name] = value;
            this.data.product_changes.variants[index]['id'] = variant.id;
            this.data.product_changes.detail['id'] = this.product.detail.id;
        }

        if (this.product.detail.variation_type === 'simple') {
            this.product.variants[0] = variant;
            this.data.product_changes['variants'] = [];
            this.data.product_changes['variants'][0] = variant;
            this.data.product_changes['variants'][0]['id'] = variant.id;
            this.data.product_changes.detail['id'] = this.product.detail.id;
        }

        if (modeType === 'add') {
            this.setHasChange(true)
        }
    }

    afterDeletingPricing = (index) => {
        this.product.variants.splice(index, 1);
    }

    deletePricing = (id, index) => {

        return Confirmation.ofDelete(
            translate("Are you sure, you want to delete this price?")
        ).then(() => {
            if (id !== undefined) {
                return Rest.delete(`products/variants/${id}`)
                    .then(response => {
                        Notify.success(response.message);
                        this.afterDeletingPricing(index);
                        this.saveSnapshot();
                    })
                    .catch((errors) => {
                        if (errors?.status_code == '422') {
                            Notify.validationErrors(errors);
                        } else {
                            Notify.error(errors?.data);
                        }
                        throw errors;
                    })
            }
        });
    }

    /* Pricing end */

    /* Downloadable Assets start */
    getDownloadableHeaderClass = () => {
        const hasDownloadableFiles =
            this.product.downloadable_files &&
            Array.isArray(this.product.downloadable_files) &&
            this.product.downloadable_files.length > 0;


        return {
            '!border-0': this.product.detail?.manage_downloadable == '0' || !hasDownloadableFiles,
        };
    }

    /**
     * Set downloadable variant options those variant's downloadable value is true
     */



    addDummyDownloadableFile() {
        super.addDummyDownloadableFiles(this.product)
        this.setHasChange(true)
    }


    /* Downloadable Assets end */

    /* Inventory Management start */
    onChangeInventoryStatus = (value) => {
        this.product.variants.filter(variant => {
            // return variant.adjusted_quantity = this.product.detail.manage_stock;
            return variant.manage_stock = value;
        });
        this.setHasChange(true);
    }

    ensureVariationIndex = (index) => {
        if (!this.data.product_changes?.detail) {
            this.data.product_changes.detail = {};
        }

        if (!this.data.product_changes.variants) {
            this.data.product_changes.variants = [];
        }

        if (!this.data.product_changes.variants[index]) {
            this.data.product_changes.variants[index] = {};
        }
    }

    /**
     * Resolve the variation these stock helpers act on.
     *
     * The drawer passes fieldKey="variants" where the inventory grid passes a row
     * index, so indexing product.variants blindly returned undefined. Both callers
     * already hand over the variation object, and on a non-simple product the
     * drawer's copy is a clone (ProductPricingForm cloneVariant) — writing through
     * the index would edit the grid row instead of the row being saved.
     */
    resolveStockVariant = (index, variant) => {
        if (variant) {
            return variant;
        }

        return Array.isArray(this.product.variants) ? this.product.variants[index] : undefined;
    }

    /**
     * Recompute "Adjusted by" from a typed "New Stock".
     *
     * Nothing is staged into product_changes: `new_stock` and `adjusted_quantity`
     * are not columns (VARIANT_MUTABLE_FIELDS drops both), and the real write is
     * the Apply button's own PUT products/{id}/update-inventory/{variantId}. The
     * previous version reset product_changes.variants to [] whenever the lookup
     * missed — which is exactly when it missed — destroying every price or title
     * edit staged before the merchant touched this field.
     */
    onChangeNewStock = (name, value, index, variant = null) => {
        const target = this.resolveStockVariant(index, variant);

        if (!target) {
            return;
        }

        if (value === '') {
            target['new_stock'] = 0;
        }

        const oldStock = parseInt(target['total_stock'] ?? 0);
        const newStock = parseInt(target['new_stock'] ?? 0);

        target['adjusted_quantity'] = newStock - oldStock;
    }

    /**
     * Recompute "New Stock" from a typed "Adjusted by". See onChangeNewStock.
     */
    onChangeAdjustedQuantity = (name, value, index, variant = null) => {
        const target = this.resolveStockVariant(index, variant);

        if (!target) {
            return;
        }

        if (value === '') {
            value = 0;
        }

        const newStock = parseInt(target['total_stock'] ?? 0) + parseInt(value);

        target['new_stock'] = (newStock < 0) ? 0 : newStock;
    }
    /* Inventory Management end */

    onChangeInputField = (name, value, triggerChanges = true) => {

        if (name === 'post_title' && this.product.detail.variation_type === 'simple') {
            if (Array.isArray(this.product.variants) && this.variantsLength() > 0) {
                this.product.variants[0]['variation_title'] = value;
                if (!this.data.product_changes?.variants) {
                    this.data.product_changes.variants = [];
                }
                this.ensureVariationIndex(0);
                this.data.product_changes.variants[0]['variation_title'] = value;
                this.data.product_changes.variants[0]['id'] = this.product.variants[0]['id'];
            }
        }

        if (name === 'post_date') {
            this.product[name] = dayjs(value).format('YYYY-MM-DDTHH:mm:ssZ');
            this.data.product_changes[name] = this.product[name];
        }

        if (name === 'default_variation_id') {
            if (!this.data.product_changes.detail) {
                this.data.product_changes.detail = {};
            }
            if (!this.product.detail) {
                this.product.detail = {};
            }

            // Stage the detail row id so the backend update endpoint
            // PATCHes the right row, and flip hasChange so the top
            // "Update" button enables. The branch was previously only
            // staging the value, so a picked default would render in
            // the el-select but never persist on save.
            //
            // Clearing the el-select emits `undefined`, which JSON.stringify
            // would drop from the request entirely — see
            // productUpdatePayload.js for why that silently preserves the old
            // default instead of clearing it.
            this.data.product_changes.detail['default_variation_id'] = normalizeDefaultVariationIdForSave(value);
            this.data.product_changes.detail['id'] = this.product.detail.id;
            this.setHasChange(true);
        }

        if (name === 'manage_downloadable') {
            if (!this.data.product_changes.detail) {
                this.data.product_changes.detail = {};
            }
            if (!this.product.detail) {
                this.product.detail = {};
            }

            // Same shape as default_variation_id above, and the same bug it had:
            // the switch mutated product.detail through v-model and flipped
            // hasChange, but nothing ever staged the column, so update() posted
            // detail as {id, fulfillment_type, variation_type} and the toggle was
            // dropped in both directions while the toast reported success.
            this.product.detail.manage_downloadable = value;
            this.data.product_changes.detail['manage_downloadable'] = value;
            this.data.product_changes.detail['id'] = this.product.detail.id;
            this.setHasChange(true);
        }

        if (name === 'fulfillment_type') {
            if (!this.data.product_changes.detail) {
                this.data.product_changes.detail = {};
            }
            this.product.detail.fulfillment_type = value;
            this.data.product_changes.detail.fulfillment_type = value;
            this.data.product_changes.detail['id'] = this.product.detail.id;

            this.product.variants.forEach((variant, index) => {
                this.product.variants[index]['fulfillment_type'] = value;
                this.ensureVariationIndex(index);
                this.data.product_changes.variants[index]['fulfillment_type'] = value;
                this.data.product_changes.variants[index]['id'] = variant.id;
            });
        }

        if (name === 'shipping_class') {
            if (!this.data.product_changes.detail) {
                this.data.product_changes.detail = {};
            }
            if (!this.product.detail) {
                this.product.detail = {};
            }
            if (!this.product.detail?.other_info) {
                this.product.detail.other_info = {};
            }
            this.product.detail.other_info[name] = value;

            if (!this.data.product_changes.detail?.other_info) {
                this.data.product_changes.detail.other_info = {};
            }

            this.data.product_changes.detail.other_info[name] = value;

            this.product.variants.forEach((variant, index) => {
                // Always set for product.variants
                this.product.variants[index]['shipping_class'] = value;

                this.ensureVariationIndex(index);
                // Now safely assign
                this.data.product_changes.variants[index]['shipping_class'] = value;
                this.data.product_changes.variants[index]['id'] = variant.id;
            });

        }

        if ([
            'group_pricing_by',
            'use_pricing_table',
            'active_editor',
            'sold_individually',
            'reviews_enabled'
        ].includes(name)) {

            // No ensureVariationIndex() here: these are product-detail settings and
            // must not open a variant change record. It created `variants[0] = {}`
            // for every type but wrote the id only for `simple`, so on
            // simple_variations / advanced_variations the payload builder had no id
            // to match and could emit only {post_id} — the save then failed with
            // "Title is required." and "Invalid fulfillment type." With no record at
            // all, a simple product's row is rebuilt from the stored variation and
            // the other types send no variant row, which is what they need.
            if (!this.data.product_changes.detail) {
                this.data.product_changes.detail = {};
            }

            // Ensure objects exist
            // if (!this.product.detail) {
            //     this.product.detail = {};
            // }
            if (!this.product.detail?.other_info) {
                this.product.detail.other_info = {};
            }

            if (!this.data.product_changes.detail?.other_info) {
                this.data.product_changes.detail.other_info = {};
            }


            // Always update product
            this.product.detail.other_info[name] = value;

            // tax_class also updates product_changes
            this.data.product_changes.detail.other_info[name] = value;

            // Always stage the detail id: ProductDetailResource::update() bails
            // without it and the setting is dropped while the toast still says the
            // product was updated.
            this.data.product_changes.detail['id'] = this.product.detail.id;

        }


        if (name === 'post_excerpt') {
            this.product[name] = value;
            if (!this.data.product_changes.detail) {
                this.data.product_changes.detail = {};
            }
            this.data.product_changes.detail['id'] = this.product.detail.id;
            this.data.product_changes[name] = this.product[name];
            const trimmed = value.trim();
            if (trimmed.length === 0) {
                this.setExcerptWordCount(0)
            } else {
                this.setExcerptWordCount(trimmed.split(' ').length)
            }
        } else {
            this.product[name] = value;
            this.data.product_changes[name] = value;
            if (!this.data.product_changes.detail) {
                this.data.product_changes.detail = {};
            }
            this.data.product_changes.detail['id'] = this.product.detail.id;
        }
        if (triggerChanges) {
            this.setHasChange(true);
        }
    }

    hasValidationError = (fieldKey) => {
        return this.validationErrors && this.validationErrors.hasOwnProperty(fieldKey);
    }

    clearValidationError = (fieldKey) => {
        if (this.validationErrors && this.validationErrors.hasOwnProperty(fieldKey)) {
            delete this.validationErrors[fieldKey];
        }
    }

    // Strike-through pricing rule: a non-zero compare_price must be >= the
    // variant's item_price. Surfaces an inline validation error instead of
    // silently clearing the bad value (the old behavior wiped the field
    // without telling the merchant). Re-evaluated whenever item_price or
    // compare_price changes — either side moving can invalidate the pair.
    // Returns true when the pair is valid (or no compare_price set), false
    // when an error was raised, so callers can short-circuit downstream
    // side-effects (e.g. block the inline commit toast).
    validateComparePrice = (variantIndex) => {
        if (variantIndex === undefined || variantIndex === null) return true;
        const variant = this.product?.variants?.[variantIndex];
        if (!variant) return true;
        const key = `variants.${variantIndex}.compare_price`;
        const itemPrice = parseFloat(variant.item_price);
        const comparePrice = parseFloat(variant.compare_price);
        // No compare_price set → nothing to validate.
        if (isNaN(comparePrice) || comparePrice <= 0) {
            this.clearValidationError(key);
            return true;
        }
        if (!isNaN(itemPrice) && comparePrice < itemPrice) {
            this.validationErrors[key] = [
                translate('Compare price must be greater than or equal to item price.')
            ];
            return false;
        }
        this.clearValidationError(key);
        return true;
    }

    discard = () => {
        const snapshot = this.data.product_snapshot;
        if (!snapshot || !Object.keys(snapshot).length) {
            return;
        }
        this._mergeSnapshot(snapshot);
        this.data.product_changes = {};
        this.data.discardKey++;
        this.setHasChange(false);
    }

    update = async (successMessage = null) => {


        let data = this.data.product_changes;

        data = {...this.product};
        data.metaValue = this.data.metaValue;
        // delete data.post_content;
        let proceed = true;

        if (!proceed) {
            return;
        }

        if (!this.data.product_changes.post_status) {
            this.data.product_changes.post_status = this.product.post_status;
        }
        if (!this.data.product_changes.post_title) {
            this.data.product_changes.post_title = this.product.post_title;
        }

        // check detail.fulfillment_type
        if (!this.data.product_changes.detail) {
            this.data.product_changes.detail = {};
            this.data.product_changes.detail.fulfillment_type = this.product.detail.fulfillment_type;
            this.data.product_changes.detail.variation_type = this.product.detail.variation_type;
        } else {
            if (!this.data.product_changes.detail?.fulfillment_type) {
                this.data.product_changes.detail.fulfillment_type = this.product.detail.fulfillment_type;
            }
            if (!this.data.product_changes.detail?.variation_type) {
                this.data.product_changes.detail.variation_type = this.product.detail.variation_type;
            }
        }

        data = this.data.product_changes;
        data.metaValue = this.data.metaValue;

        this.setValidationErrors({})
        this.setSaving(true)


        delete data.taxonomies;
        delete data.product_terms;
        delete data.variantOptions;
        delete data.downloadable_files;


        // data.variants holds change records, not variations: onChangePricing()
        // writes only `{id, <changed field>}`. Reading the row's own keys sent
        // `variation_title: undefined` and never carried post_id at all, so the save
        // was rejected with "Title is required.", "Invalid fulfillment type." and
        // "variants.0.post_id is required."
        //
        // Simple products used to skip this merge and hand-roll a row instead, which
        // only ran when nothing on the variation had changed and carried no
        // other_info — so editing a simple product's title, price or fulfilment
        // failed the same way the advanced editor once did.
        if (data.detail?.variation_type === 'simple') {
            data.variants = buildSimpleVariantUpdatePayload(
                data.variants,
                this.product.variants,
                this.product.ID
            );
        } else if (data.variants) {
            data.variants = buildVariantUpdatePayload(
                data.variants,
                this.product.variants,
                this.product.ID
            );
        }


        if (data.downloadable_files?.length < 1) {
            data.variantOptions = [];
        }


        const req = Rest.post(`products/${this.product.ID}/pricing`, data);

        let productBadge = document.querySelector('.fct-admin-product-info .badge');

        req.then((response) => {

            Notify.success(successMessage || response.message);
            this.setHasChange(false);
            if (productBadge) {
                productBadge.innerText = response.data.post_status;
            }
            this.product.view_url = response.data.viewUrl;
            //this.product.post_name = response.data.post_name;

            if (productBadge && response.data.post_status === 'publish') {
                productBadge.classList.remove('warning');
                productBadge.classList.add('success');
            }

            if (productBadge && response.data.post_status === 'future') {
                /* translators: %s is the post date */
                productBadge.innerText = translate('Publishes on: %s', formatDate(response.data.post_date, true));
                productBadge.classList.remove('success');
                productBadge.classList.add('warning');
            }

            if (productBadge) {
                if (response.data.post_status === 'draft') {
                    productBadge.classList.remove('success');
                    productBadge.classList.add('info');
                } else {
                    productBadge.classList.remove('info');
                    productBadge.classList.add('success');
                }
            }

            if (this.product.detail.variation_type === 'simple') {
                //this.product.variants = response.data.variants;

                if (Array.isArray(response.data.variants) && response.data.variants.length > 0) {
                    this.product.variants[0].id = response.data.variants[0].id;
                    this.product.variants[0]['created_at'] = response.data.variants[0]['created_at'];
                }

            }

            // removed duplicate variants
            this.product.variants = this.product.variants.filter((variant, index, self) =>
                index === self.findIndex(v => v.id === variant.id)
            );

            this.data.product_changes = {};
            this.saveSnapshot();

            Object.values(this.data.onProductUpdatedListener).forEach(callback => {
                if(typeof callback === 'function'){
                    callback(this.product);
                }
            })

            //this.saveSnapshot();
        }).catch((errors) => {
            if (errors?.status_code?.toString() === '422') {
                Notify.validationErrors(errors);
                this.setValidationErrors(errors)
            } else {
                Notify.error(errors?.data?.message || errors?.message || translate('Failed to save product'));
            }
        })
            .finally(async () => {
                this.setSaving(false)

            });
    }

    delete = () => {
        $confirm(
            translate('Are you sure you want to delete this product?'),
            translate('Confirm Delete!'),
            {
                confirmButtonText: translate('Yes, Delete!'),
                cancelButtonText: translate('Cancel'),
                type: 'warning'
            }
        ).then(() => {
            this.setHasChange(false);
            Rest.delete(`products/${this.product.ID}`)
                .then((response) => {
                    Notify.success(response.message);
                    this.router.push({
                        name: 'products'
                    });
                })
                .catch((errors) => {
                        if (errors.status_code == '422') {
                            Notify.validationErrors(errors);
                        } else {
                            Notify.error(errors.data?.message);
                        }
                    }
                )
        }).catch(() => {

        });
    }

    updateVariationType = (name, value) => {
        // Remember the current type so a rejected switch can be rolled back. The
        // mutation below is optimistic; without the restore in catch, a failed
        // request (e.g. the blocked advanced -> simple downgrade returns 422)
        // would leave the editor showing a type the server rejected — which then
        // disables the other dropdown options and strands the merchant.
        const previousValue = this.product.detail[name];
        this.product.detail[name] = value

        let data = {
            'variation_type' : value,
            'variation_ids'  : [this.product?.variants[0]?.id],
            'action'         : 'change_variation_type'
        };


        const req = Rest.post(`products/detail/${this.product.detail.id}`, data)

        req.then(response => {
            Notify.info(response.message);
            if (name === 'variation_type' && value === 'simple') {

                //If the product type changed to simple,
                //we should remove the other variation options as its has been deleted from server
                //this.product.variants = this.product.variants.splice(1, this.variantsLength())
                //We should keep the first variation.
                this.product.variants = this.product.variants.slice(0, 1);

                if (this.variantsLength() > 0) {
                    this.product.variants[0]['manage_stock'] = this.product.detail.manage_stock;
                }
            } else if (name === 'variation_type' && value === 'advanced_variations') {
                // The server deletes the old Simple Variations variants on switch
                // to Advanced — clear them locally so the editor doesn't show
                // now-deleted rows. The merchant builds fresh combinations from
                // attribute options, which create new variants on generate.
                this.product.variants = [];
            }
            //this.setHasChange(false)
        })
            .catch((errors) => {
                // Roll the optimistic change back so the editor matches the server.
                this.product.detail[name] = previousValue;
                if (errors.status_code == '422') {
                    Notify.validationErrors(errors);
                } else {
                    Notify.error(errors.data?.message);
                }
            })
            .finally(() => {

            });

        // Returned so the caller (ProductPricing.vue) can roll its own select
        // value back on failure — the model only owns product.detail.
        return req;
    }

    getMaxExcerptWordCount = () => {
        Rest.get('products/get-max-excerpt-word-count').then(response => {
            this.setMaxExcerptWordCount(response.count)
        }).catch(e => {
        });
    }

    shouldShowShippingMethodAlert = () => {

        const count = AppConfig.get('available_shipping_method_count').toString();

        if (count !== '0') {
            return false;
        }
        return this.hasPhysicalVariation();
    }

    updateMedia(key, value) {
        this.product[key] = value;
        this.data.product_changes[key] = value;
        this.setHasChange(true)
    }

    hasDigitalVariation = () => {
        return this.product.variants.some(variant => variant.fulfillment_type === 'digital');
    }

    isAllDigitalVariation = () => {
        return this.product.variants.every(variant => variant.fulfillment_type === 'digital');
    }

    hasPhysicalVariation = () => {
        return this.product.variants.some(variant => variant.fulfillment_type === 'physical');
    }

    isTaxEnabled = () => {
        return AppConfig.get('is_tax_enabled');
    }

    isAllPhysicalVariation = () => {
        return this.product.variants.every(variant => variant.fulfillment_type === 'physical');
    }

    shippingSettingsUrl () {
        return AppConfig.get('admin_url') + 'settings/shipping';
    }

    isBundleProduct = () =>{
        return Arr.get(this.data.product, 'detail.other_info.is_bundle_product') === 'yes';
    }


}

/**
 * @return {ProductEditModel}
 */
export function useProductEditModel() {
    return ProductEditModel.init();
}
