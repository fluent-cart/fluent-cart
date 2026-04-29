<template>
  <div class="fct-form-container" role="region" :aria-labelledby="addressSectionTitleId">
    <div class="form-left">
      <h3 :id="addressSectionTitleId" class="form-heading">{{ title }}</h3>
      <p class="form-desc">{{ description }}</p>
      <AddressModal ref="modalRef" :modalAction="addressType"
                    @fetch="$emit('fetch')"
                    :isEditAddress="isEditAddress"
                    :newAddressData="newAddressData"
                    :profileDetails="profileDetails"/>
    </div>

    <div class="form-right">
      <ul v-if="addresses.length" class="fct-customer-address-list" role="list" :aria-label="`${addressType} addresses`">
        <li :class="`fct-customer-address-block ${address.is_primary === '1' ? 'default-address' : ''}`"
            v-for="address in sortedAddresses" :key="address.id" role="listitem">
          <div class="title" v-if="address.label">
            {{ address.label }}

            <Badge
                :hide-icon="true"
                v-if="address.is_primary === '1'"
                highContrast
                size="small"
                :aria-label="translate('Default')"
            >
              {{ translate('Default') }}
            </Badge>
          </div>

          <p class="text">{{ getAddress(address.formatted_address, '') }}</p>

          <div class="fct-address-action">
            <el-dropdown trigger="click" class="fct-more-option-wrap" popper-class="fct-dropdown"
                         @command="handleAddressCommand">

              <button class="more-btn" :aria-label="translate('Address options')" aria-haspopup="true"
                      aria-expanded="false">
                <DynamicIcon name="More" aria-hidden="true"/>
              </button>

              <template #dropdown>
                <el-dropdown-menu role="menu">
                  <el-dropdown-item :command="{ action: 'edit', data: address }" role="menuitem">
                    {{ translate('Edit') }}
                  </el-dropdown-item>

                  <template v-if="address.is_primary !== '1'">
                    <el-dropdown-item :command="{ action: 'default', data: address }" role="menuitem">
                      {{ translate('Set as default') }}
                    </el-dropdown-item>

                    <el-dropdown-item :command="{ action: 'remove', data: address }" class="item-destructive"
                                      role="menuitem">
                      {{ translate('Delete') }}
                    </el-dropdown-item>
                  </template>
                </el-dropdown-menu>
              </template>
            </el-dropdown>
          </div>
        </li>
      </ul>

      <div v-else class="text-sm text-system-mid" role="status" aria-live="polite">
        <!-- translators: 1: address type -->
        {{ translate(`No %1$s address found, please add a new address.`, addressType) }}
      </div>
    </div>
  </div>
</template>

<script>
import Badge from "@/Bits/Components/Badge.vue";
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";
import translate from "../../../translator/Translator";
import AddressModal from './AddressModal.vue'
import {ElMessageBox} from "element-plus";

export default {
  name: "AddressSection",
  props: {
    profileDetails: Object,
    addressType: String, // 'billing' or 'shipping'
    title: String,
    description: String
  },
  emits: ['fetch', 'setAddressPrimary', 'deleteAddress'],
  data() {
    return {
      addressSectionTitleId: `address-section-title-${this.addressType}`,
      isEditAddress: false,
      newAddressData: {
        label: '', name: '', email: '', phone: '',
        address_1: '', address_2: '', city: '', state: '',
        postcode: '', country: ''
      },
    }
  },
  components: {DynamicIcon, Badge, AddressModal},
  computed: {
    addresses() {
      return this.profileDetails[`${this.addressType}_address`] || [];
    },
    sortedAddresses() {
      return this.addresses.sort((a, b) => b.is_primary - a.is_primary);
    }
  },
  methods: {
    translate,
    handleAddressCommand(command) {
      const {action, data} = command;

      if (action === 'edit') {
        this.isEditAddress = true;
        this.newAddressData = {...data};
        this.$refs.modalRef.handleAddNewAddressModal('edit', data); // Pass edit type and data
      } else if (action === 'default') {
        this.$emit('setAddressPrimary', data);
      } else if (action === 'remove') {
        ElMessageBox.confirm(
            translate('Are you sure you want to delete this address?'),
            translate('Warning'), {
              customClass: 'fluent-cart-customer-profile-app',
              confirmButtonText: translate('Yes, Delete!'),
              cancelButtonText: translate('Cancel'),
              cancelButtonClass: 'el-button--small',
              confirmButtonClass: 'el-button--small',
              type: 'warning'
            }
        ).then(() => {
          this.$emit('deleteAddress', data);
        }).catch(() => {
        });
      }
    }
  }
}
</script>
