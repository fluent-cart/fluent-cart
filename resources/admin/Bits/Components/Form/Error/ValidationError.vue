<script setup>
defineOptions({
  name: 'ValidationError'
})

defineProps({
  validationErrors: Object,
  fieldKey: {
    type: String,
    default: ''
  }
})


</script>

<template>

  <div class="fluent-cart-admin-validation-error-wrapper `${fieldKey}`">
    <span class="fluent-cart-admin-validation-error" v-for="(error, errorName) in validationErrors"
          :key="error.toLowerCase().replace(/\s/g, '')">{{ error }}</span>
  </div>
</template>
