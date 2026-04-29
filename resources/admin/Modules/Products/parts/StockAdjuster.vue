<script setup>
import translate from "@/utils/translator/Translator";
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";
import {ref} from 'vue';

const props = defineProps({
    variant: Object,
    fieldKey: String,
    productEditModel: Object,
});

const emit = defineEmits(['save']);

const visible = ref(false);
</script>

<template>
    <el-popover :visible="visible" popper-class="fct-stock-dropdown" trigger="click" placement="bottom-end">
        <div class="fct-adjust-by-wrap">
            <div class="fct-adjust-by-row">
                <div class="fct-adjust-by-col">
                    <span class="title">
                        {{ translate('Adjust by') }}
                    </span>
                    <el-input
                        size="small"
                        :placeholder="translate('Quantity')"
                        type="number"
                        v-model.number="variant.adjusted_quantity"
                        @keyup="event => { productEditModel.onChangeAdjustedQuantity('adjusted_quantity', event.target.value, fieldKey) }"
                        @change="value => { productEditModel.onChangeAdjustedQuantity('adjusted_quantity', value, fieldKey) }"
                    />
                </div>
                <div class="fct-adjust-by-col">
                    <span class="title">
                        {{ translate('New Stock') }}
                    </span>
                    <el-input
                        size="small"
                        :placeholder="translate('New Stock')"
                        type="number"
                        :min="1"
                        v-model.number="variant.new_stock"
                        @keyup="event => { productEditModel.onChangeNewStock('new_stock', event.target.value, fieldKey) }"
                    />
                </div>
            </div>
            <div class="fct-adjust-by-action">
                <el-button
                    size="small"
                    type="info"
                    soft
                    @click="visible = false"
                >
                    {{ translate('Cancel') }}
                </el-button>
                <el-button
                    size="small"
                    type="primary"
                    @click="visible = false; emit('save')"
                    :disabled="variant.new_stock === ''"
                >
                    {{ translate('Apply') }}
                </el-button>
            </div>
        </div>

        <template #reference>
            <div @click="visible = !visible" class="fct-stock-adjuster-trigger">
                <DynamicIcon name="Configuration"/>
            </div>
        </template>
    </el-popover>
</template>
