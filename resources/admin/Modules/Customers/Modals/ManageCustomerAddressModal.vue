<template>
  <div class="fct-manage-address-modal">
    <el-dialog
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
    >
      <el-skeleton v-if="loading" animated :rows="4"/>
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

          <div class="fct-address-action absolute top-0 right-0 pt-2">
            <el-dropdown
                trigger="click"
                class="fct-more-option-wrap"
                popper-class="fct-dropdown"
                @command="handleAddressCommand"
            >
              <span class="more-btn">
                <DynamicIcon name="More"/>
              </span>
              <template #dropdown>
                <el-dropdown-menu>
                  <el-dropdown-item
                      :command="{
                      action: 'edit',
                      data: address,
                    }"
                  >
                    {{ $t("Edit") }}
                  </el-dropdown-item>

                  <el-dropdown-item
                      :command="{
                      action: 'use',
                      data: address,
                    }"
                  >
                    {{ $t("Use this address") }}
                  </el-dropdown-item>

                  <el-dropdown-item
                      :command="{
                      action: 'set-as-also',
                      data: address,
                    }"
                  >
                    {{
                      modalAction.type === "billing"
                          ? $t("Copy to Shipping")
                          : $t("Copy to Billing")
                    }}
                  </el-dropdown-item>

                  <template v-if="address.is_primary !== '1'">
                    <el-dropdown-item
                        :command="{
                        action: 'default',
                        data: address,
                      }"
                    >
                      {{ $t("Set as default") }}
                    </el-dropdown-item>
                    <el-dropdown-item
                        :command="{
                        action: 'remove',
                        data: address,
                      }"
                        class="item-destructive"
                    >
                      {{ $t("Delete") }}
                    </el-dropdown-item>
                  </template>
                </el-dropdown-menu>
              </template>
            </el-dropdown>
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
        <el-button
            text
            @click="() => (isEditAddress = false)"
            style="margin-bottom: 16px"
        >
          <el-icon>
            <Back/>
          </el-icon>
          {{ $t("Manage address") }}
        </el-button>
        <AddOrEditAddressModal
            @update:refresh-address="
            (val) => {
              isEditAddress = val;
              updateAddress();
            }
          "
            :customer_id="customer_id"
            :modalAction="modalAction"
            :address="address"
            :showAddressModal="isEditAddress"
            :order_id="order_id"
            @close-modal="showModal = false"
        />
      </div>
      <div class="dialog-footer" v-if="!isEditAddress">
        <el-button
            v-if="selectedAddressIndex != null"
            @click="emitAddressSelected()"
        >
          <DynamicIcon name="LocationPin"/>
          {{ $t("Use this address") }}
        </el-button>

        <el-button @click="emitAddNewAddress(modalAction.type)" type="primary">
          <DynamicIcon name="Plus"/>
          {{ $t("Add new address") }}
        </el-button>
      </div>
    </el-dialog>
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
  props: ["showAddressModal", "customer_id", "modalAction", "order_id"],
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
        this.$confirm("Are you sure want to delete this address?", "Warning", {
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
    updateAddress() {
      this.fetchAddresses();
      this.$emit("refresh-address");
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
