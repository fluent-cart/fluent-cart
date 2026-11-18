<script setup>
import {computed} from "vue";
import CurrencyFormatter from "@/utils/support/CurrencyFormatter";

const props = defineProps({
  amount: {
    type: Number,
    required: true
  },
  placement: {
    type: String,
    default: 'top'
  }
})

const formattedAmount = computed(() => {
  return CurrencyFormatter.formatScaled(props.amount);
})
</script>

<template>
  <fct-tooltip :placement="placement">
    <template #content>
      <span>{{ formattedAmount }}</span>
    </template>
    <span class="cursor-pointer">{{ formattedAmount }}</span>
  </fct-tooltip>
</template>

<style scoped>

</style>
