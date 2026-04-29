<script setup>
import {ref, computed, onMounted} from 'vue';
import SharedVariantItemBox from "@/Modules/Products/parts/SharedVariantItemBox.vue";
import ValidationError from "@/Bits/Components/Inputs/ValidationError.vue";
import LabelHint from "@/Bits/Components/LabelHint.vue";
import translate from "@/utils/translator/Translator";
import Rest from "@/utils/http/Rest";
import Notify from "@/utils/Notify";
import VariantItemCollapse from "@/Modules/Products/parts/VariantItemCollapse.vue";
import StockAdjuster from "@/Modules/Products/parts/StockAdjuster.vue";

const props = defineProps({
    variant: Object,
    fieldKey: String,
    modeType: String,
    product: Object,
    productEditModel: Object,
});

const generatingSku = ref(false);

const isStockManaged = computed(() => props.variant.manage_stock == '1' || props.variant.manage_stock == 1);

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

    const isVariation = props.product.detail?.variation_type === 'simple_variations';
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
        <template v-if="product.detail?.variation_type === 'simple_variations'" #label>
            {{ translate('Inventory') }}
        </template>

        <template v-if="product.detail?.variation_type === 'simple_variations'" #action>
            <div class="fct-shared-variant-item-box__switch">
                <el-switch
                    size="small"
                    v-model="variant.manage_stock"
                    @change="handleVariantStockChange"
                    active-value="1" 
                    inactive-value="0"
                />
            </div>
        </template>

        <div v-if="isStockManaged && product.detail?.variation_type === 'simple_variations'" class="fct-variant-stock-grid">
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

        <VariantItemCollapse>
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
                                class="underline-link-button"
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
