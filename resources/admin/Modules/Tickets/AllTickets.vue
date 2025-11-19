<script setup>
import * as Card from "@/Bits/Components/Card/Card.js";
import {getCurrentInstance, onMounted, ref} from "vue";
import Empty from "@/Bits/Components/Table/Empty.vue";
import Pagination from "@/Bits/Components/Pagination.vue";
import {useRouter} from "vue-router";
import AddTicketModal from "@/Modules/Tickets/AddTicketModal.vue";
import {useSupportService} from "@/mixin/useSupportService.js";


const supportService = useSupportService();
const self = getCurrentInstance().ctx;
const router = useRouter();

const loading = ref(true);
const tickets = ref([]);
const paginate = ref({
  per_page: 10,
  current_page: 1,
  total: 0,
  last_page: 1,
  from: 1,
  to: 10,
});

const skeletonRowCount = ref(10);
const onCurrentPageChanged = ({page, currentPageCount}) => {
  skeletonRowCount.value = currentPageCount * 2;
  paginate.value.current_page = page;
  fetchTickets();
};
const onPerPageChanged = (perPage) => {
  paginate.value.current_page = 1;
  skeletonRowCount.value = 20;
  paginate.value.per_page = perPage;
  this.fetchCustomers()
};
const onTicketFetched = (response) => {
  tickets.value = response.tickets.data;
  paginate.value.total = response.tickets.total;
  paginate.value.last_page = response.tickets.last_page;
  paginate.value.from = response.tickets.from;
  paginate.value.to = response.tickets.to;
  loading.value = false;
}
const isSettingsValidated = ref(supportService.isConfigValidated());
const fetchTickets = () => {
  loading.value = true;
  supportService.getTickets(paginate.value, onTicketFetched, () => {
    loading.value = false;
  })
};


onMounted(() => {
  fetchTickets();
})
</script>

<template>
  <Card.Container class="overflow-hidden">
    <Card.Header :title="$t('All Tickets')" border_bottom></Card.Header>
    <Card.Body class="px-0 pb-0">
      <TableFilterWrapper>
        <template v-slot:btn>
          <div class="fct-btn-group">
            <AddTicketModal @onTicketCreated="fetchTickets"/>
          </div>
        </template>
      </TableFilterWrapper>

      <el-skeleton class="px-5 pb-5" :loading="loading" :rows="skeletonRowCount" animated/>
      <div v-if="!loading" class="fct-all-customer-tables">
        <el-table class="w-full" :data="tickets">

          <el-table-column min-width="250" :label="$t('ID')">
            <template #default="scope">
              <div class="truncate">
                {{ scope.row.id }}
              </div>
            </template>
          </el-table-column>

          <el-table-column min-width="250" :label="$t('Title')">
            <template #default="scope">
              <div class="truncate">
                {{ scope.row.title }}
              </div>
            </template>
          </el-table-column>

          <el-table-column min-width="250" :label="$t('Status')">
            <template #default="scope">
              <div class="truncate">
                {{ scope.row.status }}
              </div>
            </template>
          </el-table-column>

          <el-table-column min-width="250" :label="$t('Content')">
            <template #default="scope">
              <div class="truncate" v-html="scope.row.content">

              </div>
            </template>
          </el-table-column>

          <el-table-column min-width="250" :label="$t('Agent')">
            <template #default="scope">
              <div class="truncate">
                {{ scope.row.agent === null ? '----' : scope.row.agent.full_name }}
              </div>
            </template>
          </el-table-column>

          <el-table-column min-width="250" :label="$t('Action')">
            <template #default="scope">
              <div class="">
                <el-button @click="evt => {
                  router.push({name: 'view_tickets', params: {id: scope.row.id}})

                }">
                  View
                </el-button>
              </div>
            </template>
          </el-table-column>


          <template #empty>
            <Empty icon="Empty/ListView"
                   :text="$t('We could not find any customers matching your search.')"/>
          </template>
        </el-table>
      </div>

      <pagination
          v-if="tickets.length > 0"
          :hide_on_single="false"
          :pagination="paginate"
          @fetch="fetchTickets"
          @current-page-changed="onCurrentPageChanged"
          @on-per-page-changed="onPerPageChanged"
      />
    </Card.Body>
  </Card.Container>
</template>

<style scoped>

</style>
