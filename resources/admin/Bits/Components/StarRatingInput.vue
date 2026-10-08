<template>
  <div
      class="fct-star-rating-input"
      :class="`fct-star-rating-input-${size}`"
  >
    <div
        class="fct-star-rating-input__stars"
        role="radiogroup"
        :aria-label="label"
    >
      <button
          v-for="star in 5"
          :key="star"
          :ref="(el) => (starRefs[star - 1] = el)"
          type="button"
          role="radio"
          class="fct-star-rating-input__star"
          :class="{'is-filled': star <= (hoverValue || modelValue), 'is-selected': star <= modelValue}"
          :aria-checked="modelValue === star"
          :aria-label="starLabel(star)"
          :tabindex="modelValue === star || (!modelValue && star === 1) ? 0 : -1"
          :disabled="disabled"
          @click="toggle(star)"
          @mouseenter="hoverValue = star"
          @mouseleave="hoverValue = 0"
          @focus="hoverValue = star"
          @blur="hoverValue = 0"
          @keydown.left.prevent="move(-1)"
          @keydown.right.prevent="move(1)"
          @keydown.up.prevent="move(1)"
          @keydown.down.prevent="move(-1)"
      >
        <ReviewStar class="fct-star-rating-input__svg"/>
      </button>
    </div>

    <!-- The word for the star under the pointer, or the chosen one, as a
         pill beside the row — the storefront's tooltip, kept in the flow so
         it needs no room below and never covers the next field. aria-hidden:
         each star already names itself. -->
    <span
        v-if="hoverValue || modelValue"
        class="fct-star-rating-input__badge"
        :class="`is-${ratingTone(hoverValue || modelValue)}`"
        aria-hidden="true"
    >{{ ratingWord(hoverValue || modelValue) }}</span>
  </div>
</template>

<script setup>
import {nextTick, ref} from "vue";
import translate from "@/utils/translator/Translator";
import ReviewStar from "@/Bits/Components/Icons/ReviewStar.vue";

const props = defineProps({
  modelValue: {
    type: Number,
    default: 0
  },
  size: {
    type: String,
    default: 'medium',
    validator: (v) => ['small', 'medium', 'large'].includes(v)
  },
  label: {
    type: String,
    default: () => translate('Rating')
  },
  disabled: {
    type: Boolean,
    default: false
  }
});

const emit = defineEmits(['update:modelValue']);

// Hover/focus preview only — never written back to the model, so the
// committed rating survives the pointer leaving the row.
const hoverValue = ref(0);
const starRefs = ref([]);

const select = (value) => {
  if (props.disabled) {
    return;
  }
  emit('update:modelValue', value);
};

// Clicking the chosen star takes the rating back, as on the storefront and
// on Google, so the form has no separate Clear control. The hover fill is
// dropped with it: the pointer is still on the star, and the fill would keep
// it looking chosen until the mouse moved on.
const toggle = (star) => {
  const cleared = star === props.modelValue;
  select(cleared ? 0 : star);
  if (cleared) {
    hoverValue.value = 0;
  }
};

/**
 * Arrow-key step. A radiogroup moves focus WITH the selection, so the new
 * star is focused as well as checked — the roving tabindex alone would leave
 * the caret on the old star. hoverValue is set here rather than left to the
 * focus handler because the fill is drawn from it: without this the row would
 * keep showing the previous star count until focus actually lands.
 */
const move = (delta) => {
  if (props.disabled) {
    return;
  }
  const next = Math.min(5, Math.max(1, (props.modelValue || 0) + delta));
  select(next);
  hoverValue.value = next;
  nextTick(() => starRefs.value[next - 1]?.focus());
};

/* translators: %1$s: a star count from 1 to 5 */
const starLabel = (star) => translate('Rate %1$s out of 5 stars', star);

// The storefront's words and tones for each star count, so an admin reads
// the same scale a customer does.
const ratingWords = {
  1: translate('Poor'),
  2: translate('Average'),
  3: translate('Good'),
  4: translate('Very Good'),
  5: translate('Excellent'),
};
const ratingTones = {1: 'danger', 2: 'warning', 3: 'info', 4: 'success', 5: 'success'};

const ratingWord = (value) => ratingWords[value] || '';
const ratingTone = (value) => ratingTones[value] || 'none';
</script>
