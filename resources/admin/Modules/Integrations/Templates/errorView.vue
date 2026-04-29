<template>
  <p class="fct-inline-error ff" v-if="errors.has(field)">{{ errors.errors[field].required ? Str.headline(errors.errors[field].required) : errors.errors[field] }}</p>
</template>

<script>
import Str from "@/utils/support/Str";
export default {
  name: 'errorView',
  props: ['field', 'errors'],
  computed: {
    Str() {
      return Str
    }
  },
}
</script>

