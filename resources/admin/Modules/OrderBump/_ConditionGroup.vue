<template>
    <div class="fct_condition_group">
        <div v-for="(line, index) in group" :key="'fct_condition_line_' + index" class="fct_condition_line mb-3">
            <ConditionalLine @remove-item="handleRemoveItem(index)" v-model="group[index]"/>
        </div>
        <el-button type="info" class="fct_add_condition_btn"
                @click="group.push({ condition_key: '', condition_type: '', condition_value: '' })">
          <DynamicIcon name="Plus"/>
          {{ translate('Add Condition') }}
        </el-button>
    </div>
</template>

<script type="text/babel">
import ConditionalLine from "./_ConditionalLine.vue";
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";
import translate from "../../utils/translator/Translator";

export default {
    name: 'ConditionGroup',
    components: {
      DynamicIcon,
        ConditionalLine
    },
    props: {
        modelValue: {
            type: Array,
            default: () => []
        }
    },
    emits: ['update:modelValue', 'removeGroup'],
    data() {
        return {
            group: this.modelValue
        }
    },
    watch: {
        group: {
            deep: true,
            handler(newVal) {
                this.$emit('update:modelValue', newVal);
            }
        }
    },
    methods: {
      translate,
        handleRemoveItem(index) {
            this.group.splice(index, 1);
            if (!this.group.length) {
                this.$emit('removeGroup');
            }
        }
    },
    mounted() {
        if (!this.group?.length) {
            this.group.push({
                key: '',
                operator: '',
                value: ''
            });
        }
    }
}
</script>
