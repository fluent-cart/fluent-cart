<script setup>
// Fixture for tests/lint/name-mode-forms.php — NOT shipped code.
//
// This is the exact shape the "Change customer" modal had before the fix: one
// unconditional Full Name input, posted to an endpoint whose CustomerRequest
// requires first_name whenever the store runs the first+last name mode.
import {ref} from "vue";
import MaterialInput from "@/Bits/Components/MaterialInput.vue";

const editableCustomer = ref({
  email: '',
  full_name: '',
});
</script>

<template>
  <div class="fct-compact-form">
    <MaterialInput
        :label="'Full Name'"
        v-model="editableCustomer.full_name"
    />

    <MaterialInput
        :label="'Email'"
        v-model="editableCustomer.email"
    />
  </div>
</template>
