<script setup>
const props = defineProps({
  visible: {
    type: Boolean,
    default: false
  },
  duration: {
    type: Number,
    default: 300
  },
  horizontal: {
    type: Boolean,
    default: false
  }
});

const transitionDuration = props.duration;

const beforeEnter = (el) => {
  if (props.horizontal) {
    el.style.width = '0';
  } else {
    el.style.height = '0';
  }
  el.style.opacity = '0';
  el.style.overflow = 'hidden';
};

const enter = (el, done) => {
  if (props.horizontal) {
    const width = el.scrollWidth + 'px';
    el.style.transition = `width ${transitionDuration}ms ease, opacity ${transitionDuration}ms ease`;
    requestAnimationFrame(() => {
      el.style.width = width;
      el.style.opacity = '1';
    });
  } else {
    const height = el.scrollHeight + 'px';
    el.style.transition = `height ${transitionDuration}ms ease, opacity ${transitionDuration}ms ease`;
    requestAnimationFrame(() => {
      el.style.height = height;
      el.style.opacity = '1';
    });
  }

  setTimeout(() => done(), transitionDuration);
};

const afterEnter = (el) => {
  if (props.horizontal) {
    el.style.width = 'auto';
  } else {
    el.style.height = 'auto';
  }
  el.style.overflow = '';
};

const beforeLeave = (el) => {
  if (props.horizontal) {
    el.style.width = el.scrollWidth + 'px';
  } else {
    el.style.height = el.scrollHeight + 'px';
  }
  el.style.opacity = '1';
  el.style.overflow = 'hidden';
};

const leave = (el, done) => {
  void el.offsetHeight;

  if (props.horizontal) {
    el.style.transition = `width ${transitionDuration}ms ease, opacity ${transitionDuration}ms ease`;
    el.style.width = '0';
  } else {
    el.style.transition = `height ${transitionDuration}ms ease, opacity ${transitionDuration}ms ease`;
    el.style.height = '0';
  }
  el.style.opacity = '0';

  const sizeProperty = props.horizontal ? 'width' : 'height';
  const cleanup = (e) => {
    if (e.propertyName !== sizeProperty) return;
    el.removeEventListener('transitionend', cleanup);
    done();
  };

  el.addEventListener('transitionend', cleanup);
};

const afterLeave = (el) => {};
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
