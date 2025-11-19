<script setup>
import * as Card from "@/Bits/Components/Card/Card.js";
import translate, {translateNumber} from "@/utils/translator/Translator";
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";
import Rest from "@/utils/http/Rest";
import {onMounted, ref, watch} from "vue";
import Notify from "@/utils/Notify";
import CopyToClipboard from "@/Bits/Components/CopyToClipboard.vue";
import NewCustomerModal from "@/Modules/Orders/Modals/NewCustomerModal.vue";
import ManageCustomerAddressModal from "@/Modules/Customers/Modals/ManageCustomerAddressModal.vue";
import AddOrEditAddressModal from "@/Modules/Customers/Modals/AddOrEditAddressModal.vue";
import {Back} from "@element-plus/icons-vue";
import ChangeOrderCustomer from "@/Modules/Orders/Components/ChangeOrderCustomer.vue";
import {formatNumber} from "@/Bits/productService";

const props = defineProps({
  order: {
    type: Object,
    default: () => ({}),
  },
  shouldEnableEditing: {
    type: Boolean,
    default: true,
  },
});

const preFetchedCustomer = ref([]);
const currentlySelectedCustomer = ref(
    props.order?.customer?.id ? props.order.customer : null
);

const currentBillingAddress = ref(
    props.order?.billing_address ? props.order.billing_address : null
);

const currentShippingAddress = ref(
    props.order?.shipping_address ? props.order.shipping_address : null
);

const emit = defineEmits(["onAddressSelected", "onAddressRemove"]);

const showCustomerChangeModal = ref(false);

const commandHandler = (command) => {
  if (command === "remove_customer") {
    resetCustomer();
  }
  if (command === "change_customer") {
    showCustomerChangeModal.value = true;
    //resetCustomer();
  } else if (command === "edit_customer") {
    openCustomerModel("edit");
  } else if (command === "manage_billing_address") {
    manageAddress("billing");
  } else if (command === "manage_shipping_address") {
    manageAddress("shipping");
  }
};

const resetCustomer = () => {
  props.order.customer_id = "";
  props.order.customer = {};
  currentlySelectedCustomer.value = null;
  currentBillingAddress.value = null;
  currentShippingAddress.value = null;
  emit('onAddressRemove');
  setTimeout(() => {
    renderCreateCustomerButton();
  }, 50);
};

const searchCustomer = (name, cb) => {
  Rest.get("customers/", {
    search: name,
    with: ["shipping_address", "billing_address"],
  })
      .then((response) => {
        let customers = response.customers.data;
        preFetchedCustomer.value = customers;
        let format = customers.map((customer) => {
          return {
            id: customer.id,
            value: customer.full_name,
            email: customer.email,
          };
        });

        let autocompleteDropdown = jQuery(".fct-customer-autocomplete-dropdown");
        let autocompleteSuggestion = jQuery(".el-autocomplete-suggestion__list");
        let emptyTextClass = "empty-text";

        if (customers.length < 1) {
          if (autocompleteDropdown.length > 0) {
            autocompleteDropdown.addClass("is-empty-customer");
            if (!autocompleteSuggestion.children(`.${emptyTextClass}`).length) {
              autocompleteSuggestion.prepend(
                  `<li class="${emptyTextClass}">No customer found.</li>`
              );
            }
          }
        } else {
          if (autocompleteDropdown.length > 0) {
            autocompleteDropdown.removeClass("is-empty-customer");
            autocompleteSuggestion.children(`.${emptyTextClass}`).remove();
          }
        }
        cb(format);
      })
      .catch((errors) => {
        Notify.error(errors);
      });
};

const selectCustomer = (formattedCustomer) => {
  const customer =
      preFetchedCustomer.value.find(
          (customer) => customer.id === formattedCustomer.id
      ) ?? null;
  setCustomer(customer);
};

const setCustomer = (customer) => {
  currentlySelectedCustomer.value = customer;
  props.order.customer = customer;
  props.order.customer_id = customer.id;
  if (currentlySelectedCustomer.value !== null) {
    setBillingAddress();
    setShippingAddress();
  }
};


const setBillingAddress = () => {
  //Set the current billing address
  const addresses = currentlySelectedCustomer.value.billing_address;

  if(!addresses) return;
  if (Array.isArray(addresses) && addresses.length > 0) {
    //Set the fallback address
    currentBillingAddress.value = addresses[0];
    //Set the primary address
    currentBillingAddress.value =
        addresses.find((address) => address.is_primary.toString() === "1") ??
        currentBillingAddress.value;
  } else {
    currentBillingAddress.value = null;
  }

  emit("onAddressSelected", currentBillingAddress.value);
};
const setShippingAddress = () => {
  //Set the current shipping address
  const addresses = currentlySelectedCustomer.value.shipping_address;
  if(!addresses) return;
  if (Array.isArray(addresses) && addresses.length > 0) {
    //Set the fallback address
    currentShippingAddress.value = addresses[0];
    //Set the primary address
    currentShippingAddress.value =
        addresses.find((address) => address.is_primary.toString() === "1") ??
        currentShippingAddress.value;
  } else {
    currentShippingAddress.value = null;
  }

  emit("onAddressSelected", currentShippingAddress.value);
};

const setAddress = (address) => {

  if (!address) {
    return;
  }
  Rest.post(`orders/${props.order.id}/update-address-id`, {
    address_id: address.id,
    address_type: address.type,
  }).then((response) => {
    Notify.success(response.message);
    if (address.type === "shipping") {
      currentShippingAddress.value = address;
    } else {
      currentBillingAddress.value = address;
    }
    customerAddressModalInfos.value.showModal = false;

    emit("onAddressSelected", address);
  }).catch((errors) => {
    Notify.error(errors);
  });

};

const customerModalInfos = ref({
  showModal: false,
  action: "create",
  title: translate("Create New Customer"),
  customer: {},
  manageWpUser: "yes",
});

const openCustomerModel = (action = "create") => {
  const modelInfo = customerModalInfos.value;
  modelInfo.showModal = true;
  modelInfo.action = action === "create" ? "create" : "edit";

  modelInfo.title =
      action === "create"
          ? translate("Create New Customer")
          : translate("Edit Customer Information");

  modelInfo.customer_id =
      action === "create" ? null : currentlySelectedCustomer.value.id;
  modelInfo.customer =
      action === "create" ? {} : currentlySelectedCustomer.value;
  // this.customerModalInfos.manageWpUser = action == "create" ? "yes" : "no";
  modelInfo.manageWpUser =
      action === "create"
          ? "yes"
          : currentlySelectedCustomer.value.user_id === "0"
              ? "no"
              : "yes";

  customerModalInfos.value = modelInfo;
};

const shouldShowSetAsAlsoCheckbox = ref(true);

const customerAddressModalInfos = ref({
  showManageAddressModal: false,
  showModal: false,
  modalAction: {
    type: "billing",
    action: "create",
  },
  title: "",
  customer_id: "",
  address_id: "",
  address: {},
  address_mock: {
    name: "",
    phone: "",
    email: "",
    address_1: "",
    address_2: "",
    city: "",
    state: "",
    postcode: "",
    country: "",
    is_primary: "0",
  },
});

const manageAddress = (type = "billing", action = "edit") => {
  customerAddressModalInfos.value.showManageAddressModal = true;
  customerAddressModalInfos.value.modalAction.type = type;
  customerAddressModalInfos.value.modalAction.action = action;
};

const addNewAddress = (type = "billing", action = "create", copiedData = {}) => {
  if (Object.keys(copiedData).length > 0) {
    customerAddressModalInfos.value.address = {
      ...copiedData,
    };
    shouldShowSetAsAlsoCheckbox.value = false;
  } else {
    customerAddressModalInfos.value.address = {
      ...customerAddressModalInfos.value.address_mock,
    };
  }
  customerAddressModalInfos.value.showModal = true;
  customerAddressModalInfos.value.modalAction.type = type;
  customerAddressModalInfos.value.modalAction.action = action;
  customerAddressModalInfos.value.showManageAddressModal = false;
};
const renderCreateCustomerButton = () => {
  let suggestionElem = jQuery(".el-autocomplete-suggestion__wrap");
  if (suggestionElem.length > 0) {
    let buttonElem = jQuery(
        `<div class="fct-create-customer-button-wrap"><button class="el-button el-button--info el-button--small is-plain"><svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 14 14" fill="none"><path d="M1.5 7H12.5M7 1.5V12.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"></path></svg> Create a new customer</button></div>`
    ).on("click", () => {
      openCustomerModel();
    });
    suggestionElem.prepend(buttonElem);
  }
};

const getTitle = () => {
  if (customerAddressModalInfos.value.modalAction.action === "create") {
    return customerAddressModalInfos.value.modalAction.type === "billing"
        ? translate("Add new billing address")
        : translate("Add new shipping Address");
  } else {
    return customerAddressModalInfos.value.modalAction.type === "billing"
        ? translate("Edit billing address")
        : translate("Edit shipping address");
  }
};

const refreshAddress = (showModal, address) => {
  let type = customerAddressModalInfos.value.modalAction.type;

  if (type === "billing") {
    currentBillingAddress.value = address;
  } else {
    currentShippingAddress.value = address;
  }
  customerAddressModalInfos.value.showModal = showModal;
  customerAddressModalInfos.value.showManageAddressModal = true;
  setAddress(address);
};
watch(props.order, (newVal, oldVal) => {
  if (newVal.customer_id !== oldVal.customer_id) {
    resetCustomer();
  }
});

onMounted(() => {
  renderCreateCustomerButton();
});
</script>

<template>
  <div class="fct-admin-sidebar">
    <Card.Container>
      <Card.Header
          :title="translate('Customer Information')"
          title_size="small"
          border_bottom
      >
        <template #action>
          <el-dropdown
              trigger="click"
              class="fct-more-option-wrap"
              popper-class="fct-dropdown"
              v-if="currentlySelectedCustomer && shouldEnableEditing"
              @command="commandHandler"
          >
            <span class="more-btn">
              <DynamicIcon name="More"/>
            </span>
            <template #dropdown>
              <el-dropdown-menu>
                <el-dropdown-item command="edit_customer">
                  {{ translate("Edit customer information") }}
                </el-dropdown-item>
                <el-dropdown-item command="manage_shipping_address">
                  {{ translate("Manage shipping address") }}
                </el-dropdown-item>
                <el-dropdown-item command="manage_billing_address">
                  {{ translate("Manage billing address") }}
                </el-dropdown-item>

                <el-dropdown-item
                    v-if="order.id"
                    command="change_customer"
                >
                  {{ translate("Change customer") }}
                </el-dropdown-item>

                <el-dropdown-item
                    v-if="!order.id"
                    command="remove_customer"
                >
                  {{ translate("Remove customer") }}
                </el-dropdown-item>
              </el-dropdown-menu>
            </template>
          </el-dropdown>
        </template>
      </Card.Header>

      <Card.Body>
        <div
            class="fct-admin-sidebar-item"
            v-if="currentlySelectedCustomer === null"
        >
          <el-autocomplete
              popper-class="fct-customer-autocomplete-dropdown"
              clearable
              v-model="order.customer.full_name"
              :fetch-suggestions="searchCustomer"
              :placeholder="translate('Search or create a customer')"
              :disabled="false"
              @select="selectCustomer"
          >
            <template #prefix>
              <DynamicIcon name="Search"/>
            </template>
            <template #default="scope">
              <span class="name">{{ scope.item.value }}</span>
              <span class="email">{{ scope.item.email }}</span>
            </template>
          </el-autocomplete>
        </div>
        <template v-else>
          <div class="fct-admin-sidebar-item">
            <div class="fct-profile-card">
              <div class="fct-profile-image cursor-pointer" @click="$router.push({
                name: 'view_customer',
                params: { customer_id: currentlySelectedCustomer.id },
              })">
                <img
                    :src="currentlySelectedCustomer.photo"
                    :alt="currentlySelectedCustomer?.full_name"
                />
              </div>
              <div
                  class="fct-profile-details"
                  v-if="currentlySelectedCustomer.id"
              >
                <div class="title">
                  <router-link
                      :to="{
                      name: 'view_customer',
                      params: { customer_id: currentlySelectedCustomer.id },
                    }"
                  >
                    {{ currentlySelectedCustomer.full_name }}
                  </router-link>
                </div>
                <span class="text">
                  <span v-if="currentlySelectedCustomer.purchase_count > 0">
                    {{ translateNumber(currentlySelectedCustomer.purchase_count) }}
                  </span>
                  {{
                    $n(
                        "Order",
                        "Orders",
                        currentlySelectedCustomer.purchase_count ?? 0,
                        "No Order"
                    )
                  }}
                </span>
                 <!-- <CustomerPurchaseValue :value="currentlySelectedCustomer?.purchase_value"/>-->
                <span class="text">
                  {{formatNumber(currentlySelectedCustomer?.ltv, true)}} {{ translate('Lifetime Value') }}
                </span>

              </div>
            </div>

            <div class="user-info mt-2" v-if="order.customer.user_link">
              {{translate('WP User:')}} <a :href="order.customer.user_link" target="_blank"
                          class="inline-flex items-center gap-1 dark:text-gray-100 text-primary-500 focus:shadow-none hover:!underline">
              #{{ order.customer.user_id }}
              <DynamicIcon name="Redirect" class="w-2.5 h-2.5 text-primary-500 dark:text-gray-200"/>
            </a>
            </div>
          </div>

          <div class="fct-admin-sidebar-item mt-5">


            <ul class="fct-contact-info-list">
              <li>
                <div class="flex items-center justify-between gap-2">
                  <a class="text" :href="'mailto:' + order.customer?.email">
                    {{ currentlySelectedCustomer.email }}
                  </a>
                  <CopyToClipboard
                      :text="currentlySelectedCustomer.email"
                      showMode="basic_copy_btn"
                      :tooltipText="translate('Copy email')"
                  />
                </div>
              </li>
            </ul>
          </div>

          <div class="fct-admin-sidebar-item mt-5">
            <h4 class="sidebar-title">{{ translate("Shipping Address") }}</h4>
            <div
                v-if="currentShippingAddress != null"
                class="fct-address-copy-wrap"
            >
              <span class="text">
                <strong v-if="currentShippingAddress.name" class="pr-1">{{
                    currentShippingAddress.name
                  }}</strong>
                <span
                    class="break-all"
                    v-html="
                    getOrderAddress(
                      {
                        ...currentShippingAddress,
                      },
                      'shipping',
                      true
                    )
                  "
                ></span>
                <span v-if="currentShippingAddress?.meta?.other_data?.phone" class="mt-3">
                   <strong class="pr-1">{{ translate("Phone") }}:</strong>
                   <a :href="'tel:' + currentShippingAddress.meta.other_data.phone" class="text-system-mid dark:text-gray-50 hover:underline">
                     {{ currentShippingAddress.meta.other_data.phone }}
                   </a>
                </span>
              </span>
              <CopyToClipboard
                  :text="
                  getOrderAddress(
                    {
                      ...currentShippingAddress,
                    },
                    'shipping'
                  )
                "
                  showMode="basic_copy_btn"
                  :tooltipText="translate('Copy address')"
              />
            </div>
            <!-- .fct-address-copy-wrap -->

            <template v-else>
              <p class="no-address-text">
                {{ translate("No shipping address provided") }}
              </p>
            </template>
          </div>

          <div class="fct-admin-sidebar-item mt-5">
            <h4 class="sidebar-title">{{ translate("Billing Address") }}</h4>
            <div
                v-if="currentBillingAddress != null"
                class="fct-address-copy-wrap"
            >
              <div class="text">
                <strong v-if="currentBillingAddress.name">
                  {{ currentBillingAddress.name }}
                </strong>
                <span
                    class="break-all"
                    v-html="
                    getOrderAddress(
                      {
                        ...currentBillingAddress,
                      },
                      'billing',
                      true
                    )
                  "
                ></span>

                <span v-if="currentBillingAddress?.meta?.other_data?.phone" class="mt-3">
                   <strong class="pr-1">{{ translate("Phone") }}:</strong>
                  <a :href="'tel:' + currentBillingAddress.meta.other_data.phone" class="text-system-mid dark:text-gray-50 hover:underline">
                    {{ currentBillingAddress.meta.other_data.phone }}
                  </a>
                </span>

                <span v-if="currentBillingAddress?.meta?.other_data?.company_name" class="mt-3">
                  <strong class="pr-1">{{ translate("Company") }}:</strong>
                  <span>{{ currentBillingAddress.meta.other_data.company_name }}</span>
                </span>
              </div>

              <CopyToClipboard
                  :text="
                  getOrderAddress(
                    {
                      ...currentBillingAddress,
                    },
                    'billing'
                  )
                "
                  showMode="basic_copy_btn"
                  :tooltipText="translate('Copy address')"
              />
            </div>
            <!-- .fct-address-copy-wrap -->

            <template v-else>
              <p class="no-address-text">
                {{ translate("No shipping address provided") }}
              </p>
            </template>
          </div>
        </template>
      </Card.Body>
    </Card.Container>
  </div>

  <NewCustomerModal
      @select-new-customer="
      (customer) => {
        setCustomer(customer);
        customerModalInfos.showModal = false;
      }
    "
      :customerModalInfos="customerModalInfos"
      :customer_id="customerModalInfos.customer_id"
      :customer="customerModalInfos.customer"
      @close-modal="customerModalInfos.showModal = false"
  />

  <ManageCustomerAddressModal
      :modalAction="customerAddressModalInfos.modalAction"
      @onAddressSelected="setAddress"
      @update:showAddressModal="
      (val) => (customerAddressModalInfos.showManageAddressModal = val)
    "
      :showAddressModal="customerAddressModalInfos.showManageAddressModal"
      :customer_id="currentlySelectedCustomer?.id"
      :order_id="order.id"
      @addNewAddress="addNewAddress"
  />

  <el-dialog
      :show-close="true"
      v-model="customerAddressModalInfos.showModal"
      :append-to-body="true"
      :title="getTitle()"
  >
    <div class="fluent-cart-admin-pages">
      <el-button
          text
          @click="
          () => {
            customerAddressModalInfos.showManageAddressModal = true;
            customerAddressModalInfos.showModal = false;
          }
        "
          class="mb-4"
      >
        <el-icon>
          <Back/>
        </el-icon>
        {{ $t("Manage address") }}
      </el-button>
      <AddOrEditAddressModal
          @update:refresh-address="refreshAddress"
          :customerAddressModalInfos="customerAddressModalInfos"
          :customer_id="currentlySelectedCustomer.id"
          :modalAction="customerAddressModalInfos.modalAction"
          :address="customerAddressModalInfos.address"
          :showAddressModal="customerAddressModalInfos.showModal"
          :showSetAsAlsoCheckbox="shouldShowSetAsAlsoCheckbox"
          :order_id="order?.id"
          @close-modal="
          () => {
            customerAddressModalInfos.showModal = false;
            customerAddressModalInfos.showManageAddressModal = true;
          }
        "
      />
    </div>
  </el-dialog>

  <el-dialog v-if="order.id" v-model="showCustomerChangeModal" :title="$t('Change Customer')">
    <ChangeOrderCustomer :order-id="order.id" @close="showCustomerChangeModal = false"/>
  </el-dialog>
</template>
