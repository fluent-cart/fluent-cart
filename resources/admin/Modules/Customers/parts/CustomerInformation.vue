<template>
  <div>
    <Card.Container>
      <Card.Header
          :title="$t('Customer Information')"
          title_size="small"
          border_bottom
      >
        <template #action>
          <fct-dropdown
              trigger="click"
              class="fct-more-option-wrap"
              :items="[
                {command: 'edit_customer_information', label: $t('Edit customer information')},
                {command: 'manage_shipping_address', label: $t('Manage shipping address')},
                {command: 'manage_billing_address', label: $t('Manage billing address')},
                {command: 'recalculate_ltv', label: $t('Recalculate lifetime value'), disabled: recalculatingLtv}
              ]"
              @command="handleCustomerBulkCommand"
          >
            <span class="more-btn"><DynamicIcon name="More"/></span>
          </fct-dropdown>
        </template>
      </Card.Header>
      <Card.Body>
        <div class="fct-admin-sidebar-item">
          <div class="fct-profile-card">
            <div class="fct-profile-image">
              <UserAvatar :url="customer?.photo" :name="customerDisplayName"/>
            </div>
            <div class="fct-profile-details">
              <div class="title">
                {{ customerDisplayName }}
              </div>
              <span class="text">
                <span v-if="customer.purchase_count > 0">
                  {{ translateNumber(customer.purchase_count) }}
                </span>
                {{
                  $n(
                      "Order",
                      "Orders",
                      customer.purchase_count ?? 0,
                      "No Order"
                  )
                }}
              </span>
              <!-- <CustomerPurchaseValue :value="customer?.purchase_value"/>-->
              <span class="text">
                  {{formatNumber(customer?.ltv, true)}} {{ translate('Lifetime Value') }}
              </span>
            </div>
          </div>
        </div>
        <!-- .fct-admin-sidebar-item -->

        <!-- .fct-admin-sidebar-item wp-user-manage-->
        <div>
          <div
              v-if="customer.user_id && customer.user_id > 0"
              class="flex flex-wrap items-center gap-x-2 gap-y-1 mt-1.5"
          >
            <div class="user-info whitespace-nowrap">
              WP User: <a :href="customer.user_link" target="_blank" class="inline-flex items-center gap-1 dark:text-gray-100 text-primary-500 focus:shadow-none hover:!underline">
              #{{ translateNumber(customer.user_id) }}
              <DynamicIcon name="Redirect" class="w-2.5 h-2.5 text-primary-500 dark:text-gray-200" />
            </a>
            </div>

            <template v-if="customer.unverified_email">
              <el-tag type="warning" size="small" round :title="translate('The account changed its email without an inbox check.')">
                {{ translate('Email unverified') }}
              </el-tag>

              <UserCan permission="customers/manage">
                <fct-popover
                    confirm
                    :width="300"
                    :confirm-button-text="translate('Mark verified')"
                    :cancel-button-text="translate('Cancel')"
                    :title="translate('Customer_Confirmed_Person', customer.unverified_email)"
                    @confirm="markEmailVerified"
                >
                  <template #reference>
                    <fct-button link type="primary" size="small" :loading="verifyingEmail">
                      {{ translate('Mark verified') }}
                    </fct-button>
                  </template>
                </fct-popover>
              </UserCan>
            </template>

            <fct-popover
                v-if="false"
                confirm
                :width="235"
                :confirm-button-text="translate('Yes')"
                :cancel-button-text="translate('No, Thanks')"
                icon-color="red"
                :title="translate('Are you sure to detach user?')"
                @confirm="handleDetachUser"
            >
              <template #reference>
                <fct-button
                    class="flex items-center gap-1 underline cursor-pointer"
                    v-loading="detaching"
                    :title="translate('Detach User')"
                    size="small"
                    :disabled="detaching"
                >{{translate('Detach User')}}
                  <DynamicIcon class="w-4" name="Unlink"
                  />
                </fct-button>
              </template>
            </fct-popover>
          </div>

          <div v-else>
            <UserAttchModal :customer="customer"/>
          </div>
        </div>
        <!-- .fct-admin-sidebar-item wp-user-manage-->

        <div class="fct-admin-sidebar-item mt-5">
          <h3 class="sidebar-title">{{ $t("Contact Information") }}</h3>
          <ul class="fct-contact-info-list">
            <li>
              <div class="flex items-center justify-between gap-2">
                <a class="text" :href="'mailto:' + customer?.email">
                  {{ customer?.email }}
                </a>
                <CopyToClipboard
                    :text="customer.email"
                    showMode="basic_copy_btn"
                    tooltipText="Copy email"
                />
              </div>
            </li>
          </ul>
        </div>
        <!-- .fct-admin-sidebar-item -->

        <div class="fct-admin-sidebar-item mt-5">
          <h3 class="sidebar-title">
            {{ $t("Shipping Address") }}
            <el-tag type="info" round v-if="hasPrimaryShipping != -1">
              {{ $t("Default") }}
            </el-tag>
          </h3>

          <template v-for="(address, id) in shippingAddress">
            <div class="fct-address-copy-wrap" v-if="address.is_primary === '1'">
              <span class="text">
                <strong v-if="address.name" class="pe-1">{{
                    address.name
                  }}</strong>
                <span
                    v-html="getOrderAddress(address, 'shipping', true)"
                ></span>
                <span v-if="address.phone" class="mt-3">
                   <strong class="pe-1">{{ translate("Phone") }}:</strong>
                  <a :href="'tel:' + address.phone" class="text-system-mid dark:text-gray-50 hover:underline">
                     {{ address.phone }}
                   </a>
                </span>

              </span>
              <CopyToClipboard
                  :text="getOrderAddress(address)"
                  showMode="basic_copy_btn"
                  tooltipText="Copy address"
              />
            </div>
            <!-- .fct-address-copy-wrap -->
          </template>

          <p class="no-address-text" v-if="hasPrimaryShipping == -1">
            {{ $t("No default address found.") }}
          </p>
        </div>

        <div class="fct-admin-sidebar-item mt-5">
          <h3 class="sidebar-title">
            {{ $t("Billing Address") }}
            <el-tag type="info" round v-if="hasPrimaryBilling != -1">
              {{ $t("Default") }}
            </el-tag>
          </h3>

          <template v-for="(address, id) in billingAddress">
            <div class="fct-address-copy-wrap" v-if="address.is_primary === '1'">
              <span class="text">
                <strong v-if="address.name" class="pe-1">{{
                    address.name
                  }}</strong>
                <span v-html="getOrderAddress(address, 'billing', true)"></span>

                <span v-if="address.phone" class="mt-3">
                   <strong class="pe-1">{{ translate("Phone") }}:</strong>
                  <a :href="'tel:' + address.phone" class="text-system-mid dark:text-gray-50 hover:underline">
                     {{ address.phone }}
                   </a>
                </span>
              </span>
              <CopyToClipboard
                  :text="getOrderAddress(address)"
                  showMode="basic_copy_btn"
                  :tooltipText="$t('Copy address')"
              />
            </div>
            <!-- .fct-address-copy-wrap -->
          </template>

          <p class="no-address-text" v-if="hasPrimaryBilling === -1">
            {{ $t("No default address found.") }}
          </p>
        </div>
        <!-- .fct-admin-sidebar-item -->
      </Card.Body>
    </Card.Container>

    <!-- edit customer modal -->
    <NewCustomerModal
        v-if="customerModalInfos.showModal"
        @update:customer-modal="fetch"
        :customerModalInfos="customerModalInfos"
        :customer_id="customerModalInfos.customer_id"
        :customer="customerModalInfos.customer"
        @close-modal="customerModalInfos.showModal = false"
    />

    <!-- manage address modal -->
    <ManageAddressModal
        :modalAction="modalAction"
        @refresh-address="refresh"
        @update:show-address-modal="(val) => (showAddressModal = val)"
        :showAddressModal="showAddressModal"
        :customer_id="customer_id"
        :showBusinessDetailsSection="true"
        @add-new-address="addNewAddress"
    />

    <!-- add address modal -->
    <fct-dialog
        :show-close="true"
        v-model="addOrEditAddress"
        :append-to-body="true"
        :title="getTitle()"
        @close="() => {
          closingModal = true;
        }"
    >
      <div class="fct-customer-address-wrap">
        <fct-button
            text
            @click="
            () => {
              showAddressModal = true;
              addOrEditAddress = false;
            }
          "
        >
          <el-icon class="fct-address-back-icon">
            <Back/>
          </el-icon>
          {{ $t("Manage address") }}
        </fct-button>
        <AddOrEditAddressModal
            @update:refresh-address="refresh"
            :customer_id="customer_id"
            :modalAction="modalAction"
            :address="address"
            :showAddressModal="addOrEditAddress"
            :showSetAsAlsoCheckbox="shouldShowSetAsAlsoCheckbox"
            :showBusinessDetailsSection="true"
            @close-modal="addOrEditAddress = false"
            :closing_modal="closingModal"
        />
      </div>
    </fct-dialog>
  </div>
</template>

<script setup>
import * as Card from "@/Bits/Components/Card/Card.js";
import UserAvatar from "@/Bits/Components/UserAvatar.vue";
import {translateNumber} from "@/utils/translator/Translator";
import {formatNumber} from "@/Bits/productService";
import translate from "@/utils/translator/Translator";
</script>

<script>
import {
  Back,
} from "@element-plus/icons-vue";
import CustomerPurchaseValue from "./CustomerPurchaseValue.vue";
import NewCustomerModal from "../../Orders/Modals/NewCustomerModal.vue";
import ManageAddressModal from "../Modals/ManageCustomerAddressModal.vue";
import AddOrEditAddressModal from "../Modals/AddOrEditAddressModal.vue";
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";
import CopyToClipboard from "@/Bits/Components/CopyToClipboard.vue";
import UserAttchModal from "@/Modules/Customers/parts/UserAttchModal.vue";
import UserCan from "@/Bits/Components/Permission/UserCan.vue";


export default {
  name: "CustomerInformations",
  components: {
    CustomerPurchaseValue,
    NewCustomerModal,
    ManageAddressModal,
    AddOrEditAddressModal,
    DynamicIcon,
    CopyToClipboard,
    UserAttchModal,
    UserCan,
    Back
  },
  props: ["customer", "customer_id"],
  emits: ["fetch", "emailVerified"],
  data() {
    this.localizedData = window.fluentCartAdminApp;
    return {
      addOrEditAddress: false,
      shippingAddress: [],
      billingAddress: [],
      showAddressModal: false,
      modalAction: {
        type: "billing",
        action: "create",
      },
      address: {},
      address_mock: {
        label: "",
        name: "",
        phone: "",
        email: "",
        company_name: "",
        vat_number: "",
        legal_registration_id: "",
        address_1: "",
        address_2: "",
        city: "",
        state: "",
        postcode: "",
        country: "",
        is_primary: "0",
      },
      customerModalInfos: {
        showModal: false,
        action: "edit",
        title: "Edit Customer Information",
        customer: {},
      },
      detaching: false,
      recalculatingLtv: false,
      verifyingEmail: false,
      shouldShowSetAsAlsoCheckbox: true,
      closingModal: false,
    };
  },
  computed: {
    customerDisplayName() {
      if (this.localizedData.is_full_name_required) {
        return this.customer?.full_name;
      }
      return this.customer?.first_name + ' ' + this.customer?.last_name;
    },
    hasPrimaryBilling() {
      return this.billingAddress.findIndex(
          (address) => address.is_primary == "1"
      );
    },
    hasPrimaryShipping() {
      return this.shippingAddress.findIndex(
          (address) => address.is_primary == "1"
      );
    },
  },
  mounted() {
    this.billingAddress = this.customer.billing_address;
    this.shippingAddress = this.customer.shipping_address;
    this.customerModalInfos.customer = this.customer;
    this.customerModalInfos.customer_id = this.customer.id;
  },
  methods: {
    handleCustomerBulkCommand(command) {
      if (command === "edit_customer_information") {
        this.customerModalInfos.showModal = true;
      }
      if (command === "manage_billing_address") {
        this.manageAddress("billing");
      }
      if (command === "manage_shipping_address") {
        this.manageAddress("shipping");
      }
      if (command === "recalculate_ltv") {
        this.recalculateLtv();
      }
    },
    getCustomerTotalOrders(purchase_count) {
      return purchase_count > 0
          ? purchase_count +
          " " +
          (purchase_count.toString() === '1' ? this.$t("order") : this.$t("orders"))
          : this.$t("No orders");
    },
    refresh() {
      this.addOrEditAddress = false;
      this.fetch();
    },
    getTitle() {
      if (this.modalAction.action === "create") {
        return this.modalAction.type === "billing"
            ? this.$t("Add new billing address")
            : this.$t("Add new shipping Address");
      } else {
        return this.modalAction.type === "billing"
            ? this.$t("Edit billing address")
            : this.$t("Edit shipping address");
      }
    },
    addNewAddress(type = "billing", action = "create", copiedData = []) {
      if (Object.keys(copiedData).length > 0) {
        this.address = JSON.parse(JSON.stringify(copiedData));
        this.shouldShowSetAsAlsoCheckbox = false;
      } else {
        this.address = JSON.parse(JSON.stringify(this.address_mock));
      }
      this.addOrEditAddress = true;
      this.modalAction.type = type;
      this.modalAction.action = action;
      this.showAddressModal = false;
    },
    manageAddress(type = "billing", action = "edit") {
      this.showAddressModal = true;
      this.modalAction.type = type;
      this.modalAction.action = action;
    },
    fetch() {
      this.$emit("fetch");
    },
    recalculateLtv() {
      this.recalculatingLtv = true;
      this.$post(`customers/${this.customer_id}/recalculate-ltv`)
          .then((response) => {
            if (response.customer) {
              this.customer.ltv = response.customer.ltv;
              this.customer.purchase_count = response.customer.purchase_count;
              this.customer.aov = response.customer.aov;
              this.customer.first_purchase_date = response.customer.first_purchase_date;
              this.customer.last_purchase_date = response.customer.last_purchase_date;
            }
            this.handleSuccess(response.message);
          })
          .catch((error) => {
            this.handleError(error.message || this.$t('Failed to recalculate lifetime value'));
          })
          .finally(() => {
            this.recalculatingLtv = false;
          });
    },
    markEmailVerified() {
      this.verifyingEmail = true;
      this.$post(`customers/${this.customer_id}/mark-email-verified`)
          .then((response) => {
            this.handleSuccess(response.message);
            // Guest orders may have been linked; the parent reloads the stats and the order list.
            this.$emit("emailVerified");
          })
          .catch((error) => {
            this.handleError(error.message || this.$t('The email could not be verified. Please try again.'));
          })
          .finally(() => {
            this.verifyingEmail = false;
          });
    },
    handleDetachUser() {
      this.detaching = true;
      Rest.post(`customers/${this.customer_id}/detach-user`, {
        user_id: this.customer.user_id,
      })
          .then((response, status) => {
            this.customer.user_id = 0;
            Notify.success(response.message);
          })
          .catch((error) => {
            if (error.message) {
              return Notify.error(error.message);
            } else {
            }
          })
          .finally(() => {
            this.detaching = false;
          });
    },
  },
};
</script>
