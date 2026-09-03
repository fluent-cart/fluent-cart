<script setup>
// Fixture for tests/lint/name-mode-forms.php — NOT shipped code.
//
// False-positive guard: the order screen binds a customer *search* box to
// `entity.customer.full_name`. It submits no name, so it is not a name field
// and must not be reported even though this file never reads the name mode.
import {ref} from "vue";

const entity = ref({customer: {full_name: ''}});
</script>

<template>
  <el-autocomplete
      v-model="entity.customer.full_name"
      :placeholder="'Search or create a customer'"
  />
</template>
