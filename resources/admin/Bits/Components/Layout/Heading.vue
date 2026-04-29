<template>
  <div class="heading-wrap">
    <h2 class="title">{{ title }}</h2>

    <div v-if="slots.actions" class="heading-action">
      <slot name="actions"></slot>
    </div>
  </div>
</template>

<script setup>
import {useSlots} from 'vue'

defineProps({
  title: String
})
const slots = useSlots()
</script>