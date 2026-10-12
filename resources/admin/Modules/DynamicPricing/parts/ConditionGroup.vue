<script setup>
import {nextTick, ref, watch} from "vue";
import translate from "@/utils/translator/Translator";
import ConditionRow from "@/Modules/DynamicPricing/parts/ConditionRow.vue";
import ConditionCascaderPanel from "@/Modules/DynamicPricing/parts/ConditionCascaderPanel.vue";
import IconButton from "@/Bits/Components/Buttons/IconButton.vue";
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";
import {conditionGroups} from "@/Modules/DynamicPricing/conditionAttributes";

const props = defineProps({
    modelValue: {
        type: Array,
        default: () => []
    }
});

const emit = defineEmits(['update:modelValue', 'remove']);

const group = ref(props.modelValue);
const popoverRef = ref(null);
const selectedPath = ref([]);

/*
 * Groups are keyed by index, so Vue reuses this instance when an earlier group
 * is removed — re-point at whatever array the parent now hands us.
 */
watch(() => props.modelValue, (newVal) => {
    group.value = newVal;
});

watch(group, (newVal) => {
    emit('update:modelValue', newVal);
}, {deep: true});

const addCondition = (key) => {
    if (!key) {
        return;
    }

    group.value.push({key, operator: '', value: ''});
    selectedPath.value = [];

    nextTick(() => {
        if (popoverRef.value) {
            popoverRef.value.hide();
        }
    });
};

/*
 * el-cascader-panel hands back the full path down to the attribute —
 * [group, attribute], or [group, nested group, attribute]. Only the attribute
 * key is stored, since a condition never records its group.
 */
const selectFromCascader = (path) => {
    if (Array.isArray(path) && path.length >= 2) {
        addCondition(path[path.length - 1]);
    }
};

const removeCondition = (index) => {
    group.value.splice(index, 1);
};
</script>

<template>
    <div class="fct-conditions-group">
        <table v-if="group.length" class="w-full">
            <tbody>
                <ConditionRow
                    v-for="(condition, index) in group"
                    :key="index"
                    v-model="group[index]"
                    @remove="removeCondition(index)"
                />
            </tbody>
        </table>

        <div class="fct-conditions-intro">
            <el-popover
                ref="popoverRef"
                placement="right"
                width="auto"
                popper-class="fct-conditions-popover"
                trigger="click"
            >
                <div class="fct-cascader-panel-wrap">
                    <ConditionCascaderPanel
                        :options="conditionGroups"
                        @select="addCondition"
                    />

                    <el-cascader-panel
                        v-model="selectedPath"
                        style="width: 100%"
                        :options="conditionGroups"
                        @change="selectFromCascader"
                    />
                </div>

                <template #reference>
                    <el-button class="el-button--x-small">
                        <DynamicIcon name="Plus"/>
                        {{ translate('Add') }}
                    </el-button>
                </template>
            </el-popover>

            <span class="px-2">{{ translate('Add new properties') }}</span>

            <IconButton
                v-if="!group.length"
                size="x-small"
                bg="info"
                tag="button"
                class="float-end"
                :aria-label="translate('Remove group')"
                @click="emit('remove')"
            >
                <DynamicIcon name="Delete"/>
            </IconButton>
        </div>
    </div>
</template>
