<script setup>
import {ref} from "vue";
import {useSupportService} from "@/mixin/useSupportService";

const supportService = useSupportService();

const showModal = ref(false);
const loading = ref(false);
const form = ref({
  title: '',
  content: '',
  product_id: '1'
});
const isSettingsValidated = ref(supportService.isConfigValidated());
const emit = defineEmits(['onTicketCreated', 'submit'])
const reset = () => {
  showModal.value = false;
  loading.value = false;
  form.value = {
    title: '',
    content: '',
  };
}

const saveTicket = () => {
  if (loading.value) {
    return;
  }

  loading.value = true;
  supportService.createTicket(form.value, () => {
    emit('onTicketCreated');
    reset();
  }, reset)
}
</script>

<template>
  <fct-button @click="showModal = true">{{ $t('New Ticket') }}</fct-button>

  <fct-dialog v-model="showModal" @closed="reset">
    <fct-input v-model="form.title"/>
    <fct-input type="textarea" v-model="form.content"/>
    <fct-button @click="()=>{saveTicket()}">
      Save
    </fct-button>
  </fct-dialog>

</template>

<style scoped>

</style>