<template>
  <SingleCustomerLoader v-if="loading"/>
  <NotFound v-if="notFound.show" :button-text="notFound.buttonText" :message="notFound.message"
            :route="notFound.route"/>
  <div v-if="notFound.show === false">

    <div v-if="!loading" class="fct-single-customer-wrapper fct-layout-width">
      <SaveBar
          :isActive="changes_made > 0 ? 'is-active' : ''"
          @discard="discardChanges"
          @save="update"
          :loading="loading"
      />

      <div class="single-page-header">
        <div class="single-page-header-title-wrap">
          <el-breadcrumb :separator-icon="ArrowRight">
            <el-breadcrumb-item :to="{ name: 'customers' }">{{ translate('Customers') }}</el-breadcrumb-item>
            <el-breadcrumb-item>
              <span v-if="customer">{{ customer.full_name }}</span>
            </el-breadcrumb-item>
            <el-breadcrumb-item>
              {{ translate('Overview') }}
            </el-breadcrumb-item>
          </el-breadcrumb>
        </div>
      </div><!-- .single-page-header -->

      <div class="single-page-body" v-if="customer.id">
        <el-row :gutter="30">
          <el-col :lg="17" :xs="24">
            <CardContainer class="overflow-hidden fct-customer-orders-items">
              <CardHeader :title="translate('Orders') + ' ('+translateNumber(customerOrders.total)+')'" title_size="small">
                <template #action>
                  <el-input
                      v-model="search"
                      @keyup.enter="getCustomerOrders()"
                      :placeholder="translate('Type and hit enter...')"
                      clearable
                      @clear="getCustomerOrders()"
                      :class="`w-[210px] ${!showSearch ? 'search-input-hide-on-mobile' : ''}`"
                  />

                  <IconButton
                      tag="button"
                      size="small"
                      @click="showSearch = !showSearch"
                      class="icon-button-show-on-mobile ml-2"
                  >
                    <DynamicIcon v-if="showSearch" name="Cross"/>
                    <DynamicIcon v-else name="Search"/>
                  </IconButton>
                </template>
              </CardHeader>
              <CardBody class="px-0 pb-0">
                <CustomerOrdersLoader v-if="fetchingOrders" :columns="['items', 'order_items', 'total', 'type', 'status']" />

                <template v-else>
                  <OrdersTable
                      class="hide-on-mobile"
                      @fetch="getCustomerOrders"
                      :orders="customerOrders.data"
                      :columns="['items', 'order_items', 'total', 'type', 'status']"
                  />

                  <OrdersTableMobile
                      class="show-on-mobile"
                      :orders="customerOrders.data"
                      :columns="['items', 'order_items', 'total', 'type', 'status']"
                  />
                </template>

                <Pagination
                    v-if="paginate.total > 10"
                    @fetch="getCustomerOrders"
                    :hide_on_single="false"
                    :pagination="paginate"
                />
              </CardBody>
            </CardContainer>

            <CardContainer v-if="customer.subscriptions" class="overflow-hidden">
              <CardHeader :title="translate('Subscriptions') + ' ('+customer.subscriptions.length+')'"
                          title_size="small"></CardHeader>
              <CardBody class="px-0 pb-0">
                <subscriptions-table class="hide-on-mobile" :subscriptions="customer.subscriptions"
                                     :columns="['next_billing_date', 'collection_method', 'bills_count', 'order_id', 'payment_method', 'valid_till']"/>

                <SubscriptionsTableMobile
                    class="show-on-mobile"
                    :subscriptions="customer.subscriptions"
                    :columns="['next_billing_date', 'collection_method', 'bills_count', 'order_id', 'payment_method', 'valid_till']"
                />
              </CardBody>
            </CardContainer>

            <CardContainer v-if="customer.licenses" class="overflow-hidden">
              <CardHeader :title="translate('Licenses') + ' ('+translateNumber(customer.licenses.length)+')'"
                          title_size="small"></CardHeader>
              <CardBody class="px-0 pb-0 fct-single-customer-license-table">
                <license-table :columns="['product', 'order_id']" :licenses="customer.licenses"/>
              </CardBody>
            </CardContainer>
          </el-col>
          <el-col :lg="7" :xs="24">
            <div class="fct-admin-sidebar">
              <CustomerInformation
                  :customer="customer"
                  :customer_id="customer_id"
                  @fetch="fetch"
              />


              <Labels
                  :bind-to-id="customer.id"
                  bind-to-type="Customer"
                  :selectedLabels="selectedLabels"
                  :shouldEnableEditing="true"
              />

              <div v-if="customer">
                <DynamicTemplates
                    filter="customer"
                    :widgetsQuery="{
                  customer_id: customer.id
                }" :data="{customer}"/>
              </div>
            </div><!-- .fct-admin-sidebar -->
          </el-col>
        </el-row>
      </div>
    </div><!-- .fct-single-customer-wrapper -->
  </div>
</template>

<script setup>
import NotFound from "@/Pages/NotFound.vue";
import translateNumber from "@/utils/translator/Translator";
import Pagination from "@/Bits/Components/Pagination.vue";
import CustomerOrdersLoader from "@/Modules/Customers/CustomerOrdersLoader.vue";
import translate from "@/utils/translator/Translator";
</script>

<script type="text/babel">
import OrdersTable from "@/Modules/Orders/Components/OrdersTable.vue";
import OrdersTableMobile from "@/Modules/Orders/Components/OrdersTableMobile.vue";
import LicenseTable from "@/../licensing/components/_LicenseTable.vue";
import Empty from "@/Bits/Components/Table/Empty.vue";
import Badge from "@/Bits/Components/Badge.vue";
import SaveBar from "@/Bits/Components/SaveBar.vue";
import CustomerInformation from './parts/CustomerInformation.vue';
import Labels from "@/Modules/Parts/Labels/Label.vue";
import DynamicTemplates from "@/Bits/Components/DynamicTemplates/DynamicTemplates.vue";
import {ArrowDown, ArrowRight} from '@element-plus/icons-vue';
import {Container as CardContainer, Header as CardHeader, Body as CardBody} from '@/Bits/Components/Card/Card.js';
import Arr from "@/utils/support/Arr";
import Notify from "@/utils/Notify";
import Rest from "@/utils/http/Rest";
import IconButton from "@/Bits/Components/Buttons/IconButton.vue";
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";
import SubscriptionsTable from "@/Modules/Subscriptions/Components/SubscriptionsTable.vue";
import SubscriptionsTableMobile from "@/Modules/Subscriptions/Components/SubscriptionsTableMobile.vue";
import SingleCustomerLoader from "@/Modules/Customers/parts/SingleCustomerLoader.vue";
import translate from "@/utils/translator/Translator";

export default {
  name: 'SingleCustomer',
  props: ['customer_id'],
  components: {
    OrdersTable,
    IconButton,
    Empty,
    DynamicIcon,
    Badge,
    SaveBar,
    CustomerInformation,
    Labels,
    DynamicTemplates,
    ArrowDown,
    ArrowRight,
    CardContainer,
    CardHeader,
    CardBody,
    LicenseTable,
    OrdersTableMobile,
    SubscriptionsTable,
    SingleCustomerLoader,
    SubscriptionsTableMobile
  },
  data() {
    return {
      changes_made: 0,
      notFound: {
        show: false,
        message: '',
        buttonText: '',
        route: ''
      },
      loading: false,
      customer: '',
      selection: false,
      selectedOrders: [],
      editableOrderStatues: this.appVars.editable_order_statues,
      selectedLabels: [],
      licenses: [],
      subscriptions: [],
      customerOrders: [],
      paginate: {
        current_page: 1,
        per_page: 10,
        last_page: 1,
        total: 0,
        from: 1,
        to: 10,
      },
      fetchingOrders: false,
      search: '',
      showSearch: false
    }
  },
  watch: {
    customer_id: {
      handler(newVal, oldVal) {
        this.fetch();
      },
    },
  },
  computed: {},
  methods: {
    getProductName(product) {
      if (!product?.product?.post_title) {
        return "n/a";
      }
      return product?.product?.post_title;
    },
    getProductVariationName(product) {
      if (!product?.product_variant?.variation_title) {
        return "n/a";
      }
      return product?.product_variant?.variation_title;
    },
    onChangeLabel(selectedLabels) {
      this.selectedLabels = selectedLabels;
      this.changes_made++;
    },
    update() {
      this.loading = true;
      Rest.put('customers/' + this.customer_id + '/additional-info', {
        labels: this.selectedLabels,
      })
          .then((response) => {
            Notify.success(response);
          })
          .catch((errors) => {
            if (errors.status_code == '422') {
              Notify.validationErrors(errors);
            } else {
              Notify.error(errors.data?.message);
            }
          })
          .finally(() => {
            this.loading = false;
            this.changes_made = 0;
          });
    },
    discardChanges() {
      return window.location.reload();
    },
    fetch() {

      this.notFound.show = false;
      this.loading = true;
      this.selectedOrders = [];
      Rest.get('customers/' + this.customer_id, {
        with: ['shipping_address', 'billing_address', 'labels', 'subscriptions']
      })
          .then(response => {
            this.changeTitle(
                /* translators: %s is the customer name */
                translate('Customer: %s', response.customer.full_name)
            );
            this.customer = response.customer;
            this.selectedLabels = response.customer.selected_labels;
            this.licenses = this.customer.licenses;
            this.subscriptions = this.customer.subscriptions;
          })
          .catch((errors) => {
            if (errors.code === 'fluent_cart_entity_not_found') {
              this.notFound.show = true;
              this.notFound.buttonText = Arr.get(errors, 'data.buttonText');
              this.notFound.message = Arr.get(errors, 'data.message');
              this.notFound.route = Arr.get(errors, 'data.route');
            } else {
              if (errors.status_code == '422') {
                Notify.validationErrors(errors);
              } else {
                Notify.error(errors.data?.message);
              }
            }
          })
          .finally(() => {
            this.loading = false;
          });
    },
    getCustomerOrders() {
      this.fetchingOrders = true;
      Rest.get('customers/' + this.customer_id+'/orders', {
        per_page: this.paginate.per_page,
        page: this.paginate.current_page,
        with: [
          'order_items'
        ],
        search: this.search,
        order_by: 'id',
        order_type: 'DESC',
      })
          .then(response => {
            this.customerOrders = response.orders;
            this.paginate.total = response.orders.total;
            this.paginate.per_page = response.orders.per_page;
            this.paginate.current_page = response.orders.current_page;
            this.paginate.last_page = response.orders.last_page;
            this.paginate.from = response.orders.from;
            this.paginate.to = response.orders.to;

          })
          .catch((errors) => {
            if (errors.status_code == '422') {
              Notify.validationErrors(errors);
            } else {
              Notify.error(errors.data?.message);
            }
          })
          .finally(() => {
            this.fetchingOrders = false;
          });
    },
    onSelection(orders) {
      this.selection = !!orders.length;

      this.selectedOrders = orders;

      this.selectionCount = orders.length;
    },
    exportOrders() {
      location.href = window.ajaxurl + '?' + jQuery.param({
        action: 'fluent_cart_admin_ajax',
        route: 'export_orders',
        customer_id: this.customer_id,
        search: this.search,
        format: 'csv'
      });
    },
  },
  mounted() {
    this.fetch();
    this.getCustomerOrders();
  }
}
</script>
