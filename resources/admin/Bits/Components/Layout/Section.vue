<template>
  <div class="rounded overflow-hidden bg-white dark:bg-dark-800" :class="[
        {'shadow-sm': !disableShadow},
        {'-mx-7.5': fullWidth}
  ]">
    <Heading v-if="title || slots.actions" :title="title">
      <template v-slot:actions v-if="slots.actions">
        <slot name="actions"></slot>
      </template>
    </Heading>

    <slot/>
  </div>
</template>

<script setup>
  import Heading from "./Heading.vue";
  import {useSlots} from "vue";
  const slots = useSlots()
  defineProps({
    fullWidth:Boolean,
    title:String,
    disableShadow:Boolean,
    disableBg: Boolean,
  })
</script>