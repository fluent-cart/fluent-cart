<template>

  <NotFound v-if="notFound.show" :button-text="notFound.buttonText" :message="notFound.message"
            :route="notFound.route"/>
  <div class="fct-single-subscription-page" v-if="notFound.show === false">

    <SingleSubscriptionLoader v-if="loading"/>

    <div v-if="!loading && subscription.id" class="fct-single-order-wrapper fct-layout-width">
      <div class="single-page-header flex items-start justify-between">
        <div>
          <div class="single-page-header-title-wrap">
            <el-breadcrumb :separator-icon="ArrowRight">
              <el-breadcrumb-item :to="{ name: 'all_subscriptions' }">
                {{ $t("Subscriptions") }}
              </el-breadcrumb-item>
              <el-breadcrumb-item>#{{ subscription_id }}</el-breadcrumb-item>
            </el-breadcrumb>
            <div class="single-page-header-status-wrap">
              <Badge :status="subscriptionStatus"/>
            </div>
          </div>
          <div class="pt-2 text-xs text-system-mid dark:text-gray-300">
            <ConvertedTime :date-time="subscription.created_at" with-time/>
          </div>
        </div>
        <div class="fct-btn-group sm">
        </div>
      </div><!-- .single-page-header -->

      <div class="single-page-body">
        <div class="fct-single-subscription">
          <div class="fct-single-subscription-main">
            <SubscriptionDetails 
              :subscription="subscription" 
              :orderId="parentOrder?.id"
              @fetchOrder="$emit('fetchSubscription')"
              @reload="reload()"
            />

            <CardContainer class="overflow-hidden">
              <CardHeader class="pb-4" :title="$t('Related Orders') + ' (' + subscription.related_orders.length +')'" title_size="small"/>
              <orders-table class="hide-on-mobile" :orders="subscription.related_orders" :columns="['type']"/>
              <OrdersTableMobile class="show-on-mobile fct-single-subscription-orders-mobile" :orders="subscription.related_orders" :columns="['type']"/>
            </CardContainer>

            <CardContainer v-if="subscription.licenses" class="overflow-hidden">
              <CardHeader class="pb-4" :title="$t('Related Licenses')" title_size="small"/>
              <license-table :licenses="subscription.licenses" :columns="[]"/>
            </CardContainer>
          </div>
          <div class="fct-single-subscription-aside">
            <div class="fct-admin-sidebar">

              <CardContainer v-if="parentOrderLoading">
                <CardHeader
                  :title="$t('Subscription Plan')"
                  title_size="small"
                  border_bottom
                >
                </CardHeader>
                <CardBody>
                  <el-skeleton :rows="3" :animated="true"/>
                </CardBody>
              </CardContainer>
            </div>
            <div class="fct-admin-sidebar">
              <OrderCustomerInformation
                  v-if="subscription.customer"
                  :order="subscription"
                  :shouldEnableEditing="true"
              />


<!--              <CardContainer>-->
<!--                <CardHeader-->
<!--                    :title="$t('Customer Details')"-->
<!--                    title_size="small"-->
<!--                    border_bottom-->
<!--                >-->
<!--                  <template #action>-->
<!--                    <el-dropdown-->
<!--                        trigger="click"-->
<!--                        class="fct-more-option-wrap"-->
<!--                        popper-class="fct-dropdown"-->
<!--                        @command="handleCommand"-->
<!--                    >-->
<!--                      <span class="more-btn">-->
<!--                        <DynamicIcon name="More"/>-->
<!--                      </span>-->
<!--                      <template #dropdown>-->
<!--                        <el-dropdown-menu>-->
<!--                          <el-dropdown-item command="change_customer">-->
<!--                            {{ translate("Change Customer") }}-->
<!--                          </el-dropdown-item>-->
<!--                        </el-dropdown-menu>-->
<!--                      </template>-->
<!--                    </el-dropdown>-->
<!--                  </template>-->
<!--                </CardHeader>-->
<!--                <CardBody>-->
<!--                  <template v-if="subscription.customer">-->
<!--                    <div class="fct-admin-sidebar-item">-->
<!--                      <div class="fct-profile-card">-->
<!--                        <div class="fct-profile-image">-->
<!--                          <img :src="subscription.customer?.photo"-->
<!--                               :alt="subscription.customer?.full_name">-->
<!--                        </div>-->
<!--                        <div class="fct-profile-details">-->
<!--                          <div class="title">-->
<!--                            <router-link-->
<!--                                :to="{ name: 'view_customer', params: { customer_id: subscription.customer?.id }}">-->
<!--                              {{ subscription.customer.full_name }}-->
<!--                            </router-link>-->
<!--                          </div>-->
<!--                          <span class="text"> {{ subscription.customer?.purchase_count }} Orders</span>-->
<!--                          <CustomerPurchaseValue :value="subscription.customer?.purchase_value"/>-->
<!--                        </div>-->
<!--                      </div>-->
<!--                      <div class="user-info pt-2">-->
<!--                        WP User: <a :href="subscription.customer.user_link" target="_blank" class="inline-flex items-center gap-1 dark:text-gray-100 text-primary-500 focus:shadow-none hover:!underline">-->
<!--                        #{{ subscription.customer.user_id }}-->
<!--                        <DynamicIcon name="Redirect" class="w-2.5 h-2.5 text-primary-500 dark:text-gray-200" />-->
<!--                      </a>-->
<!--                      </div>-->
<!--                    </div>&lt;!&ndash; .fct-admin-sidebar-item &ndash;&gt;-->
<!--                    <div class="fct-admin-sidebar-item mt-5">-->
<!--                      <h4 class="sidebar-title">{{ $t('Contact Information') }}</h4>-->
<!--                      <ul class="fct-contact-info-list">-->
<!--                        <li>-->
<!--                          <div class="flex items-center justify-between gap-2">-->
<!--                            <a class="text" :href="'mailto:' + subscription.customer.email">-->
<!--                              {{ subscription.customer.email }}-->
<!--                            </a>-->
<!--                            <CopyToClipboard :text="subscription.customer.email"-->
<!--                                             showMode="basic_copy_btn"-->
<!--                                             tooltipText="Copy email"/>-->
<!--                          </div>-->
<!--                        </li>-->
<!--                      </ul>-->
<!--                    </div>&lt;!&ndash; .fct-admin-sidebar-item &ndash;&gt;-->
<!--                  </template>-->
<!--                </CardBody>-->

<!--                <el-dialog v-model="changeCustomer" :title="translate('Change Customer')">-->
<!--                  <ChangeOrderCustomer :orderId="parentOrder.id" @close="changeCustomer = false" />-->
<!--                </el-dialog>-->
<!--              </CardContainer>-->

              <Labels
                  :bind-to-id="subscription.id"
                  bind-to-type="Subscription"
                  :selectedLabels="selectedLabels"
                  :shouldEnableEditing="true"
                  @update:update-label="()=>{
                      //onChangeLabel
                    }"
              />

            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script type="text/babel">
import {ArrowRight} from "@element-plus/icons-vue";
import {markRaw} from "vue";
import {Container as CardContainer, Body as CardBody, Header as CardHeader} from "@/Bits/Components/Card/Card.js";
import CopyToClipboard from "@/Bits/Components/CopyToClipboard.vue";
import Empty from "@/Bits/Components/Table/Empty.vue";
import OrdersTable from "@/Modules/Orders/Components/OrdersTable.vue";
import OrdersTableMobile from "@/Modules/Orders/Components/OrdersTableMobile.vue";
import LicenseTable from "../../../licensing/components/_LicenseTable.vue";
import Labels from "@/Modules/Parts/Labels/Label.vue";
import Str from "../../utils/support/Str";
import SubscriptionDetails from "@/Modules/Subscriptions/Components/SubscriptionDetails.vue";
import SingleSubscriptionLoader from "@/Modules/Subscriptions/Components/SingleSubscriptionLoader.vue";
import NotFound from "@/Pages/NotFound.vue";
import Arr from "@/utils/support/Arr";
import Badge from "@/Bits/Components/Badge.vue";
import ConvertedTime from "@/Bits/Components/ConvertedTime.vue";
import {getCardBrand, handleError} from "@/Bits/common";
import translate from "../../utils/translator/Translator";
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";
import ChangeOrderCustomer from "@/Modules/Orders/Components/ChangeOrderCustomer.vue";
import CustomerPurchaseValue from "@/Modules/Customers/parts/CustomerPurchaseValue.vue";
import OrderCustomerInformation from "@/Modules/Orders/OrderCustomerInformation.vue";
import Notify from "@/utils/Notify";
export default {
  name: 'SingleSubscription',
  props: ['subscription_id'],
  data() {
    return {
      notFound: {
        show: false,
        message: '',
        buttonText: '',
        route: ''
      },
      subscription: {},
      loading: false,
      parentOrderLoading: true,
      error: null,
      ArrowRight: markRaw(ArrowRight),
      parentOrder: null,
      selectedLabels: [],
      changeCustomer: false
    }
  },
  watch: {
    subscription_id: {
      handler(newVal, oldVal) {
        this.fetchSubscription();
      },
    },
  },
  computed: {
    Str() {
      return Str
    },
    subscriptionStatus() {
      return this.subscription?.status;
    }
  },
  components: {
    ChangeOrderCustomer,
    DynamicIcon,
    ConvertedTime,
    Badge,
    NotFound,
    SingleSubscriptionLoader,
    SubscriptionDetails,
    Labels,
    LicenseTable,
    Empty,
    CopyToClipboard,
    CardContainer,
    CardBody,
    CardHeader,
    OrdersTable,
    CustomerPurchaseValue,
    OrderCustomerInformation,
    OrdersTableMobile
  },
  methods: {
    translate,
    getCardBrand,
    fetchSubscription() {
      this.notFound.show = false;
      this.loading = true;
      this.$get('subscriptions/' + this.subscription_id)
          .then((response) => {
            this.subscription = response.subscription;
            this.selectedLabels = response.selected_labels;
            this.getParentOrder();
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
    getParentOrder() {
      this.$get('orders/' + this.subscription?.parent_order_id)
          .then((response) => {
            this.parentOrder = response.order;
          })
          .finally(() => {
            this.parentOrderLoading = false;
          });
    },
    reload() {
      // reload the window
      window.location.reload();
    },
    handleCommand(command) {
      if (command === 'change_customer') {
        this.changeCustomer = true;

      }

    }
  },
  mounted() {
    this.fetchSubscription();
  },
}
</script>
