<script setup>
defineOptions({
  name: 'ValidationError'
})

const props = defineProps({
  validationErrors: Object,
  fieldKey: String
})


const getValidationErrors = () => {
  const validationErrors = props.validationErrors[props.fieldKey];
  if (typeof validationErrors !== 'object') {
    return '';
  }
  const [[key, value]] = Object.entries(props.validationErrors[props.fieldKey]);
  return { [key]: value };
}


</script>

<template>
  <div class="fluent-cart-admin-validation-error-wrapper"
       v-if="validationErrors && validationErrors.hasOwnProperty(fieldKey)">



    <span class="fluent-cart-admin-validation-error" v-for="(error, errorName) in getValidationErrors()"
          :key="error.toLowerCase().replace(/\s/g, '')">{{ error }}</span>

  </div>
</template>
