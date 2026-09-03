<script setup>
// Fixture for tests/lint/name-mode-forms.php — NOT shipped code.
//
// The bypass the first version of this rule allowed: the guard token appears
// in the file, so a plain substring check called it compliant, but nothing
// branches on it. Both name modes render at once and the form still cannot
// follow the store setting.
//
// The token appears twice below in ways that must NOT satisfy the rule: once
// in this comment (is_full_name_required) and once in a dead assignment.
import {ref} from "vue";
import MaterialInput from "@/Bits/Components/MaterialInput.vue";

const unusedMode = window.fluentCartAdminApp.is_full_name_required;

const editableCustomer = ref({
  email: '',
  full_name: '',
  first_name: '',
  last_name: '',
});
</script>

<template>
  <div class="fct-compact-form">
    <MaterialInput
        :label="'Full Name'"
        v-model="editableCustomer.full_name"
    />

    <MaterialInput
        :label="'First Name'"
        v-model="editableCustomer.first_name"
    />

    <MaterialInput
        :label="'Last Name'"
        v-model="editableCustomer.last_name"
    />
  </div>
</template>
