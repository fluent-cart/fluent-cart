<script setup>
defineOptions({
  name: 'ValidationError'
})

defineProps({
  errorKey: String,
  form: {
    type: Object,
    required: true
  },
})


</script>

<template>
  <div :class="`fluent-cart-admin-validation-error-wrapper ${errorKey}`" v-if="form.hasValidationError(errorKey)">
    <span class="fluent-cart-admin-validation-error" v-for="(error, errorName) in form.getValidationError(errorKey)"
          :key="error.toLowerCase().replace(/\s/g, '')">{{ error }}</span>
  </div>
</template>
