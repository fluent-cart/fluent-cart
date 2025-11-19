<template>
    <div class="fct_condition_line flex items-center gap-2 mb-2">
        <el-select @change="setDefaultValues()" v-model="conditionLine.key"
                   :placeholder="translate('Select Condition')"
                   class="w-1/4">
            <el-option
                v-for="(dataGroup, key) in dataGroups"
                :key="'fct_key_' + key"
                :label="dataGroup.label"
                :value="key">
            </el-option>
        </el-select>

        <div class="w-1/6">
            <el-select v-if="conditionLine.key"
                       v-model="conditionLine.operator"
                       :placeholder="translate('Select Type')"
                       class="flex-1">
                <el-option
                    v-for="(typeLabel, typeKey) in dataGroups[conditionLine.key].types"
                    :key="'fct_operator_' + typeKey"
                    :label="typeLabel"
                    :value="typeKey">
                </el-option>
            </el-select>
            <el-select v-else :disabled="true" :placeholder="translate('Select Condition First')" class="flex-1">
            </el-select>
        </div>

        <div class="flex-1">
            <div v-if="conditionLine.key && conditionLine.operator">
                <template v-if="conditionLine.key === 'order_items'">
                    <ProductVariationSelector v-model="conditionLine.value" :is_multiple="true"/>
                </template>

                <template v-else-if="conditionLine.key === 'order_subtotal'">
                    <el-input
                        v-model="conditionLine.value"
                        type="number"
                        :placeholder="translate('Enter Amount')"
                        class="w-full"/>
                </template>
            </div>
            <div v-else>
                <el-input
                    :disabled="true"
                    :placeholder="translate('Select Condition and Type First')"
                    class="w-full"/>
            </div>
        </div>

      <IconButton
          @click="$emit('removeItem')"
          size="x-small"
          tag="button"
          class="border-0"
      >
        <DynamicIcon name="Cross"/>
      </IconButton>
    </div>
</template>

<script type="text/babel">
import ProductVariationSelector from "@/Bits/Components/ProductVariationSelector.vue";
import IconButton from "@/Bits/Components/Buttons/IconButton.vue";
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";
import translate from "@/utils/translator/Translator";

export default {
    name: 'ConditionalLine',
    components: {
      DynamicIcon,
      IconButton,
        ProductVariationSelector
    },
    props: {
        modelValue: {
            type: Object,
            default: () => ({})
        }
    },
    emits: ['update:modelValue', 'removeItem'],
    data() {
        return {
            conditionLine: this.modelValue,
            dataGroups: {
                order_items: {
                    label: translate('Cart Items'),
                    default_type: 'exist',
                    default_value: [],
                    types: {
                        exist: translate('Exists'),
                        not_exist: translate('Not Exist'),
                    }
                },
                order_subtotal: {
                    label: translate('Items Subtotal'),
                    default_type: 'greater_than',
                    default_value: '',
                    types: {
                        greater_than: translate('Greater Than'),
                        less_than: translate('Less Than'),
                        equals: translate('Equals'),
                        not_equals: translate('Not Equals'),
                    }
                },
            }
        }
    },
    watch: {
        conditionLine: {
            deep: true,
            handler(newVal) {
                this.$emit('update:modelValue', newVal);
            }
        }
    },
    methods: {
      translate,
        setDefaultValues() {
            if (this.conditionLine.key && this.dataGroups[this.conditionLine.key]) {
                this.conditionLine.operator = this.dataGroups[this.conditionLine.key].default_type;
                this.conditionLine.value = this.dataGroups[this.conditionLine.key].default_value;
            } else {
                this.conditionLine.operator = '';
                this.conditionLine.value = '';
            }
        }
    },
    mounted() {
        if (!this.conditionLine || !Object.keys(this.conditionLine).length) {
            this.conditionLine = {
                key: '',
                operator: '',
                value: ''
            };
        }
    }
}
</script>
