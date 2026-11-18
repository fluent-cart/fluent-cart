<script setup>
import {ref} from "vue";
import Rest from "@/utils/http/Rest";
import Notify from "@/utils/Notify";
import translate from "@/utils/translator/Translator";

const props = defineProps({
  customer: {}
})

const submitting = ref(false);

const detachUser = () => {
  validationErrors.value = {};
  submitting.value = true;
  Rest.post(`customers/${props.customer.id}/detach-user`, {}).then((response, status) => {
    Notify.success(response.message);
    props.customer.user_id = null;
  }).catch((error) => {
    Notify.error(error.message);
  }).finally(() => {
    submitting.value = false;
  });

}


</script>

<template>

  <fct-button @click="detachUser">
    {{ translate('Detach User') }}
  </fct-button>
</template>

<style scoped>

</style>