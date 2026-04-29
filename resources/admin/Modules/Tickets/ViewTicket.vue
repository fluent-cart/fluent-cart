<script setup>
import {onMounted, ref} from "vue";
import {useRoute} from "vue-router";
import {useSupportService} from "@/mixin/useSupportService";

const supportService = useSupportService();
const route = useRoute();
const ticket = ref({});
const ticketResponses = ref([]);
const loading = ref(false);
onMounted(() => {
  if (route.params.id) {
    fetchTicket(route.params.id);
  }
})
const isSettingsValidated = ref(supportService.isConfigValidated());
const onTicketFetched = (response) => {
  ticketResponses.value = response.responses;
  ticket.value = response.ticket;
  loading.value = false;
}

const fetchTicket = (id) => {
  loading.value = true;
  supportService.getTicket(id, onTicketFetched, () => {
    loading.value = false;
  })
};
</script>

<template>
  <div>{{ ticket }}</div>
  <div>{{ ticketResponses }}</div>
</template>

<style scoped>

</style>