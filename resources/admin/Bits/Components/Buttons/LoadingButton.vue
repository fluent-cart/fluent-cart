<script setup>
import { computed } from "vue";

const props = defineProps({
  loading: Boolean,
  disabled: Boolean,
  type: { type: String, default: '' },
  size: { type: String, default: '' },
  plain: Boolean,
  nativeType: { type: String, default: 'button' },
  duration: { type: Number, default: 300 },
});

const emit = defineEmits(['click']);

const isDisabled = computed(() => props.disabled || props.loading);

const SPINNER_GAP = '6px';
const transition = () => `width ${props.duration}ms ease, opacity ${props.duration}ms ease, margin-right ${props.duration}ms ease`;

const beforeEnter = (el) => {
  el.style.width = '0';
  el.style.opacity = '0';
  el.style.overflow = 'hidden';
  el.style.marginRight = '6px';
};

const enter = (el, done) => {
  const width = el.scrollWidth + 'px';
  el.style.transition = transition();
  requestAnimationFrame(() => {
    el.style.width = width;
    el.style.opacity = '1';
    el.style.marginRight = '';
  });
  setTimeout(() => done(), props.duration);
};

const afterEnter = (el) => {
  el.style.width = 'auto';
  el.style.overflow = '';
  el.style.marginRight = '';
};

const beforeLeave = (el) => {
  el.style.width = el.scrollWidth + 'px';
  el.style.opacity = '1';
  el.style.overflow = 'hidden';
  el.style.marginRight = SPINNER_GAP;
};

const leave = (el, done) => {
  void el.offsetHeight;
  el.style.transition = transition();
  el.style.width = '0';
  el.style.opacity = '0';
  el.style.marginRight = '0';
  const cleanup = (e) => {
    if (e.propertyName !== 'width') return;
    el.removeEventListener('transitionend', cleanup);
    done();
  };
  el.addEventListener('transitionend', cleanup);
};
</script>

<template>
  <button
      :type="nativeType"
      :class="[
        'el-button',
        type && `el-button--${type}`,
        size && `el-button--${size}`,
        { 'is-plain': plain, 'is-loading': loading, 'is-disabled': isDisabled }
      ]"
      :disabled="isDisabled"
      :aria-disabled="isDisabled"
      @click="emit('click', $event)"
  >
    <transition
        @before-enter="beforeEnter"
        @enter="enter"
        @after-enter="afterEnter"
        @before-leave="beforeLeave"
        @leave="leave"
    >
      <span v-if="loading" class="fct-btn-spinner">
        <i class="el-icon is-loading">
          <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1024 1024">
            <path fill="currentColor" d="M512 64a32 32 0 0 1 32 32v192a32 32 0 0 1-64 0V96a32 32 0 0 1 32-32m0 640a32 32 0 0 1 32 32v192a32 32 0 1 1-64 0V736a32 32 0 0 1 32-32m448-192a32 32 0 0 1-32 32H736a32 32 0 1 1 0-64h192a32 32 0 0 1 32 32m-640 0a32 32 0 0 1-32 32H96a32 32 0 0 1 0-64h192a32 32 0 0 1 32 32M195.2 195.2a32 32 0 0 1 45.248 0L376.32 331.008a32 32 0 0 1-45.248 45.248L195.2 240.448a32 32 0 0 1 0-45.248zm452.544 452.544a32 32 0 0 1 45.248 0L828.8 783.552a32 32 0 0 1-45.248 45.248L647.744 692.992a32 32 0 0 1 0-45.248zM828.8 195.264a32 32 0 0 1 0 45.184L692.992 376.32a32 32 0 0 1-45.248-45.248l135.808-135.808a32 32 0 0 1 45.248 0m-452.544 452.48a32 32 0 0 1 0 45.248L240.448 828.8a32 32 0 0 1-45.248-45.248l135.808-135.808a32 32 0 0 1 45.248 0z"/>
          </svg>
        </i>
      </span>
    </transition>

    <span><slot/></span>
  </button>
</template>

<style scoped>


.el-button{
  gap: 0;
}
</style>
