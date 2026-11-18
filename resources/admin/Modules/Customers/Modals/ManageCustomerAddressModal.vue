<template>
  <div class="fct-manage-address-modal">
    <fct-dialog
        v-model="showModal"
        @close="
        () => {
          selectedAddressIndex = null;
          isEditAddress = false;
        }
      "
        @open="fetchAddresses"
        :title="getTitle()"
        :append-to-body="true"
        modal-class="fct-manage-order-address-modal"
    >
      <fct-skeleton v-if="loading" animated :rows="4"/>
      <div v-if="!loading && !isEditAddress" class="fct-manage-address-wrap">
        <div
            class="fct-manage-address-block-wrap"
            v-for="(address, index) in manageAddress"
            :key="address.id"
        >
          <div
              :class="`cursor-pointer fct-manage-address-block ${
              address.is_primary === '1' ? 'default-address' : ''
            }  ${index === selectedAddressIndex ? 'selected' : ''} `"
              @click="
              () => {
                selectedAddressIndex = index;
              }
            "
          >
            <el-tag type="info" v-if="address.is_primary === '1'" round>
              {{ $t("Default") }}
            </el-tag>
            <p>
              <strong>{{ address.name }}</strong>
              {{ getOrderAddress(address, "", true) }}
            </p>
          </div>

          <div class="fct-address-action absolute top-0 end-0 pt-2">
            <fct-dropdown
                trigger="click"
                class="fct-more-option-wrap"
                :items="[
                  {command: 'edit', label: $t('Edit')},
                  {command: 'use', label: $t('Use this address')},
                  {command: 'set-as-also', label: modalAction.type === 'billing' ? $t('Copy to Shipping') : $t('Copy to Billing')},
                  ...(address.is_primary !== '1' ? [
                    {command: 'default', label: $t('Set as default')},
                    {command: 'remove', label: $t('Delete'), danger: true}
                  ] : [])
                ]"
                @command="(action) => handleAddressCommand({action, data: address})"
            >
              <span class="more-btn">
                <DynamicIcon name="More"/>
              </span>
            </fct-dropdown>
          </div>
        </div>

        <div v-if="manageAddress.length == 0" class="empty-text">
          {{
            modalAction.type === "billing"
                ? $t("No billing address found.")
                : $t("No shipping address found.")
          }}
        </div>
      </div>
      <div v-if="isEditAddress" class="edit_address">
        <fct-button
            text
            @click="() => (isEditAddress = false)"
            style="margin-bottom: 16px"
        >
          <el-icon class="fct-address-back-icon">
            <Back/>
          </el-icon>
          {{ $t("Manage address") }}
        </fct-button>
        <AddOrEditAddressModal
            @update:refresh-address="
            (val, savedAddress, orderAddress) => {
              isEditAddress = val;
              updateAddress(savedAddress, orderAddress);
            }
          "
            :customer_id="customer_id"
            :modalAction="modalAction"
            :address="address"
            :showAddressModal="isEditAddress"
            :order_id="order_id"
            :showBusinessDetailsSection="showBusinessDetailsSection"
            @close-modal="showModal = false"
        />
      </div>
      <div class="dialog-footer" v-if="!isEditAddress">
        <fct-button
            v-if="selectedAddressIndex != null"
            @click="emitAddressSelected()"
        >
          <DynamicIcon name="LocationPin"/>
          {{ $t("Use this address") }}
        </fct-button>

        <fct-button @click="emitAddNewAddress(modalAction.type)" type="primary">
          {{ $t("Add new address") }}
        </fct-button>
      </div>
    </fct-dialog>
  </div>
</template>

<script setup>
import {EditPen, Back, InfoFilled} from "@element-plus/icons-vue";
import AddOrEditAddressModal from "./AddOrEditAddressModal.vue";
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";
</script>

<script type="text/babel">
import Notify from "@/utils/Notify";

export default {
  name: "ManageAddressModal",
  props: ["showAddressModal", "customer_id", "modalAction", "order_id","showBusinessDetailsSection"],
  expose: ["editAddress"],
  data() {
    return {
      manageAddress: [],
      address: {},
      loading: true,
      isEditAddress: false,
      selectedAddressIndex: null,
    };
  },
  computed: {
    showModal: {
      get() {
        return this.showAddressModal;
      },
      set(val) {
        this.$emit("update:showAddressModal", val);
      },
    },
  },
  methods: {
    handleAddressCommand(command) {
      if (command.action === "edit") {
        this.editAddress(command.data);
      } else if (command.action === "use") {
        this.$emit("onAddressSelected", command.data);
      } else if (command.action === "set-as-also") {
        this.setAsAlso(command.data);
      } else if (command.action === "default") {
        this.setAddressPrimary(command.data);
      } else if (command.action === "remove") {
        this.$confirm(this.$t("Are you sure you want to delete this address?"), this.$t("Warning"), {
          confirmButtonText: this.$t("Yes, Delete!"),
          cancelButtonText: this.$t("Cancel"),
          cancelButtonClass: "el-button--small",
          confirmButtonClass: "el-button--small",
          type: "warning",
        })
            .then(() => {
              this.removeAddress(command.data);
            })
            .catch(() => {
            });
      }
    },
    emitAddNewAddress(type, copiedData = []) {
      this.$emit("addNewAddress", type, 'create', copiedData);
    },

    emitAddressSelected() {
      this.showModal = false;
      this.$emit(
          "onAddressSelected",
          this.manageAddress[this.selectedAddressIndex]
      );
    },

    editAddress(address) {
      this.address = address;
      this.isEditAddress = true;
    },
    removeAddress(address) {
      let addressId = address.id;
      this.$del("customers/" + this.customer_id + "/address", {
        address: address,
      })
          .then((response) => {
            this.getAddresses();
            this.$emit("refresh-address");
            this.handleSuccess(response.message);

            if (addressId === this.selectedAddressIndex) {
              this.selectedAddressIndex = null;
            }
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
          });
    },
    setAddressPrimary(address) {
      this.$post("customers/" + this.customer_id + "/address/make-primary", {
        addressId: address.id,
        type: address.type
      })
          .then((response) => {
            this.$emit("refresh-address");
            this.getAddresses();
            this.handleSuccess(response.message);
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
          });
    },
    getTitle() {
      return this.modalAction.type === "billing"
          ? this.$t("Manage billing address")
          : this.$t("Manage shipping address");
    },
    fetchAddresses() {
      this.getAddresses(this.modalAction.type);
    },
    getAddresses(type = "billing") {
      this.loading = true;
      this.selectedOrders = [];
      this.$get("customers/" + this.customer_id + "/address", {
        type: this.modalAction.type,
      })
          .then((addresses) => {
            this.manageAddress = addresses.addresses;
          })
          .catch((errors) => {
            this.handleError(errors);
          })
          .finally(() => {
            this.loading = false;
          });
    },
    updateAddress(savedAddress = null, orderAddress = null) {
      this.fetchAddresses();
      this.$emit("refresh-address", savedAddress, orderAddress);
    },
    setAsAlso(address) {
      const type = this.modalAction.type === "billing" ? "shipping" : "billing";
      const addressPayload = {
        ...address,
        type,
        customer_id: this.customer_id,
        is_primary: 0,
      };
      delete addressPayload.id;
      delete addressPayload.created_at;
      delete addressPayload.updated_at;
      this.emitAddNewAddress(type, addressPayload)
    },
  },
};
</script>
