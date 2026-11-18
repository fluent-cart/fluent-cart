<template>
  <div class="fct-attr-group-modal">
    <div>
      <fct-input
          ref="titleInputRef"
          type="text"
          v-model="group.title"
          :label="$t('Group Title')"
          required
          :placeholder="$t('Group Title')"
          :class="validationErrors['title'] ? 'is-error' : ''"
          :validation-errors="validationErrors"
          field-key="title"
          @keyup="handleTitleKeyup"
      />

      <div class="fct-attr-group-modal-row">
        <fct-select
            v-model="group.settings.type"
            :label="$t('Attribute Type')"
            style="width: 100%"
            :options="[
              {label: $t('Options'), value: 'options'},
              {label: $t('Color'), value: 'color'},
              {label: $t('Image'), value: 'image'}
            ]"
        />
        <fct-select
            v-model="group.settings.styling"
            :label="$t('Styling')"
            clearable
            style="width: 100%"
            :options="[
              {label: $t('Dropdown'), value: 'dropdown'},
              {label: $t('Button'), value: 'button'}
            ]"
        />
      </div>

      <div class="dialog-footer">
        <fct-button
          :disabled="!group.title || loading"
          :loading="loading"
          @click="createGroup()"
          type="primary"
        >
            {{ $t('Add Group')}}
        </fct-button>
      </div>
    </div>
  </div>
</template>

<script setup>
import {reactive, ref} from "vue";
import Rest from "@/utils/http/Rest";
import {handleSuccess} from "@/Bits/common";
import Notify from "@/utils/Notify";


defineOptions({
  name: 'AddGroupModal'
})

const emit = defineEmits(['whenGroupCreateIsDone'])

const loading = ref(false);

const validationErrors = ref({});

const titleInputRef = ref(null);

const group = reactive({
  title: '',
  settings: { type: 'options', styling: 'button' }
});

// Exposed for the parent to call from el-dialog's @opened event. Focus
// must wait until AFTER the dialog's open transition completes —
// el-dialog traps focus during the transition, so onMounted / nextTick
// focus calls get swallowed.
const focusTitle = () => {
  titleInputRef.value?.focus?.();
};

defineExpose({ focusTitle });

const createGroup = () => {
  loading.value = true;
  validationErrors.value = {}

  Rest.post('options/attr/group/', {
    title: group.title,
    settings: group.settings,
  }).then(response => {
    handleSuccess(response.message);
    emit('whenGroupCreateIsDone', response.data);
  }).catch(errors => {
    if (errors?.status_code == 422 && errors.data) {
      validationErrors.value = errors.data;
    } else {
      Notify.error(errors?.data?.message || errors?.message);
    }
  }).finally(() => {
    loading.value = false;
  });
}

// Keyboard submit shortcut — Enter inside the title input mirrors the
// Add Group button. Inline key check instead of Vue's @keyup.enter
// modifier; the modifier compiles to _withKeys() which fails to resolve
// when el-input forwards keyboard events (Vue runtime error in this
// Element Plus / Vue combination). Disabled-state guard mirrors the
// button so an in-flight request can't be double-submitted.
const handleTitleKeyup = (event) => {
  if (event.key !== 'Enter') return;
  if (loading.value || !group.title) return;
  createGroup();
};
</script>
