<script setup>
import {inject, ref, watch} from "vue";
import Rest from "@/utils/http/Rest";
import translate from "@/utils/translator/Translator";

const props = defineProps({
  mode: String
});

const connection = inject('paymentConnectionTest', null);
const testing = ref(false);
const result = ref(null);

// Bumped on every reset so a response for an older mode or older credentials is dropped.
let run = 0;

const reset = () => {
  run++;
  testing.value = false;
  result.value = null;
};

watch(() => props.mode, reset);

// A disconnect or connect-info refresh replaces the saved credentials without making the form dirty.
watch(() => connection?.value?.saved, reset);

watch(() => connection?.value?.dirty, dirty => {
  if (dirty) {
    reset();
  }
});

const test = () => {
  reset();
  const current = run;
  testing.value = true;
  Rest.post('settings/payment-methods/test-connection', {
    method: connection.value.routeName,
    mode: props.mode
  })
      .then(response => {
        if (current === run) {
          result.value = {type: 'success', message: response.message};
        }
      })
      .catch(error => {
        if (current === run) {
          result.value = {type: 'error', message: error?.data?.message || translate('Connection test failed.')};
        }
      })
      .finally(() => {
        if (current === run) {
          testing.value = false;
        }
      });
};
</script>

<template>
  <div v-if="connection?.canTest && mode" class="mt-4">
    <fct-tooltip :content="translate('Save settings first.')" :disabled="!connection.dirty" placement="top-start">
      <span>
        <fct-button :disabled="connection.dirty" :loading="testing" @click="test">
          {{ translate('Test Connection') }}
        </fct-button>
      </span>
    </fct-tooltip>
    <el-alert
        v-if="result"
        class="mt-3"
        :type="result.type"
        :title="result.message"
        :closable="false"
        show-icon
    />
  </div>
</template>
