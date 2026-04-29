<template>
  <div class="fct-manage-address-modal">
    <el-dialog v-model="showModal"
               @close="isEditAddress = false"
               @open="fetchAddresses"
               :title="getTitle()"
               :append-to-body="true"
               modal-class="fct-manage-order-address-modal"
    >
      <div v-if="!isEditAddress" class="fct-manage-address-wrap">
        <div :class="`fct-manage-address-block ${address.is_primary  === '1' ? 'default-address' : ''}`"
             v-for="address in manageAddress" :key="address.id">
          <el-tag type="info" v-if="address.is_primary  === '1'" round>
            {{ translate('Default') }}
          </el-tag>
          <p>
            <strong>{{ address.name }}</strong>
            {{getOrderAddress(address, '', true)}}
          </p>
          <div class="fct-address-action">
            <el-dropdown trigger="click" class="fct-more-option-wrap" popper-class="fct-dropdown"
                         @command="handleAddressCommand">
                  <span class="more-btn">
                      <DynamicIcon name="More"/>
                  </span>
              <template #dropdown>
                <el-dropdown-menu>
                  <el-dropdown-item :command="{
                          action: 'edit',
                          data: address
                        }">
                    {{ translate('Edit') }}
                  </el-dropdown-item>
                  <template v-if="address.is_primary  !== '1'">
                    <el-dropdown-item :command="{
                          action: 'default',
                          data: address
                        }"
                    >
                      {{ translate('Set as default') }}
                    </el-dropdown-item>
                    <el-dropdown-item :command="{
                        action: 'remove',
                        data: address
                      }" class="item-destructive">
                      {{ translate('Delete') }}
                    </el-dropdown-item>
                  </template>
                </el-dropdown-menu>
              </template>
            </el-dropdown>
          </div>
        </div>

        <div v-if="manageAddress.length == 0" class="empty-text">
          {{ modalAction.type === 'billing' ? translate('No billing address found.') : translate('No shipping address found.') }}
        </div>

      </div>
      <div v-if="isEditAddress" class="edit_address">
        <el-button text @click="() => isEditAddress = false" style="margin-bottom: 16px;">
          <el-icon>
            <Back/>
          </el-icon>
          {{ translate('Manage address') }}
        </el-button>
        <AddOrEditAddressModal
            @update:refresh-address="val => { isEditAddress = val; updateAddress() }"
            :customer_id="customer_id"
            :modalAction="modalAction"
            :address="address"
            :showAddressModal="isEditAddress"
            :order_id="order_id"
            @close-modal="showModal = false"
        />
      </div>
      <div class="dialog-footer is-border" v-if="!isEditAddress">
        <el-button size="small" @click="emitAddNewAddress(modalAction.type)">
          <DynamicIcon name="Plus"/>
          {{ translate('Add new address') }}
        </el-button>
      </div>
    </el-dialog>
  </div>
</template>

<script setup>
import {EditPen, Back, InfoFilled} from '@element-plus/icons-vue'
import AddOrEditAddressModal from './AddOrEditAddressModal.vue';
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";
import translate from "@/utils/translator/Translator";
</script>

<script type="text/babel">
import translate from "@/utils/translator/Translator";
import Rest from "@/utils/http/Rest";
import Notify from "@/utils/Notify";

export default {
  name: 'ManageAddressModal',
  props: ['showAddressModal', 'customer_id', 'modalAction', 'order_id'],
  expose: ['editAddress'],
  data() {
    return {
      manageAddress: [],
      address: {},
      loading: true,
      isEditAddress: false,
    }
  },
  computed: {
    showModal: {
      get() {
        return this.showAddressModal;
      },
      set(val) {
        this.$emit('update:showAddressModal', val)
      }
    }
  },
  methods: {
    handleAddressCommand(command) {
      if (command.action == 'edit') {
        this.editAddress(command.data);
      }
      if (command.action == 'default') {
        this.setAddressPrimary(command.data);
      }
      if (command.action == 'remove') {
        this.$confirm(translate('Are you sure you want to delete this address?'), 'Warning', {
          confirmButtonText: translate('Yes, Delete!'),
          cancelButtonText: translate('Cancel'),
          cancelButtonClass: 'el-button--small',
          confirmButtonClass: 'el-button--small',
          type: 'warning'
        }).then(() => {
          this.removeAddress(command.data);
        })
            .catch(() => {

            });

      }
    },
    emitAddNewAddress(type, copiedData) {
      this.$emit("addNewAddress", type, 'create', copiedData);
    },
    editAddress(address) {
      this.address = address;
      this.isEditAddress = true;
    },
    removeAddress(address) {
      Rest.delete('customers/' + this.customer_id + '/address', {
        address: address
      }).then(response => {
        this.getAddresses();
        this.$emit('refresh-address');
        Notify.success(response.message);
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
      Rest.post('customers/' + this.customer_id + '/address/make-primary', {
        addressId: address.id,
        type: address.type
      }).then(response => {
        this.$emit('refresh-address');
        this.getAddresses();
        Notify.success(response.message);
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
      return this.modalAction.type === 'billing' ? 'Manage billing address' : 'Manage shipping address';
    },
    fetchAddresses() {
      this.getAddresses(this.modalAction.type);
    },
    getAddresses(type = "billing") {
      this.loading = true;
      this.selectedOrders = [];
      Rest.get('customers/' + this.customer_id + '/address', {
        type: this.modalAction.type
      })
          .then(addresses => {
            this.manageAddress = addresses.addresses;
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
    updateAddress() {
      this.fetchAddresses();
      this.$emit('refresh-address')
    },
  }
}
</script>
