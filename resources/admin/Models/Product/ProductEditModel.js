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


    addOnProductUpdatedListener(name, callback) {
        this.data.onProductUpdatedListener[name] = callback;
    }

    setProductDownloadableModel(model) {
        this.data.productDownloadableModel = model;
    }

    setReloader(reloader) {
        this.data.reloader = reloader;
    }

    saveSnapshot() {
        this.data.product_snapshot = this.data.product;
    }

    restoreSnapshot() {
        this.data.product_snapshot = this.data.product;
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
        if (product.post_status === 'future') {
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
                times: '',
                repeat_interval: 'yearly',
                billing_summary: '',
                manage_setup_fee: 'no',
                signup_fee_name: '',
                signup_fee: '',
                setup_fee_per_item: 'no',
                //purchasable: 'yes',
            },
            downloadable: 'true',
        };
    }

    formatPricing = (prices) => {
        let result = {};

        prices.forEach((pricing, idx) => {
            pricing.item_price = (pricing.item_price) ? formatNumber(pricing.item_price, false) : 0;
            pricing.item_cost = (pricing.item_cost) ? formatNumber(pricing.item_cost, false) : 0;
            pricing.compare_price = (pricing.compare_price) ? formatNumber(pricing.compare_price, false) : 0;

            pricing.other_info.signup_fee = (pricing.other_info.signup_fee) ? formatNumber(pricing.other_info.signup_fee, false) : 0;

            pricing.rowId = idx;

            result[pricing.id] = pricing;
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
        // if(name === 'compare_price') {
        //     this.product.variants[index]['compare_price'] = ( value <= 0 )? '' : value
        // }


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
            this.data.product_changes.variants[index]['billing_summary'] = `${variant.item_price} ${variant.other_info.repeat_interval} ${occurrence}`;
            this.data.product_changes.variants[index]['id'] = variant.id;
            this.data.product_changes.detail['id'] = this.product.detail.id;
            return variant.other_info.billing_summary = `${variant.item_price} ${variant.other_info.repeat_interval} ${occurrence}`;
        }
        this.data.product_changes.variants[index]['billing_summary'] = '';
        this.data.product_changes.variants[index]['id'] = variant.id;
        this.data.product_changes.detail['id'] = this.product.detail.id;
        return variant.other_info.billing_summary = "";
    }

    updateVariantSerialIndexes(variants) {
        variants.forEach((variant, index) => {
            variant.serial_index = index + 1;
        });
        if (!this.data.product_changes?.variants) {
            this.data.product_changes.variants = [];
        }

        this.data.product_changes.variants = variants.map(v => ({
            id: v.id,
            serial_index: v.serial_index,
        }));
        this.setHasChange(true);
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
        if (index !== undefined & index != null) {
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
            //this.saveSnapshot();
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

        if (name === 'fulfillment_type') {
            // check if fulfillment_type exist in product_changes.details
            if (!this.data.product_changes.detail) {
                this.data.product_changes.detail = {};
            }

            this.data.product_changes.detail.fulfillment_type = value;
            this.data.product_changes.variants[index]['fulfillment_type'] = value;
        }

        // if(name === 'compare_price') {
        //   variant['compare_price'] = ( value <= 0 )? 1 : value
        // }

        // if(parseInt(variant['compare_price']) < parseInt(variant['item_price'])) {
        //   variant['compare_price'] = variant['item_price']
        // }

        if (name === 'item_cost') {
            variant['item_cost'] = (value < 0) ? 0 : value
        }

        if (parseInt(variant['item_cost']) > parseInt(variant['item_price'])) {
            variant['item_cost'] = 0
        }

        this.data.product_changes.variants[index][name] = value;
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


    }

    updatePricingOtherValue = (name, value, index = null, variant, modeType = '') => {
        this.ensureVariationIndex(index);

        if (['payment_type', 'times', 'repeat_interval', 'manage_setup_fee', 'signup_fee_name', 'signup_fee', 'billing_summary', 'setup_fee_per_item', 'purchasable'].includes(name)) {
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

            this.onChangePricingPayment(variant, value, index);
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

        Confirmation.ofDelete(
            translate("Are you sure, you want to delete this price?")
        ).then(() => {
            if (id !== undefined) {
                Rest.delete(`products/variants/${id}`)
                    .then(response => {
                        Notify.success(response.message);
                        this.afterDeletingPricing(index)
                    })
                    .catch((errors) => {
                        if (errors.status_code == '422') {
                            Notify.validationErrors(errors);
                        } else {
                            Notify.error(errors?.data);
                        }
                    })
            }

        }).catch(() => {

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

    onChangeNewStock = (name, value, index) => {
        if (!this.product.variants[index]) {
            this.data.product_changes.variants = [];
        }

        this.ensureVariationIndex(index);

        if (value === '') {
            this.product.variants[index]['new_stock'] = 0;
            this.data.product_changes.variants[index]['new_stock'] = 0;
        }
        let oldStock = this.product.variants[index]['total_stock'] ?? 0;
        let newStock = this.product.variants[index]['new_stock'] ?? 0;
        let adjustedStock = parseInt(newStock.toString()) - parseInt(oldStock);
        this.product.variants[index]['adjusted_quantity'] = adjustedStock;
        this.data.product_changes.variants[index]['adjusted_quantity'] = adjustedStock;
        this.data.product_changes.variants[index]['id'] = this.product.variants[index]['id'];
    }

    onChangeAdjustedQuantity = (name, value, index) => {

        this.ensureVariationIndex(index);
        if (value === '') {
            value = 0;
        }
        let newStock = this.product.variants[index]['total_stock'] + parseInt(value);
        this.product.variants[index]['new_stock'] = (newStock < 0) ? 0 : newStock;
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

            this.data.product_changes.detail['default_variation_id'] = value;
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
            'tax_class',
            'group_pricing_by',
            'use_pricing_table',
            'active_editor',
            'sold_individually',
            'order_note_enabled',
            'order_note_title'
        ].includes(name)) {

            this.ensureVariationIndex(0);

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
            if (this.product.detail.variation_type === 'simple') {
                this.data.product_changes.detail['id'] = this.product.detail.id;
                this.data.product_changes.variants[0]['id'] = this.product.variants[0]['id'];
            }

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

    discard = () => {
        return window.location.reload();
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


        if (data.detail?.variation_type === 'simple') {
            if (!data.variants) {
                data.variants = [];
            }
            if (!Array.isArray(data.variants) || data.variants.length === 0) {
                //Todo need to update the the backend validation
                data.variants[0] = {
                    variation_title: data.post_title,
                    item_price: '',
                    post_id: data.ID,
                };
            }
        }


        delete data.taxonomies;
        delete data.product_terms;
        delete data.variantOptions;
        delete data.downloadable_files;


        if (data.detail?.variation_type !== 'simple') {
            if (data.variants) {
                let formattedVariants = [];
                data.variants.forEach(variant => {
                    formattedVariants.push({
                        id: variant.id,
                        variation_title: variant.variation_title,
                        item_price: variant.item_price,
                        compare_price: variant.compare_price,
                        other_info: variant.other_info,
                        manage_stock: variant.manage_stock,
                        serial_index: variant.serial_index,
                        total_stock: variant.total_stock,
                        available: variant.available,
                        committed: variant.committed,
                        on_hold: variant.on_hold,
                        stock_status: variant.stock_status,
                        sold_individually: variant.sold_individually,
                    });
                });

                data.variants = formattedVariants;
            }
        }


        if (data.downloadable_files?.length < 1) {
            data.variantOptions = [];
        }


        const req = Rest.post(`products/${this.product.ID}/pricing`, data);

        let productBadge = document.querySelector('.fct-admin-product-info .badge');

        req.then((response) => {

            Notify.success(successMessage || response.message);
            this.setHasChange(false);
            productBadge.innerText = response.data.post_status;
            this.product.view_url = response.data.viewUrl;
            //this.product.post_name = response.data.post_name;

            if (response.data.post_status === 'publish') {
                productBadge.classList.remove('warning');
                productBadge.classList.add('success');
            }

            if (response.data.post_status === 'future') {
                /* translators: %s is the post date */
                productBadge.innerText = translate('Publishes on: %s', formatDate(response.data.post_date, true));
                productBadge.classList.remove('success');
                productBadge.classList.add('warning');
            }

            if (response.data.post_status === 'draft') {
                productBadge.classList.remove('success');
                productBadge.classList.add('info');
            } else {
                productBadge.classList.remove('info');
                productBadge.classList.add('success');
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


            Object.values(this.data.onProductUpdatedListener).forEach(callback => {
                if(typeof callback === 'function'){
                    callback(this.product);
                }
            })

            //this.saveSnapshot();
        }).catch((errors) => {
            console.log(errors)
            if (errors.status_code.toString() === '422') {
                Notify.validationErrors(errors);
                this.setValidationErrors(errors)
            } else {
                Notify.error(errors.data?.message);
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
            }
            //this.setHasChange(false)
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
