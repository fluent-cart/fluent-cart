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
  <el-button @click="showModal = true">New Ticket</el-button>

  <el-dialog v-model="showModal" @closed="reset">
    <el-input v-model="form.title"/>
    <el-input type="textarea" v-model="form.content"></el-input>
    <el-button @click="()=>{saveTicket()}">
      Save
    </el-button>
  </el-dialog>

</template>

<style scoped>

</style>