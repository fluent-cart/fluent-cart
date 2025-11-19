<template>
    <div class="fct_bump_conditions">
        <el-checkbox @change="maybePrepareData()" true-value="yes" false-value="no" v-model="bumpConditions.is_enabled">
          {{ translate('Enable Conditions for this Order Bump') }}
        </el-checkbox>

        <div class="fct_bump_condition_groups mt-4" v-if="bumpConditions.is_enabled === 'yes'">
            <div v-for="(group, index) in bumpConditions.groups" :key="'fct_group_' + index"
                 class="fct_bump_condition_group_wrap">
              <div class="fct_bump_condition_group">
                <ConditionGroup @removeGroup="handleRemoveGroup(index)" v-model="bumpConditions.groups[index]"/>
              </div>
              <div v-if="index !== bumpConditions.groups.length - 1"  class="fct_bump_condition_group_or_separator">
                <span>{{ translate('OR') }}</span>
              </div>
            </div>
        </div>

        <el-button v-if="bumpConditions.is_enabled === 'yes'" type="primary" @click="bumpConditions.groups.push([])" class="mt-4">
          <DynamicIcon name="Plus"/>
          {{ translate('Add New Condition Group') }}
        </el-button>

    </div>
</template>

<script type="text/babel">
import ConditionGroup from "./_ConditionGroup.vue";
import translate from "../../utils/translator/Translator";
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";

export default {
    name: 'BumpConditions',
    components: {
      DynamicIcon,
        ConditionGroup
    },
    props: {
        modelValue: {
            type: Object,
            default: () => ({
                is_enabled: 'no',
                groups: []
            })
        }
    },
    watch: {
        bumpConditions: {
            deep: true,
            handler(newVal) {
                this.$emit('update:modelValue', newVal);
            }
        }
    },
    emits: ['update:modelValue'],
    data() {
        return {
            bumpConditions: this.modelValue
        }
    },
    methods: {
      translate,
        maybePrepareData() {
            if (this.bumpConditions.is_enabled === 'yes' && !this.bumpConditions.groups?.length) {
                this.bumpConditions.groups = [];
                this.bumpConditions.groups.push([
                    {
                        key: '',
                        operator: '',
                        value: ''
                    }
                ]);
            }
        },
        handleRemoveGroup(index) {
            this.bumpConditions.groups.splice(index, 1);
            if (!this.bumpConditions.groups.length) {
                this.bumpConditions.is_enabled = 'no';
            }
        }
    },
    mounted() {
        if (!this.bumpConditions || !this.bumpConditions?.groups) {
            this.bumpConditions = {
                is_enabled: 'no',
                groups: []
            };
        }
    }
}
</script>
