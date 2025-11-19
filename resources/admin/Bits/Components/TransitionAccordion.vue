<script setup>
const props = defineProps({
  visible: {
    type: Boolean,
    default: false
  },
  duration: {
    type: Number,
    default: 300
  }
});

const transitionDuration = props.duration; // duration in ms

const beforeEnter = (el) => {
  el.style.height = '0';
  el.style.opacity = '0';
  el.style.overflow = 'hidden';
};

const enter = (el, done) => {
  const height = el.scrollHeight + 'px';
  el.style.transition = `height ${transitionDuration}ms ease, opacity ${transitionDuration}ms ease`;

  requestAnimationFrame(() => {
    el.style.height = height;
    el.style.opacity = '1';
  });

  setTimeout(() => done(), transitionDuration);
};

const afterEnter = (el) => {
  el.style.height = 'auto';
  el.style.overflow = '';
};

const beforeLeave = (el) => {
  el.style.height = el.scrollHeight + 'px';
  el.style.opacity = '1';
  el.style.overflow = 'hidden';
};

const leave = (el, done) => {
  // Force reflow
  void el.offsetHeight;

  el.style.transition = `height ${transitionDuration}ms ease, opacity ${transitionDuration}ms ease`;
  el.style.height = '0';
  el.style.opacity = '0';

  const cleanup = () => {
    el.style.transition = '';
    el.style.height = '';
    el.style.opacity = '';
    el.style.overflow = '';
    el.removeEventListener('transitionend', cleanup);
    done();
  };

  el.addEventListener('transitionend', cleanup);
};

const afterLeave = (el) => {
  // No-op now since cleanup is handled in transitionend
};
</script>

<template>
  <transition
      @before-enter="beforeEnter"
      @enter="enter"
      @after-enter="afterEnter"
      @before-leave="beforeLeave"
      @leave="leave"
      @after-leave="afterLeave"
      mode="in-out"
  >
    <div v-if="visible">
      <slot/>
    </div>
  </transition>
</template>


<style scoped>
/* Optional styles if you want extra control */
.fct-advanced-filter-container {
  transition: opacity 0.3s ease, height 0.3s ease;
}
</style>
