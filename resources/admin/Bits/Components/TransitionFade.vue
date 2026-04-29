<script setup>
const props = defineProps({
  visible: {
    type: Boolean,
    default: false
  }
});

</script>
<template>
    <Transition>
        <slot v-if="visible"></slot>
    </Transition>
</template>

<style scoped>
/* we will explain what these classes do next! */
.v-enter-active,
.v-leave-active {
  transition: all .3s ease-in;
  transform: scale(1);
}

.v-enter-from,
.v-leave-to {
  opacity: 0;
  transition: all .3s ease-out;
  transform: scale(.8);
}
</style>
