<template>
  <div class="fct-empty-text">
    <div class="flex items-center justify-center mb-4">
      <DynamicIcon v-if="icon" :name="icon" :has-dark="hasDark" class="w-[100px]"/>
    </div>
    <h3 v-if="title">{{ title }}</h3>
    <p v-if="text">{{ text }}</p>
  </div>
</template>

<script setup>
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";

const props = defineProps({
  icon: {
    type: String,
    required: true
  },

  text: {
    type: String,
    required: false,
    default: null
  },
  hasDark: {
    type: Boolean,
    default: false
  },
  title: {
    type: String,
    required: false,
    default: null
  }
})

</script>
