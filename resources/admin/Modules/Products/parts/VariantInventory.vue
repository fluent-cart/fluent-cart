<script setup>
/* eslint-disable vue/no-mutating-props --
 * Pre-existing controlled-mutation component (same contract as TermForm.vue):
 * the parent owns the variant object and hands it down for in-place editing;
 * every v-model and write-back below predates this file entering lint scope.
 * New code added here must not introduce further prop mutations. */
import {ref, computed, onMounted} from 'vue';
import SharedVariantItemBox from "@/Modules/Products/parts/SharedVariantItemBox.vue";
import ValidationError from "@/Bits/Components/Inputs/ValidationError.vue";
import LabelHint from "@/Bits/Components/LabelHint.vue";
import translate from "@/utils/translator/Translator";
import Rest from "@/utils/http/Rest";
import Notify from "@/utils/Notify";
import VariantItemCollapse from "@/Modules/Products/parts/VariantItemCollapse.vue";
import StockAdjuster from "@/Modules/Products/parts/StockAdjuster.vue";
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";
import AppConfig from "@/utils/Config/AppConfig";

const props = defineProps({
    variant: Object,
    fieldKey: String,
    modeType: String,
    product: Object,
    productEditModel: Object,
    isGroupMode: { type: Boolean, default: false },
});

const generatingSku = ref(false);

const isStockManaged = computed(() => props.variant.manage_stock == '1' || props.variant.manage_stock == 1);

// A variation's stock toggle is inert while the PRODUCT's Inventory
// Management (product.detail.manage_stock — the card on the product edit
// page) is off. Lock the toggle and offer the enable action inline, using
// the exact same endpoint and cascade the card's own switch uses, so the
// two entry points stay behaviorally identical.
const productStockEnabled = computed(() => {
    return props.product?.detail?.manage_stock == '1' || props.product?.detail?.manage_stock == 1;
});
const enablingProductStock = ref(false);
// The warning strip's switch: flipping it on runs the enable request; a
// failure flips it back so the control never shows a state the server
// refused. The whole strip unmounts once productStockEnabled turns true.
const enableInventorySwitch = ref(false);

const handleEnableInventoryToggle = (value) => {
    if (!value) {
        return; // only turning ON triggers the request
    }
    enableProductInventory();
};

const enableProductInventory = () => {
    if (enablingProductStock.value) {
        return;
    }
    enablingProductStock.value = true;

    Rest.put(`products/${props.product.ID}/update-manage-stock`, {
        manage_stock: '1'
    })
        .then((response) => {
            // Mirror ProductInventory.vue's handleManageStockChange: flip the
            // detail flag and cascade onto the variants only AFTER the server
            // confirmed the write.
            props.product.detail.manage_stock = '1';
            (props.product.variants || []).forEach((productVariant) => {
                productVariant.manage_stock = '1';
            });
            props.variant.manage_stock = '1';
            props.productEditModel.updatePricingValue('manage_stock', '1', props.fieldKey, props.variant, props.modeType);

            Notify.success(response?.message || translate('Inventory Management enabled'));
        })
        .catch((errors) => {
            enableInventorySwitch.value = false; // revert the strip's switch
            if (errors?.status_code == '422') {
                Notify.validationErrors(errors);
            } else {
                Notify.error(errors?.data?.message || translate('Failed to update inventory'));
            }
        })
        .finally(() => {
            enablingProductStock.value = false;
        });
};

// The Stock Management module (Settings -> Modules) is the master switch. With
// it off, the product-level Inventory card (ProductInventory.vue) hides itself
// the same way, so the variation-level inventory UI must hide too — otherwise
// the variation toggle and the "enable inventory" strip would keep offering
// stock tracking that the store has globally turned off.
const stockModuleEnabled = computed(() => {
    return AppConfig.get('modules_settings.stock_management.active') === 'yes';
});

// Inventory UI (label, manage-stock toggle, stock grid) is shown for the
// legacy variable type (simple_variations) and for advanced variations.
const showInventory = computed(() => {
    if (!stockModuleEnabled.value) {
        return false;
    }
    return ['simple_variations', 'advanced_variations'].includes(props.product.detail?.variation_type);
});

const handleVariantStockChange = (value) => {
    props.productEditModel.updatePricingValue('manage_stock', value, props.fieldKey, props.variant, props.modeType);
};

onMounted(() => {
    if (props.variant.new_stock === undefined) {
        props.variant.new_stock = props.variant.total_stock;
    }
    if (props.variant.adjusted_quantity === undefined) {
        props.variant.adjusted_quantity = 0;
    }
});

const saveStock = () => {
    let newStock = parseInt(props.variant.new_stock) || 0;
    const prevTotalStock = props.variant.total_stock;
    const prevAvailable = props.variant.available;

    props.variant.total_stock = newStock < 0 ? 0 : newStock;
    let available = (parseInt(props.variant.total_stock) || 0) - (parseInt(props.variant.committed) || 0) - (parseInt(props.variant.on_hold) || 0);
    props.variant.available = available < 0 ? 0 : available;

    props.variant.adjusted_quantity = 0;
    props.variant.new_stock = props.variant.total_stock;

    // Unsaved draft variant: there is no row to PUT against yet. Keep the
    // adjustment in the local model only — the Save button creates the
    // variant WITH these stock values (ProductVariationResource::create
    // persists total_stock/available from the pricing payload).
    if (!props.variant.id) {
        props.productEditModel.updatePricingValue('total_stock', props.variant.total_stock, props.fieldKey, props.variant, props.modeType);
        props.productEditModel.updatePricingValue('available', props.variant.available, props.fieldKey, props.variant, props.modeType);
        return;
    }

    Rest.put(`products/${props.product.ID}/update-inventory/${props.variant.id}`, {
        total_stock: props.variant.total_stock,
        available: props.variant.available
    })
        .then(response => {
            Notify.success(response.message);
        })
        .catch((errors) => {
            props.variant.total_stock = prevTotalStock;
            props.variant.available = prevAvailable;
            props.variant.new_stock = prevTotalStock;
            Notify.error(errors?.data?.message || translate('Failed to update inventory'));
        });
};

const generateSku = () => {
    if (generatingSku.value) return;
    const title = props.product.post_title || '';
    if (!title) return;

    const isVariation = ['simple_variations', 'advanced_variations'].includes(props.product.detail?.variation_type);
    const variantTitle = isVariation ? (props.variant.variation_title || '') : '';
    const excludeId = props.variant.id || 0;

    generatingSku.value = true;
    Rest.get('products/suggest-sku', {
        title: title,
        variant_title: variantTitle,
        exclude_id: excludeId,
    })
        .then(response => {
            if (response?.sku) {
                props.variant.sku = response.sku;
                props.productEditModel.updatePricingValue('sku', response.sku, props.fieldKey, props.variant, props.modeType);
            }
        })
        .catch((error) => {
            const message = error?.data?.message || translate('Failed to generate SKU.');
            Notify.error(message);
        })
        .finally(() => {
            generatingSku.value = false;
        });
};
</script>

<template>
    <SharedVariantItemBox class="fct-variant-inventory" :class="product.detail?.variation_type === 'simple' ? 'fct-variant-inventory--simple' : ''">
        <template v-if="showInventory" #label>
            {{ translate('Inventory') }}
        </template>

        <!-- While product-level inventory is off, the warning strip below is
             the single control — showing a dead header toggle next to it
             would be two switches fighting over one decision. -->
        <template v-if="showInventory && productStockEnabled" #action>
            <div class="fct-shared-variant-item-box__switch">
                <el-switch
                    v-if="!isGroupMode"
                    size="small"
                    v-model="variant.manage_stock"
                    @change="handleVariantStockChange"
                    active-value="1"
                    inactive-value="0"
                />

                <div v-else class="fct-inline-select-wrap">
                    <label>{{ translate('Manage Stock') }}:</label>

                    <el-select
                        size="small"
                        v-model="variant.manage_stock"
                        popper-class="fct-group-select-popper"
                        class="fct-inline-select"
                        placement="bottom"
                    >
                        <el-option value="__unchanged__" :label="translate('Unchanged')" />
                        <el-option value="1" :label="translate('Manage stock')" />
                        <el-option value="0" :label="translate('Don\'t manage')" />
                    </el-select>
                </div>
            </div>
        </template>

        <!-- The product's Inventory Management is off: the variation toggle
             above is locked; explain why and offer the enable action inline. -->
        <div v-if="showInventory && !productStockEnabled" class="fct-variant-inventory-global-off" role="alert">
            <span class="fct-variant-inventory-global-off__text">
                <DynamicIcon name="Warning" />
                {{ translate('Enable inventory to track stock and manage availability for this product and its variations.') }}
            </span>
            <span class="fct-variant-inventory-global-off__action">
                <el-switch
                    size="small"
                    v-model="enableInventorySwitch"
                    :loading="enablingProductStock"
                    :disabled="enablingProductStock"
                    @change="handleEnableInventoryToggle"
                />
            </span>
        </div>

        <div v-if="isStockManaged && showInventory && !isGroupMode" class="fct-variant-stock-grid">
            <!-- Total Stock -->
            <div class="fct-variant-stock-box">
                <div class="fct-stock-box-label">{{ translate('Total Stock') }}</div>
                <div class="fct-stock-box-content fct-stock-box-total-content">
                    <div class="fct-stock-number">{{ variant.total_stock }}</div>
                    <StockAdjuster
                        :variant="variant"
                        :field-key="fieldKey"
                        :product-edit-model="productEditModel"
                        @save="saveStock"
                    />
                </div>
            </div>

            <!-- Available -->
            <div class="fct-variant-stock-box">
                <div class="fct-stock-box-label">{{ translate('Available') }}</div>
                <div class="fct-stock-box-content">
                    <div class="fct-stock-number">{{ variant.available }}</div>
                </div>
            </div>

            <!-- On Hold -->
            <div class="fct-variant-stock-box">
                <div class="fct-stock-box-label">{{ translate('On Hold') }}</div>
                <div class="fct-stock-box-content">
                    <div class="fct-stock-number">{{ variant.on_hold }}</div>
                </div>
            </div>

            <!-- Delivered -->
            <div class="fct-variant-stock-box">
                <div class="fct-stock-box-label">{{ translate('Delivered') }}</div>
                <div class="fct-stock-box-content">
                    <div class="fct-stock-number">{{ variant.committed }}</div>
                </div>
            </div>
        </div>

        <div v-if="isStockManaged && showInventory && isGroupMode" class="fct-admin-input-wrapper">
            <el-form-item :label="translate('Total Stock')">
                <el-input
                    type="number"
                    :min="0"
                    :placeholder="translate('Unchanged')"
                    v-model="variant.total_stock"
                />
            </el-form-item>
        </div>

        <VariantItemCollapse v-if="!isGroupMode">
            <template #header="{ isOpen }">
                <div v-if="isOpen" class="fct-collapse-heading">
                    {{ translate('More details') }}
                </div>

                <div v-if="!isOpen" class="fct-tag-group">
                    <div class="fct-tag-item">
                        {{ translate('SKU') }}
                    </div>
                </div>
            </template>

            <div class="fct-admin-input-wrapper">
                <el-form-item>
                    <template #label>
                        <div style="display: flex; justify-content: space-between; align-items: center; width: 100%;">
                            <LabelHint
                                :title="translate('SKU (Stock Keeping Unit)')"
                                placement="bottom"
                            />
                            <button
                                type="button"
                                @click="generateSku"
                                :disabled="!product.post_title || generatingSku"
                                class="underline-link-button fct-generate-sku-btn"
                            >
                                {{ generatingSku ? translate('Generating...') : translate('Generate SKU') }}
                            </button>
                        </div>
                    </template>
                    <el-input
                        :class="productEditModel.hasValidationError(`${fieldKey}.sku`) ? 'is-error' : ''"
                        :id="`${fieldKey}.sku`"
                        :placeholder="translate('SKU')" type="text" v-model="variant.sku"
                        maxlength="30"
                        show-word-limit
                        @input="value => {productEditModel.updatePricingValue('sku', value, fieldKey, variant, modeType)}">
                    </el-input>
                    <ValidationError :validation-errors="productEditModel.validationErrors"
                                    :field-key="`${fieldKey}.sku`"/>
                </el-form-item>
            </div><!-- .fct-admin-input-wrapper -->
        </VariantItemCollapse>

    </SharedVariantItemBox>
</template>
