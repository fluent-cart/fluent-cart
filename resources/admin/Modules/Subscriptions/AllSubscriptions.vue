<script setup>
import TableWrapper from "@/Bits/Components/TableNew/TableWrapper.vue";
import PageHeading from "@/Bits/Components/Layout/PageHeading.vue";
import useSubscriptionTable from "@/utils/table-new/SubscriptionTable";
import SubscriptionsTableComponent from "@/Modules/Subscriptions/Components/SubscriptionsTable.vue";
import SubscriptionsLoader from "@/Modules/Subscriptions/Components/SubscriptionsLoader.vue";
import SubscriptionsLoaderMobile from "@/Modules/Subscriptions/Components/SubscriptionsLoaderMobile.vue";
import SubscriptionsTableMobile from "@/Modules/Subscriptions/Components/SubscriptionsTableMobile.vue";
import {getCurrentInstance, onMounted, onUnmounted, ref} from "vue";

const subscriptionTable = useSubscriptionTable({
  instance: getCurrentInstance()
});

const isMobileView = ref(false);

const checkMobileView = () => {
  isMobileView.value = window.innerWidth < 768; // You can adjust this breakpoint
};

onMounted(() => {
  checkMobileView(); // Initial check
  window.addEventListener('resize', checkMobileView);
});

onUnmounted(() => {
  window.removeEventListener('resize', checkMobileView);
});
</script>

<template>
  <div class="fct-all-subscriptions-page fct-layout-width">
    <PageHeading :title="$t('Subscriptions')"></PageHeading>

    <div class="fct-all-subscriptions-wrap">
      <TableWrapper :table="subscriptionTable" :classicTabStyle="true" :has-mobile-slot="true">
        <SubscriptionsLoader v-if="subscriptionTable.isLoading()" :subscriptionTable="subscriptionTable" :next-page-count="subscriptionTable.nextPageCount" />
        <div v-else>
          <SubscriptionsTableComponent :subscriptions="subscriptionTable.getTableData()" :columns="subscriptionTable.data.columns"/>
        </div>
        <template #mobile>
          <SubscriptionsLoaderMobile v-if="subscriptionTable.isLoading()" />
          <SubscriptionsTableMobile v-if="!subscriptionTable.isLoading()" :subscriptions="subscriptionTable.getTableData()" :columns="subscriptionTable.data.columns"/>
        </template>
      </TableWrapper>
    </div>
  </div>
</template>
