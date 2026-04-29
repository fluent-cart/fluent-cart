<script setup>
import CustomerTable from "@/utils/table-new/CustomerTable";
import TableWrapper from "@/Bits/Components/TableNew/TableWrapper.vue";
import PageHeading from "@/Bits/Components/Layout/PageHeading.vue";
import {onMounted, onUnmounted, ref} from "vue";
import NewCustomerModal from "@/Modules/Orders/Modals/NewCustomerModal.vue";
import translate from "@/utils/translator/Translator";
import UserCan from "@/Bits/Components/Permission/UserCan.vue";
import CustomersLoader from "@/Modules/Customers/parts/CustomersLoader.vue";
import CustomersTable from "@/Modules/Customers/Components/CustomersTable.vue";
import CustomersTableMobile from "@/Modules/Customers/Components/CustomersTableMobile.vue";
import CustomersLoaderMobile from "@/Modules/Customers/parts/CustomersLoaderMobile.vue";

const customerTable = CustomerTable();
const customerModalInfos = ref({
  showModal: false,
  action: "create",
  title: translate("Create New Customer"),
  customer: {},
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
  <div class="fct-all-customers-page fct-layout-width">

    <UserCan permission="customers/manage">
      <PageHeading :title="translate('Customers')">
        <template #action>
          <el-button type="primary" @click="customerModalInfos.showModal = true">
            {{ translate("Add Customer") }}
          </el-button>
        </template>
      </PageHeading>
    </UserCan>


    <UserCan permission="customers/view">
      <div class="fct-all-customers-wrap">
        <TableWrapper :table="customerTable" :has-mobile-slot="true">
          <CustomersLoader v-if="customerTable.isLoading()" :customerTable="customerTable" :next-page-count="customerTable.nextPageCount" />
          <div v-else>
            <CustomersTable :table="customerTable" />
          </div>
          <template #mobile>
            <CustomersLoaderMobile v-if="customerTable.isLoading()"/>
            <CustomersTableMobile :table="customerTable" />
          </template>
        </TableWrapper>
      </div>
    </UserCan>

    <UserCan permission="customers/manage">
      <NewCustomerModal
          @update:customerModal="
        () => {
          customerModalInfos.showModal = false;
          customerModalInfos.customer = {};
          customerTable.fetch();
        }
      "
          :customerModalInfos="customerModalInfos"
          :customer_id="customerModalInfos.customer_id"
          :customer="customerModalInfos.customer"
          @close-modal="customerModalInfos.showModal = false"
      />
    </UserCan>
  </div>
</template>
