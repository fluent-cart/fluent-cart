<script setup>
import { computed } from "vue";
import BulkMediaPicker from "@/Bits/Components/Attachment/BulkMediaPicker.vue";
import ValidationError from "@/Bits/Components/Inputs/ValidationError.vue";
import SharedVariantItemBox from "@/Modules/Products/parts/SharedVariantItemBox.vue";
import translate from "@/utils/translator/Translator";

const props = defineProps({
    variant: Object,
    fieldKey: String,
    modeType: String,
    product: Object,
    productEditModel: Object,
    // Read-only title for advanced variations: { group, detail } — the
    // group-by attribute value as a heading and the remaining attributes
    // below (derived by the parent).
    titleInfo: Object,
});

// Title + media box is shown for the legacy variable type (simple_variations)
// and for advanced variations.
const showBox = computed(() => {
    return ['simple_variations', 'advanced_variations'].includes(props.product.detail?.variation_type);
});

// Advanced-variation titles are derived from the attribute combination, so the
// title is shown read-only there while the legacy type stays editable.
const isAdvanced = computed(() => {
    return props.product.detail?.variation_type === 'advanced_variations';
});
</script>

<template>
    <SharedVariantItemBox v-if="showBox" class="fct-product-media-and-title">
        <div class="fct-product-media-row">
            <BulkMediaPicker
                v-model="variant.media"
                :featured="true"
                :compact="false"
                @change="value => productEditModel.updatePricingOtherValue('media', value, fieldKey, variant, modeType)"
            />

            <!-- Advanced: the group-by attribute value is the heading (e.g.
                 "Cotton") with the remaining attributes below ("Red / Solid").
                 Read-only — an advanced variant's identity is its attribute
                 combination, not a hand-set title. -->
            <div v-if="isAdvanced" class="fct-variant-title-readonly">
                <div v-if="titleInfo && titleInfo.group" class="fct-variant-title-group text-system-dark dark:text-white">
                    {{ titleInfo.group }}
                </div>
                <div class="fct-variant-title-detail text-system-mid dark:text-gray-200">
                    {{ (titleInfo && titleInfo.detail) || variant.variation_title }}
                </div>
            </div>

            <el-form-item v-else :label="translate('Variation Title')">
                <div class="fct-admin-input-wrapper">
                    <el-input
                        :class="productEditModel.hasValidationError(`${fieldKey}.variation_title`) ? 'is-error' : ''"
                        :id="`${fieldKey}.variation_title`"
                        :placeholder="translate('e.g. Small, Medium, Large')" type="text"
                        v-model="variant.variation_title"
                        @input="value => {productEditModel.updatePricingValue('variation_title', value, fieldKey, variant, modeType)}"
                    >
                    </el-input>

                    <ValidationError
                        :validation-errors="productEditModel.validationErrors"
                        :field-key="`${fieldKey}.variation_title`"
                    />
                </div>
            </el-form-item>
        </div>
    </SharedVariantItemBox>
</template>

<style scoped>
.fct-variant-title-readonly {
    padding: 4px 0;
}

.fct-variant-title-group {
    font-size: 15px;
    font-weight: 600;
    line-height: 1.4;
    word-break: break-word;
}

.fct-variant-title-detail {
    font-size: 13px;
    line-height: 1.4;
    word-break: break-word;
}

</style>

