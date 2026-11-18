<script setup>
import {onMounted, ref} from "vue";
import Rest from "@/utils/http/Rest";
import Arr from "@/utils/support/Arr";
import Notify from "@/utils/Notify";
import translate from "@/utils/translator/Translator";
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";

const props = defineProps({
  customer: {}
})

const showModal = ref(false);
const users = ref([]);
const selectedUser = ref(null);
const validationErrors = ref({});
const submitting = ref(false);

const attachUser = () => {
  validationErrors.value = {};
  submitting.value = true;
  Rest.post(`customers/${props.customer.id}/attachable-user`, {
    user_id: selectedUser.value,
  }).then((response, status) => {
    showModal.value = false;
    Notify.success(response.message);
    props.customer.user_id = selectedUser.value;
  }).catch((error) => {
    if (error.message) {
      return Notify.error(error.message);
    } else {
      validationErrors.value = error;
    }
  }).finally(() => {
    submitting.value = false;
  });

}


const closeModal = () => {
  selectedUser.value = null;
  validationErrors.value = {};
  showModal.value = false;
}
const fetchUsers = () => {
  Rest.get('customers/attachable-user').then(response => {
    users.value = Arr.get(response, 'users');
  })
};

onMounted(() => {
  fetchUsers();
})
</script>

<template>
  <el-link v-if="false" @click="showModal = true;" class="flex items-center gap-1 underline cursor-pointer">
    {{ translate('Attach User') }}
    <DynamicIcon class="w-4" name="Link" />
  </el-link>

  <fct-dialog v-model="showModal" :title="translate('Attach User')" @closed="closeModal">

    <fct-select
        id="user_id"
        v-model="selectedUser"
        filterable
        :label="$t('Select User')"
        :placeholder="$t('Select a user')"
        class="w-full"
        :options="users.map((user) => ({label: user.display_name, value: user.ID, email: user.user_email}))"
        :validation-errors="validationErrors"
        field-key="user_id"
    >
      <template #option="{option}">
        <div class="select-use-option">
          <span>{{ option.label }}</span>
          <span>
              {{ option.email }}
            </span>
        </div>
      </template>
    </fct-select>

    <template #footer>
      <span class="dialog-footer">
        <fct-button @click="closeModal">
          {{ translate('Cancel') }}
        </fct-button>
        <fct-button
            type="primary"
            @click="attachUser"
            :loading="submitting"
            :disabled="submitting"
        >
          {{ translate('Attach User') }}
        </fct-button>
      </span>
    </template>

  </fct-dialog>
</template>

<style scoped>

</style>