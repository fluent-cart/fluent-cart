<template>
  <fct-dialog
      class="fct-role-assignment-modal"
      :title="title"
      v-model="modelValue"
      @closed="closeModal"
      :append-to-body="true"
  >

    <div class="role-form">
      <div>
        <fct-select
            v-if="!isEditing"
            :label="translate('Select User')"
            :validation-errors="validationErrors"
            field-key="user_id"
            v-model="selectedUser"
            :options="userOptions"
            :remote="true"
            :remote-method="searchUsers"
            :loading="loadingUsers"
            filterable
            clearable
            :placeholder="translate('Type to search user')"
            class="w-full"
            @change="() => { delete validationErrors['user_id'] }"
        >
          <template #option="{option}">
            <div class="select-use-option">
              <span>{{ option.label }}</span>
              <span>{{ option.email }}</span>
            </div>
          </template>
        </fct-select>

        <fct-select
            id="role_key"
            :label="translate('Select Role')"
            :validation-errors="validationErrors"
            field-key="role_key"
            @change="(currentUserName)=>{
              delete validationErrors['role_key']
            }"
            v-model="selectedRole"
            :options="roleOptions"
            :placeholder="translate('Select a role')"
            class="w-full"
            popper-class="fct-select-role-dropdown"
        >
          <template #option="{option}">
            <div class="select-label">
              {{ option.label }}
            </div>
            <div v-if="option.description" class="select-desc">
              {{ option.description }}
            </div>
          </template>
        </fct-select>
      </div>
    </div>

    <template #footer>
      <span class="dialog-footer">
        <fct-button @click="closeModal">
          {{ translate('Cancel') }}
        </fct-button>
        <fct-button
            type="primary"
            @click="attachRole"
            :loading="submitting"
            :disabled="submitting"
        >
          {{ isEditing ? translate('Update Role') : translate('Attach Role') }}
        </fct-button>
      </span>
    </template>
  </fct-dialog>
</template>

<script setup>
import {onMounted, ref, watch, computed} from 'vue';
import Rest from "@/utils/http/Rest";
import Notify from "@/utils/Notify";
import translate from "@/utils/translator/Translator";

const modelValue = ref(false);
const props = defineProps({});
const emit = defineEmits(['fetchRoles']);

const submitting = ref(false);
const selectedUser = ref(null);
const selectedRole = ref(null);
const isEditing = ref(false);
const users = ref(null);
const roles = ref(null);
const validationErrors = ref({});
const userOptions = computed(() => (users.value || []).map(user => ({label: user.name, value: user.ID, email: user.email})));
const roleOptions = computed(() => Object.entries(roles.value || {}).map(([roleKey, role]) => ({label: role.title, value: roleKey, description: role.description})));

const loadingUsers = ref(false);
const searchUsers = (query) => {

  if (!query) {
    users.value = [];
    return;
  }
  Rest.get('roles/user-list', {search: query}).then(response => {
    users.value = response.users.data;
  }).finally(() => {

  });
}

const getUserRoles = () => {
  Rest.get('roles')
      .then(response => {
        roles.value = response.roles;
      })
      .catch((error) => {
        if (error.message) {
          Notify.error(error.message);
          return;
        }
      })
      .finally(() => {

      });
}


const attachRole = () => {
  validationErrors.value = {};
  submitting.value = true;
  Rest.post('roles', {
    user_id: selectedUser.value,
    role_key: selectedRole.value,
  }).then((response, status) => {
    modelValue.value = false;
    Notify.success(response.message);
    emit('fetchRoles');
  }).catch((error) => {
    if (error.data?.message) {
      Notify.error(error.data.message);
    } else {
      validationErrors.value = error.data;
    }

  }).finally(() => {
    submitting.value = false;
  });

}

const closeModal = () => {
  selectedUser.value = null;
  selectedRole.value = null;
  isEditing.value = false;
  validationErrors.value = {};
  modelValue.value = false;
}


const currentUserName = ref('');

/* translators: %1$s: user name */
const title = computed(() => isEditing.value
    ? translate('Edit %1$s\'s Role', currentUserName.value)
    : translate('Add Role')
);

const openModal = (user, role, name, editing = false) => {
  selectedUser.value = user;
  selectedRole.value = role;
  currentUserName.value = name;
  modelValue.value = true;
  isEditing.value = editing;
  getUserRoles();
}

defineExpose({
  openModal
});

</script>

<style scoped>
@import "tailwindcss/theme.css" theme(reference inline);
@config "../../../../../tailwind.config.mjs";
.role-form .fct-form-field:last-child {
  @apply mb-0;
}

.w-full {
  width: 100%;
}
</style>
