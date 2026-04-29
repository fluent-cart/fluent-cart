<script setup>
import {computed} from 'vue';
import {ArrowDownBold} from '@element-plus/icons-vue';

const props = defineProps({
    modelValue: {
        type: Array,
        default: () => ['']
    }
});

const emit = defineEmits(['update:modelValue']);

const activeCollapse = computed({
    get: () => props.modelValue,
    set: (val) => emit('update:modelValue', val)
});

const isOpen = computed(() => activeCollapse.value.includes('1'));
</script>

<template>
    <div class="fct-variant-item-collapse">
        <el-collapse v-model="activeCollapse" accordion>
            <el-collapse-item name="1" :icon="ArrowDownBold">
                <template #title>
                    <div class="fct-variant-item-collapse-header">
                        <slot name="header" :is-open="isOpen"/>
                    </div>
                </template>
                
                <div class="fct-variant-item-collapse-body">
                    <slot/>
                </div>
            </el-collapse-item>
        </el-collapse>
    </div>
</template>
