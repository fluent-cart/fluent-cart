<script setup>

defineProps({
  title: String,
  text: String
});

</script>

<template>
  <div class="fct-customer-dashboard-heading" role="heading" aria-level="3">
    <h3 class="title">{{ title }}</h3>
    <p class="text">{{ text }}</p>
  </div>
</template>
