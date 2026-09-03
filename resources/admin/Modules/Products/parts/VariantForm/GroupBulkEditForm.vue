<script setup>
import {nextTick, onMounted, ref, watch} from "vue";
import Rest from "@/utils/http/Rest";
import Notify from "@/utils/Notify";
import VariantPrice from "@/Modules/Products/parts/VariantPrice.vue";
import VariantInventory from "@/Modules/Products/parts/VariantInventory.vue";
import ProductShipping from "@/Modules/Products/parts/ProductShipping.vue";
import VariationTaxClass from "@/Modules/Products/parts/VariationTaxClass.vue";
import translate from "@/utils/translator/Translator";

const props = defineProps({
    groupVariantIds: { type: Array, required: true },
    product:         Object,
    productEditModel: Object,
    fieldKey:        String,
});

const emit = defineEmits(['createOrUpdateVariant', 'closeModal', 'dirtyStateChange']);

const makeBlankGroupVariant = () => ({
    item_price:    null,
    compare_price: null,
    item_cost:     null,
    manage_cost:   '__unchanged__',
    sku:           null,
    manage_stock:  '__unchanged__',
    total_stock:   null,
    fulfillment_type: '__unchanged__',
    available:  null,
    on_hold:    null,
    committed:  null,
    other_info: {
        payment_type:     '__unchanged__',
        description:      null,
        tax_class:        null,
        tax_exempt:       '__unchanged__',
        tax_inclusion:    '__unchanged__',
        package_slug:     null,
        weight:           null,
        weight_unit:      null,
        length:           null,
        width:            null,
        height:           null,
        interval:         null,
        interval_count:   null,
        billing_summary:  null,
        manage_setup_fee: '__unchanged__',
        signup_fee:       null,
        signup_fee_name:  null,
        times:            null,
        trial_days:       null,
    },
});

const variant      = ref(makeBlankGroupVariant());
const changes_made = ref(0);
const isResetting  = ref(false);
const isReady      = ref(false);

onMounted(async () => {
    await nextTick();
    isReady.value = true;
});

const buildGroupUpdatePayload = () => {
    const src = variant.value || {};
    const topLevel = {};
    const topLevelFields = ['item_price', 'compare_price', 'item_cost', 'manage_cost', 'sku', 'manage_stock', 'total_stock', 'fulfillment_type'];

    topLevelFields.forEach(field => {
        const val = src[field];
        if (val !== null && val !== undefined && val !== '' && val !== '__unchanged__') {
            topLevel[field] = val;
        }
    });

    const otherInfoSrc = src.other_info || {};
    const otherInfo = {};
    Object.keys(otherInfoSrc).forEach(key => {
        // billing_summary embeds a price — the shared form computes it from the
        // blank template (null price), and one value can't fit variants with
        // different prices. The server recomputes it per row.
        if (key === 'billing_summary') {
            return;
        }
        const val = otherInfoSrc[key];
        if (val !== null && val !== undefined && val !== '' && val !== '__unchanged__') {
            otherInfo[key] = val;
        }
    });

    const payload = { ...topLevel };
    if (Object.keys(otherInfo).length > 0) {
        payload.other_info = otherInfo;
    }
    return payload;
};

const saveGroupVariant = async () => {
    if (!props.groupVariantIds.length) {
        Notify.error(translate('Please select at least one variant to update.'));
        return null;
    }
    const updates = buildGroupUpdatePayload();
    if (!Object.keys(updates).length) {
        Notify.error(translate('Please fill in at least one field to update.'));
        return null;
    }
    props.productEditModel.setSaving(true);
    try {
        const response = await Rest.post('products/variants/group-bulk-update', {
            variant_ids: props.groupVariantIds,
            ...updates,
        });
        Notify.success(response.message || translate('Variants updated successfully'));
        changes_made.value = 0;
        emit('createOrUpdateVariant', { group: true, count: props.groupVariantIds.length });
        return response;
    } catch (errors) {
        if (errors && (errors.status_code === 422 || errors.status === 422)) {
            Notify.validationErrors(errors);
        } else {
            Notify.error((errors && errors.data && errors.data.message) || translate('Failed to update variants'));
        }
        return null;
    } finally {
        props.productEditModel.setSaving(false);
    }
};

watch(variant, () => {
    if (isResetting.value || !isReady.value) return;
    changes_made.value++;
}, { deep: true });

watch(
    () => changes_made.value,
    (count) => { emit('dirtyStateChange', count > 0); },
    { immediate: true }
);

defineExpose({
    hasDirtyDrafts:    () => changes_made.value > 0,
    getDirtyDraftCount: () => changes_made.value > 0 ? 1 : 0,
    discardDrafts: async () => {
        isResetting.value = true;
        variant.value     = makeBlankGroupVariant();
        await nextTick();
        changes_made.value = 0;
        isResetting.value  = false;
    },
    saveCurrentDraft: saveGroupVariant,
});
</script>

<template>
    <div class="fct-product-pricing-form-wrap">
        <div class="fct-product-pricing-form-inner">
            <el-form
                label-position="top"
                require-asterisk-position="right"
            >
                <VariantPrice
                    :variant="variant"
                    :field-key="fieldKey"
                    mode-type="update"
                    :product="product"
                    :product-edit-model="productEditModel"
                    :is-group-mode="true"
                />

                <VariantInventory
                    :variant="variant"
                    :field-key="fieldKey"
                    mode-type="update"
                    :product="product"
                    :product-edit-model="productEditModel"
                    :is-group-mode="true"
                />

                <ProductShipping
                    :variant="variant"
                    :field-key="fieldKey"
                    mode-type="update"
                    :product-edit-model="productEditModel"
                    :is-group-mode="true"
                />

                <VariationTaxClass
                    v-if="productEditModel.isTaxEnabled()"
                    :variant="variant"
                    :product-edit-model="productEditModel"
                    :field-key="fieldKey"
                    mode-type="update"
                    :is-group-mode="true"
                />
            </el-form>
        </div>

        <div class="dialog-footer">
            <div class="fct-btn-group sm">
                <el-button @click="emit('closeModal')">
                    {{ translate('Cancel') }}
                </el-button>

                <el-button
                    type="primary"
                    :loading="productEditModel.saving"
                    :disabled="productEditModel.saving"
                    @click="saveGroupVariant"
                >
                    {{
                        /* translators: %1$s: number of variants to bulk-update */
                        translate('Update %1$s variants', groupVariantIds.length)
                    }}
                </el-button>
            </div>
        </div>
    </div>
</template>
