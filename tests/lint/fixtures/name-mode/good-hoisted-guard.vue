<script setup>
// Fixture for tests/lint/name-mode-forms.php — NOT shipped code.
//
// False-positive guard: hoisting the condition into a local is a legitimate
// refactor. The directive names `useFullName`, not the token, and the rule
// must resolve that one level of indirection rather than reporting the file.
import {computed, ref} from "vue";
import MaterialInput from "@/Bits/Components/MaterialInput.vue";

const localizedData = window.fluentCartAdminApp || {};
const useFullName = computed(() => localizedData.is_full_name_required);

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
        v-if="useFullName"
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
