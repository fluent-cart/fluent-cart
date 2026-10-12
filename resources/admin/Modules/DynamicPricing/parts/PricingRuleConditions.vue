<script setup>
import translate from "@/utils/translator/Translator";
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";
import ConditionGroup from "@/Modules/DynamicPricing/parts/ConditionGroup.vue";
import {ref, watch} from "vue";

const props = defineProps({
    modelValue: {
        type: Array,
        default: () => []
    }
});

const emit = defineEmits(['update:modelValue']);

const groups = ref(props.modelValue);

/*
 * Re-point at the parent's array when the rule is re-fetched (discard / reload),
 * otherwise this keeps rendering the previous fetch's conditions.
 */
watch(() => props.modelValue, (newVal) => {
    groups.value = newVal;
});

watch(groups, (newVal) => {
    emit('update:modelValue', newVal);
}, {deep: true});

/*
 * New groups start empty — rows are added from the attribute picker, and an
 * empty group still renders its "Add new properties" row.
 */
const addGroup = () => {
    groups.value.push([]);
};

const removeGroup = (index) => {
    groups.value.splice(index, 1);
};
</script>

<template>
    <div class="fct-conditions-wrapper">
        <div v-if="!groups.length"
             class="cond-empty bg-gray-25 dark:bg-dark-500 rounded border border-dashed border-gray-200 dark:border-dark-400 py-10 px-7 text-center">
            <span class="empty-icon">
                <DynamicIcon name="Configuration" class="w-5 h-5 mx-auto"/>
            </span>
            <p>
                {{ translate('To get started, add some conditions for this dynamic price') }}
            </p>
            <el-button @click="addGroup">
                <DynamicIcon name="Plus"/>
                {{ translate('Add Conditions') }}
            </el-button>
        </div>

        <!--
            Placement drives appearance: an or-pill inside .fct-conditions-item is
            the muted separator between groups, the one below the wrap is the
            clickable "+ or" that appends a group.
        -->
        <div v-else class="fct-conditions-container">
            <div class="fct-conditions-wrap">
                <div
                    v-for="(group, index) in groups"
                    :key="index"
                    class="fct-conditions-item"
                >
                    <ConditionGroup
                        v-model="groups[index]"
                        @remove="removeGroup(index)"
                    />

                    <div v-if="index < groups.length - 1" class="fct-conditions-or-wrap">
                        <div class="fct-conditions-or">
                            {{ translate('or') }}
                        </div>
                    </div>
                </div>
            </div>

            <div class="fct-conditions-or-wrap">
                <!--
                    A button, unlike the separator above: this one acts, and it is
                    the only way to add a group once the builder has one.
                -->
                <button
                    type="button"
                    class="fct-conditions-or"
                    :aria-label="translate('Add another condition group')"
                    @click="addGroup"
                >
                    <DynamicIcon name="Plus"/>
                    {{ translate('or') }}
                </button>
            </div>
        </div>
    </div>
</template>
