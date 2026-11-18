<script setup>
import {computed} from "vue";
import translate from "@/utils/translator/Translator";
import PlacementPreviewCartCard from "./PlacementPreviewCartCard.vue";
import Delete from "@/Bits/Components/Icons/Delete.vue";
import Minus from "@/Bits/Components/Icons/Minus.vue";
import Plus from "@/Bits/Components/Icons/Plus.vue";

const props = defineProps({
    modelValue: {
        type: Boolean,
        default: false
    },
    bump: {
        type: Object,
        default: null
    }
});

const emit = defineEmits(['update:modelValue']);

const isOpen = computed({
    get: () => props.modelValue,
    set: (value) => emit('update:modelValue', value)
});

const items = [
    {name: '60%', variant: ''},
    {name: '45%', variant: '30%'}
];
</script>

<template>
    <fct-dialog
        v-model="isOpen"
        drawer
        width="420px"
        append-to-body
        :title="translate('Shopping Cart')"
        class="fct-placement-preview-drawer"
    >
        <template #header>
            <div class="fct-placement-preview-drawer__header">
                <span class="fct-placement-preview-drawer__title">{{ translate('Shopping Cart') }}</span>
            </div>
        </template>

        <div class="fct-placement-preview-drawer__content">
            <div class="fct-placement-preview-drawer__scroll">
                <div
                    v-for="item in items"
                    :key="item.name"
                    class="fct-placement-preview-drawer__row"
                >
                    <span class="fct-placement-preview-drawer__thumb"></span>
                    <div class="fct-placement-preview-drawer__info">
                        <span class="fct-placement-preview-bar" :style="{width: item.name}"></span>
                        <span v-if="item.variant" class="fct-placement-preview-bar" :style="{width: item.variant}"></span>
                        <div class="fct-placement-preview-drawer__controls">
                            <div class="fct-placement-preview-drawer__qty">
                                <span class="fct-placement-preview-drawer__qty-btn"><Minus/></span>
                                <span class="fct-placement-preview-drawer__qty-value"></span>
                                <span class="fct-placement-preview-drawer__qty-btn"><Plus/></span>
                            </div>
                        </div>
                    </div>
                    <div class="fct-placement-preview-drawer__side">
                        <span class="fct-placement-preview-bar" style="width: 44px"></span>
                        <span class="fct-placement-preview-drawer__trash"><Delete/></span>
                    </div>
                </div>

                <div v-if="bump" class="fct-placement-preview-drawer__offer">
                    <div class="fct-placement-preview-drawer__label">{{ translate('Add to your order') }}</div>
                    <PlacementPreviewCartCard :bump="bump"/>
                </div>
            </div>

            <div class="fct-placement-preview-drawer__footer">
                <div class="fct-placement-preview-drawer__total">
                    <span class="fct-placement-preview-bar" style="width: 22%"></span><span class="fct-placement-preview-bar" style="width: 56px"></span>
                </div>
                <div class="fct-placement-preview-drawer__checkout"><span class="fct-placement-preview-bar fct-placement-preview-bar--light" style="width: 96px"></span></div>
            </div>
        </div>
    </fct-dialog>
</template>
