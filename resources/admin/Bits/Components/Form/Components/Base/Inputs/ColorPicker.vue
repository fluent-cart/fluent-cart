<script setup>
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";
import { defineModel, ref, watch, nextTick } from "vue";
import translate from "@/utils/translator/Translator";
import { COLOR_SWATCHES } from "@/utils/colorPresets";

const model = defineModel();

const props = defineProps({
    field:       { type: Object },
    statePath:   { type: String },
    value:       { default: null },
    form:        { type: Object },
    callback:    { type: Function },
    label:       { type: String },
    attribute:   { default: () => ({}) },
    withPresets: { type: Boolean, default: false },
});

const handleColorReset = () => {
    model.value = '';
};

const matchSwatch = (val) => val
    ? COLOR_SWATCHES.findIndex(h => h.toLowerCase() === val.toLowerCase())
    : -1;

// Roving tabindex: only the active (or first) swatch receives tabindex="0"
const focusedIndex = ref(Math.max(0, matchSwatch(model.value)));

watch(model, (val) => {
    const idx = matchSwatch(val);
    if (idx >= 0) focusedIndex.value = idx;
});

const swatchEls = ref([]);

const selectSwatch = (hex, index) => {
    model.value = hex;
    focusedIndex.value = index;
};

const onSwatchKeydown = (e, index) => {
    let next = index;
    if (e.key === 'ArrowRight' || e.key === 'ArrowDown') {
        next = (index + 1) % COLOR_SWATCHES.length;
    } else if (e.key === 'ArrowLeft' || e.key === 'ArrowUp') {
        next = (index - 1 + COLOR_SWATCHES.length) % COLOR_SWATCHES.length;
    } else if (e.key === 'Home') {
        next = 0;
    } else if (e.key === 'End') {
        next = COLOR_SWATCHES.length - 1;
    } else {
        return;
    }
    e.preventDefault();
    selectSwatch(COLOR_SWATCHES[next], next);
    nextTick(() => {
        if (swatchEls.value[next]) swatchEls.value[next].focus();
    });
};
</script>

<template>
    <template v-if="withPresets">
        <div class="fct-color-presets-section">
            <div
                class="fct-color-swatch-grid"
                role="group"
                :aria-label="translate('Color presets')"
            >
                <button
                    v-for="(hex, index) in COLOR_SWATCHES"
                    :key="hex"
                    :ref="el => swatchEls[index] = el"
                    type="button"
                    class="fct-color-swatch"
                    :class="{ 'is-active': model && model.toLowerCase() === hex.toLowerCase() }"
                    :style="{ background: hex, '--swatch-color': hex }"
                    :aria-label="translate('Select color %1$s', hex)"
                    :aria-pressed="!!(model && model.toLowerCase() === hex.toLowerCase())"
                    :tabindex="index === focusedIndex ? 0 : -1"
                    @click="selectSwatch(hex, index)"
                    @keydown="onSwatchKeydown($event, index)"
                />
            </div>
            <div class="fct-color-custom-row">
                <div class="fct-term-color-picker-wrap fct-term-color-picker-wrap--lg">
                    <el-color-picker
                        v-model="model"
                        color-format="hex"
                        v-bind="attribute"
                        :disabled="field && field.disabled"
                    />
                </div>
                <el-input
                    v-model="model"
                    :placeholder="translate('#000000')"
                    :aria-label="translate('Hex color code')"
                />
            </div>
        </div>
    </template>

    <template v-else>
        <div class="fct-color-picker-box">
            <div class="fct-color-picker-trigger-wrap">
                <el-color-picker
                    v-model="model"
                    v-bind="attribute"
                    :disabled="field && field.disabled"
                    popper-class="fct-color-picker-popover"
                />
                <DynamicIcon v-if="!model" name="ColorPicker" class="fct-color-picker-icon w-4 h-4"/>
            </div>
            <span v-if="!model" class="text-xs text-system-light">{{ translate('Add...') }}</span>
            <div v-if="model" class="fct-color-picker-action-wrap">
                <span>{{ model }}</span>
                <DynamicIcon name="Cross" class="fct-color-picker-action w-5 h-5" @click="handleColorReset"/>
            </div>
        </div>
    </template>
</template>

<style scoped>
</style>
