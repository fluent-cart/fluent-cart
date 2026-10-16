<script setup>
import {computed, ref, watch} from "vue";
import translate from "@/utils/translator/Translator";
import IconButton from "@/Bits/Components/Buttons/IconButton.vue";
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";
import ResourceSelector from "@/Modules/DynamicPricing/parts/ResourceSelector.vue";
import PriceInput from "@/Bits/Components/Inputs/PriceInput.vue";
import {getAttribute} from "@/Modules/DynamicPricing/conditionAttributes";

const props = defineProps({
    modelValue: {
        type: Object,
        default: () => ({key: '', operator: '', value: ''})
    }
});

const emit = defineEmits(['update:modelValue', 'remove']);

const condition = ref(props.modelValue);

/*
 * Rows are keyed by index, so Vue reuses this instance when an earlier row is
 * removed — re-point at whatever object the parent now hands us.
 */
watch(() => props.modelValue, (newVal) => {
    condition.value = newVal;
});

watch(condition, (newVal) => {
    emit('update:modelValue', newVal);
}, {deep: true});

/*
 * A saved key may not exist in the registry — the attribute was removed, an
 * add-on that registered it was deactivated, or the payload came from outside.
 * Resolve defensively so the row still renders and stays removable.
 */
const selectedAttribute = computed(() => getAttribute(condition.value.key));

/*
 * The attribute is chosen once, from the picker, and shown as static text
 * afterwards — changing it means removing the row and adding another.
 */
const attributeLabel = computed(() => {
    if (selectedAttribute.value) {
        return selectedAttribute.value.label;
    }

    /* translators: %1$s: stored condition attribute key that is no longer registered */
    return translate('%1$s (unavailable)', condition.value.key);
});

const groupLabel = computed(() => selectedAttribute.value ? selectedAttribute.value.group : '');

const operatorOptions = computed(() => selectedAttribute.value ? selectedAttribute.value.operators : {});

const valueInputType = computed(() => selectedAttribute.value?.input_type === 'number' ? 'number' : 'text');

/*
 * A select attribute names the data set its options come from, and the picker
 * asks the shared filter endpoint for it. An attribute registered later needs
 * only that key on the backend — nothing here changes.
 */
const remoteDataKey = computed(() => {
    if (selectedAttribute.value?.input_type !== 'select') {
        return '';
    }

    return selectedAttribute.value.data_key || '';
});

/*
 * Whether this attribute takes several values. Declared in the registry rather
 * than assumed here, so an attribute that only ever matches one row keeps a
 * single-value picker.
 */
const allowsMultiple = computed(() => selectedAttribute.value?.multiple === true);

const isCurrency = computed(() => selectedAttribute.value?.input_type === 'currency');
</script>

<template>
    <tr>
        <td class="w-[250px] fct-conditions-label">
            <template v-if="groupLabel">
                {{ groupLabel }}
                <span class="fct-conditions-separator">/</span>
            </template>
            {{ attributeLabel }}
        </td>

        <td class="fct-conditions-operator w-[190px]">
            <el-select
                v-model="condition.operator"
                size="small"
                :placeholder="translate('Select a condition')"
            >
                <el-option
                    v-for="(label, operator) in operatorOptions"
                    :key="operator"
                    :value="operator"
                    :label="label"
                />
            </el-select>
        </td>

        <td class="fct-conditions-value">
            <ResourceSelector
                v-if="remoteDataKey"
                v-model="condition.value"
                :data-key="remoteDataKey"
                :multiple="allowsMultiple"
                :placeholder="translate('Search and select')"
            />

            <!-- Saved in cents, which PriceInput takes and emits as is. -->
            <PriceInput
                v-else-if="isCurrency"
                v-model="condition.value"
                size="small"
                :placeholder="translate('Enter an amount')"
            />

            <el-input
                v-else
                v-model="condition.value"
                size="small"
                :type="valueInputType"
                :placeholder="translate('Enter a value')"
            />
        </td>

        <td class="w-[40px] text-end fct-conditions-action">
            <IconButton
                size="x-small"
                bg="info"
                tag="button"
                :aria-label="translate('Remove condition')"
                @click="emit('remove')"
            >
                <DynamicIcon name="Delete"/>
            </IconButton>
        </td>
    </tr>
</template>
