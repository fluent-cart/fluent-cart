<script setup>
import translate from "@/utils/translator/Translator";
import PlacementPreviewCartCard from "./PlacementPreviewCartCard.vue";
import Delete from "@/Bits/Components/Icons/Delete.vue";
import Minus from "@/Bits/Components/Icons/Minus.vue";
import Plus from "@/Bits/Components/Icons/Plus.vue";

defineProps({
    bump: {
        type: Object,
        default: null
    }
});

const items = [
    {name: '60%', variant: ''},
    {name: '45%', variant: '30%'}
];
</script>

<template>
    <div class="fct-placement-preview-cartpage">
        <h2 class="fct-placement-preview-cartpage__title">{{ translate('Cart') }}</h2>

        <div
            v-for="item in items"
            :key="item.name"
            class="fct-placement-preview-cartpage__row"
        >
            <span class="fct-placement-preview-cartpage__thumb"></span>
            <div class="fct-placement-preview-cartpage__info">
                <span class="fct-placement-preview-bar" :style="{width: item.name}"></span>
                <span v-if="item.variant" class="fct-placement-preview-bar" :style="{width: item.variant}"></span>
                <div class="fct-placement-preview-cartpage__qty">
                    <span class="fct-placement-preview-cartpage__qty-btn"><Minus/></span>
                    <span class="fct-placement-preview-cartpage__qty-value"></span>
                    <span class="fct-placement-preview-cartpage__qty-btn"><Plus/></span>
                </div>
            </div>
            <div class="fct-placement-preview-cartpage__side">
                <span class="fct-placement-preview-bar" style="width: 44px"></span>
                <span class="fct-placement-preview-cartpage__trash"><Delete/></span>
            </div>
        </div>

        <div v-if="bump" class="fct-placement-preview-cartpage__offer">
            <div class="fct-placement-preview-cartpage__label">{{ translate('Add to your order') }}</div>
            <PlacementPreviewCartCard :bump="bump"/>
        </div>

        <div class="fct-placement-preview-cartpage__total">
            <span class="fct-placement-preview-bar" style="width: 22%"></span><span class="fct-placement-preview-bar" style="width: 56px"></span>
        </div>
        <div class="fct-placement-preview-cartpage__checkout"><span class="fct-placement-preview-bar fct-placement-preview-bar--light" style="width: 96px"></span></div>
    </div>
</template>
