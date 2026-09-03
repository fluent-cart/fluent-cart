<script setup>
/* eslint-disable vue/no-mutating-props --
 * This form is deliberately a "controlled mutation" component: the parent
 * owns the term object (an entry in `drafts[]` or a clone of an editing
 * row) and hands it to us so the form can fill it in directly. Emitting
 * per-field update events would be a lot of plumbing for the same
 * end-state, and the parent is the only other writer.
 */
import { ref, watch, nextTick } from 'vue';
import translate from "@/utils/translator/Translator";
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";
import ValidationError from "@/Bits/Components/Inputs/ValidationError.vue";
import {
    COLOR_SWATCHES,
    COLOR_NAME_MAP,
    getSuggestedColor,
    getColorName,
} from "@/utils/colorPresets";

const props = defineProps({
    // Reactive term: { title, settings: { color, image } }. Used for both
    // draft creation and inline editing — the form mutates these fields
    // directly, the parent owns the object.
    term: {
        type: Object,
        required: true,
    },
    groupType: {
        type: String,
        default: 'options',
    },
    // Hide the inline X (`fct-term-draft-form-close`) when the form is
    // already inside something with its own close affordance — e.g. the
    // AddTermModal el-dialog has a top-right X that emits `remove`.
    showRemoveButton: {
        type: Boolean,
        default: true,
    },
    // Field-keyed validation errors. Lookups are composed dynamically as
    // `fieldKey ? `${fieldKey}.${base}` : base` — parents using the batch
    // endpoint pass the raw server map (`terms.0.title`, …) plus a row
    // identifier like `fieldKey="terms.0"`. The single-update flow passes
    // no fieldKey, so flat keys (`title`, `settings.color`, `settings.image`)
    // resolve directly. Matches VariantPrice's
    // `:field-key="`${fieldKey}.item_price`"` convention.
    validationErrors: {
        type: Object,
        default: () => ({}),
    },
    fieldKey: {
        type: String,
        default: '',
    },
});

const emit = defineEmits([
    'remove',  // X click or Escape — parent decides cancel vs discard
    'submit',  // Enter on title — parent decides save vs add-another
]);

const titleInputRef = ref(null);
const swatchRefs = ref([]);
const swatchFocusIdx = ref(0);
const colorSwatches = COLOR_SWATCHES;

const toTitleCase = (s) => s.replace(/\b\w/g, ch => ch.toUpperCase());

const swatchAriaLabel = (hex) => {
    const name = getColorName(hex);
    return name ? toTitleCase(name) : hex;
};

// Roving tabindex: keep the anchor on the swatch matching the current
// color so Shift+Tab back into the grid lands on the right one.
const syncSwatchFocusFromColor = (color) => {
    if (!color) { swatchFocusIdx.value = 0; return; }
    const idx = colorSwatches.indexOf(color);
    swatchFocusIdx.value = idx >= 0 ? idx : 0;
};

const resolveKey = (base) => (props.fieldKey ? `${props.fieldKey}.${base}` : base);

const hasError = (base) => !!props.validationErrors[resolveKey(base)];

const clearError = (base) => {
    const key = resolveKey(base);
    if (props.validationErrors && props.validationErrors[key]) {
        delete props.validationErrors[key];
    }
};

const onTitleInput = () => {
    clearError('title');
    if (props.groupType !== 'color') return;
    if (props.term.settings.color) return;
    const suggested = getSuggestedColor(props.term.title);
    if (suggested) {
        props.term.settings.color = suggested;
        syncSwatchFocusFromColor(suggested);
        clearError('settings.color');
    }
};

const pickSwatch = (hex) => {
    props.term.settings.color = hex;
    clearError('settings.color');
    const swatchIdx = colorSwatches.indexOf(hex);
    if (swatchIdx >= 0) swatchFocusIdx.value = swatchIdx;
    // Auto-fill the title when it's empty OR when it still matches a known
    // preset name — preserves custom titles like "My Brand Blue".
    const current = (props.term.title || '').trim().toLowerCase();
    const isPresetName = current && COLOR_NAME_MAP[current] !== undefined;
    if (!current || isPresetName) {
        const name = getColorName(hex);
        if (name) {
            props.term.title = toTitleCase(name);
            clearError('title');
        }
    }
};

const onSwatchKeydown = (e) => {
    const target = e.target;
    if (!target || !target.classList || !target.classList.contains('fct-color-swatch')) return;
    const idx = swatchRefs.value.indexOf(target);
    if (idx === -1) return;
    const last = colorSwatches.length - 1;
    let next = -1;
    switch (e.key) {
        case 'ArrowRight':
        case 'ArrowDown':
            next = idx === last ? 0 : idx + 1;
            break;
        case 'ArrowLeft':
        case 'ArrowUp':
            next = idx === 0 ? last : idx - 1;
            break;
        case 'Home':
            next = 0;
            break;
        case 'End':
            next = last;
            break;
        default:
            return;
    }
    e.preventDefault();
    swatchFocusIdx.value = next;
    const el = swatchRefs.value[next];
    if (el && typeof el.focus === 'function') el.focus();
    pickSwatch(colorSwatches[next]);
};

const clearAll = () => {
    props.term.title = '';
    props.term.settings.color = '';
    props.term.settings.image = '';
    syncSwatchFocusFromColor('');
    focus();
};

const openMediaUploader = () => {
    if (!(window.wp && window.wp.media)) return;
    const frame = window.wp.media({
        title: translate('Select Image'),
        multiple: false,
        library: { type: 'image' },
    });
    frame.on('select', () => {
        const attachment = frame.state().get('selection').first().toJSON();
        props.term.settings.image = attachment.url;
        clearError('settings.image');
    });
    frame.open();
};

const focus = () => {
    nextTick(() => {
        if (titleInputRef.value && typeof titleInputRef.value.focus === 'function') {
            titleInputRef.value.focus();
        }
    });
};

// Re-seed focus anchor when the parent swaps in a different term (new
// draft pushed, or a new term opened for edit). Auto-focuses the title.
watch(() => props.term, (newTerm) => {
    syncSwatchFocusFromColor(newTerm?.settings?.color || '');
    focus();
}, { immediate: true });

defineExpose({ focus });
</script>

<template>
    <div class="fct-term-draft-form" :class="`fct-term-draft-form--${groupType}`">
        <div class="fct-term-draft-form-title-line">
            <el-input
                ref="titleInputRef"
                v-model="term.title"
                :placeholder="groupType === 'color' ? translate('e.g. Navy Blue') : translate('Term title')"
                size="default"
                :class="{ 'is-error': hasError('title') }"
                @input="onTitleInput"
                @keyup.enter="emit('submit')"
                @keyup.escape="emit('remove')"
            />
            <button
                v-if="showRemoveButton"
                type="button"
                class="fct-term-draft-form-close"
                :aria-label="translate('Remove')"
                @click="emit('remove')"
            >
                <DynamicIcon name="Cross"/>
            </button>
        </div>
        <ValidationError :validation-errors="validationErrors" :field-key="resolveKey('title')"/>

        <template v-if="groupType === 'color'">
            <div
                class="fct-color-swatch-grid fct-term-draft-swatches"
                role="group"
                :aria-label="translate('Color presets')"
                @keydown="onSwatchKeydown"
            >
                <button
                    v-for="(color, idx) in colorSwatches"
                    :key="color"
                    :ref="el => swatchRefs[idx] = el"
                    type="button"
                    class="fct-color-swatch"
                    :class="{ 'is-active': term.settings.color === color }"
                    :style="{ background: color, '--swatch-color': color }"
                    :title="swatchAriaLabel(color)"
                    :aria-label="swatchAriaLabel(color)"
                    :aria-pressed="term.settings.color === color"
                    :tabindex="idx === swatchFocusIdx ? 0 : -1"
                    @click="pickSwatch(color)"
                />
            </div>
            <div class="fct-color-custom-row fct-term-draft-custom-row">
                <div class="fct-term-color-picker-wrap--lg fct-term-draft-custom-swatch-wrap">
                    <el-color-picker
                        v-model="term.settings.color"
                        :aria-label="translate('Pick a custom color')"
                        @change="clearError('settings.color')"
                    />
                </div>
                <el-input
                    v-model="term.settings.color"
                    placeholder="#000000"
                    size="default"
                    :class="{ 'is-error': hasError('settings.color') }"
                    @input="clearError('settings.color')"
                />
                <button
                    type="button"
                    class="fct-term-draft-clear-btn"
                    :disabled="!term.title && !term.settings.color"
                    :aria-label="translate('Clear title and color')"
                    @click="clearAll"
                >
                    {{ translate('Clear') }}
                </button>
            </div>
            <ValidationError :validation-errors="validationErrors" :field-key="resolveKey('settings.color')"/>
        </template>

        <template v-else-if="groupType === 'image'">
            <div class="fct-term-draft-image-row">
                <button
                    type="button"
                    class="fct-term-draft-img-btn fct-term-draft-img-btn--lg"
                    :aria-label="translate('Upload image')"
                    @click="openMediaUploader"
                >
                    <img v-if="term.settings.image" :src="term.settings.image" alt=""/>
                    <DynamicIcon v-else name="Plus"/>
                </button>
                <el-input
                    v-model="term.settings.image"
                    :placeholder="translate('https://...')"
                    size="default"
                    :class="{ 'is-error': hasError('settings.image') }"
                    @input="clearError('settings.image')"
                />
            </div>
            <ValidationError :validation-errors="validationErrors" :field-key="resolveKey('settings.image')"/>
        </template>

        <!-- Top-level batch-endpoint error (e.g. `terms.required`). Lives
             here so parents don't have to bolt on a separate summary —
             ValidationError self-guards via `v-if`, so non-batch flows
             (where `validationErrors.terms` doesn't exist) render nothing. -->
        <ValidationError :validation-errors="validationErrors" field-key="terms"/>

        <slot name="actions"/>
    </div>
</template>
