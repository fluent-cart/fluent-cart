<script setup>
// Fixture for tests/lint/name-mode-forms.php — NOT shipped code.
//
// False-positive guard: a form that collects full_name but branches on the
// store's name mode is correct and must not be reported.
import {ref} from "vue";
import MaterialInput from "@/Bits/Components/MaterialInput.vue";

const localizedData = window.fluentCartAdminApp || {};
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
        v-if="localizedData.is_full_name_required"
        :label="'Full Name *'"
        v-model="editableCustomer.full_name"
    />

    <template v-else>
      <MaterialInput
          :label="'First Name *'"
          v-model="editableCustomer.first_name"
      />

      <MaterialInput
          :label="'Last Name'"
          v-model="editableCustomer.last_name"
      />
    </template>
  </div>
</template>
