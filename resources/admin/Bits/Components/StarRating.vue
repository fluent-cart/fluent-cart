<template>
  <div class="fct-star-rating" :class="sizeClass" role="img" :aria-label="ariaText">
    <template v-for="star in 5" :key="star">
      <span v-if="getStarClass(star) === 'fct-star-half'" class="fct-star fct-star-half" aria-hidden="true">
        <span class="fct-star-half-empty">&#9733;</span>
        <span class="fct-star-half-fill">&#9733;</span>
      </span>
      <span v-else class="fct-star" :class="getStarClass(star)" aria-hidden="true">&#9733;</span>
    </template>
  </div>
</template>

<script setup>
import {computed} from "vue";
import translate from "@/utils/translator/Translator";

const props = defineProps({
  rating: {
    type: Number,
    default: 0
  },
  size: {
    type: String,
    default: 'small',
    validator: (v) => ['small', 'medium', 'large'].includes(v)
  }
});

const fullStars = computed(() => Math.floor(props.rating));
const hasHalfStar = computed(() => (props.rating - fullStars.value) >= 0.5);

const getStarClass = (star) => {
  if (star <= fullStars.value) return 'fct-star-filled';
  if (star === fullStars.value + 1 && hasHalfStar.value) return 'fct-star-half';
  return 'fct-star-empty';
};

const sizeClass = computed(() => `fct-star-rating-${props.size}`);
const ariaText = computed(() => translate('%s out of 5 stars', props.rating));
</script>

<style scoped>
.fct-star-rating {
  display: inline-flex;
  gap: 1px;
}

.fct-star {
  color: #d1d5db;
  line-height: 1;
}

.fct-star-filled {
  color: #f59e0b;
}

.fct-star-half {
  position: relative;
  display: inline-block;
  line-height: 1;
}

.fct-star-half-empty,
.fct-star-half-fill {
  font-size: inherit;
  line-height: inherit;
}

.fct-star-half-empty {
  color: #d1d5db;
}

.fct-star-half-fill {
  position: absolute;
  left: 0;
  top: 0;
  overflow: hidden;
  width: 50%;
  color: #f59e0b;
}

.fct-star-rating-small .fct-star,
.fct-star-rating-small .fct-star-half-empty,
.fct-star-rating-small .fct-star-half-fill {
  font-size: 14px;
}

.fct-star-rating-medium .fct-star,
.fct-star-rating-medium .fct-star-half-empty,
.fct-star-rating-medium .fct-star-half-fill {
  font-size: 18px;
}

.fct-star-rating-large .fct-star,
.fct-star-rating-large .fct-star-half-empty,
.fct-star-rating-large .fct-star-half-fill {
  font-size: 24px;
}
</style>
