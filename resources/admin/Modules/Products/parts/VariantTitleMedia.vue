<script setup>
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
});
</script>

<template>
    <SharedVariantItemBox v-if="product.detail?.variation_type === 'simple_variations'" class="fct-product-media-and-title">
        <div class="fct-product-media-row">
            <BulkMediaPicker
                v-model="variant.media"
                :featured="true"
                :compact="false"
                @change="value => productEditModel.updatePricingOtherValue('media', value, fieldKey, variant, modeType)"
            />

            <el-form-item :label="translate('Variation Title')">
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
