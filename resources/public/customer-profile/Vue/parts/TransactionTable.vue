<template>
  <div>
    <el-table :data="transactions" :aria-label="$t('Transactions Table')">
      <el-table-column label="#" :width="150">
        <template #default="scope">
          <span class="text truncate" :aria-label="$t('Invoice Number') + ' ' + scope.row.invoice_no">#{{ scope.row.invoice_no }}</span>
        </template>
      </el-table-column>

      <el-table-column :label="$t('Date')" :width="120">
        <template #default="scope">
          <time class="text truncate" :datetime="scope.row.created_at">
            {{ dateTimeI18(scope.row.created_at, 'MMM DD, YYYY') }}
          </time>
        </template>
      </el-table-column>

      <el-table-column :label="$t('Amount')" width="90">
        <template #default="scope">
          <span class="text" v-html="formatNumber(scope.row.total, true, false, scope.row.currency)" :aria-label="$t('Amount')"></span>
        </template>
      </el-table-column>

      <el-table-column :label="$t('Status')" :width="110">
        <template #default="scope">
          <Badge :type="scope.row.status" size="small" :aria-label="$t('Status') + ': ' + scope.row.status">
            {{ getStatusText(scope.row.status) }}
          </Badge>
        </template>
      </el-table-column>

      <el-table-column :label="$t('Type')" :width="100">
        <template #default="scope">
          <Badge :type="scope.row.order_type" size="small" :aria-label="$t('Order Type') + ': ' + scope.row.order_type">
            {{ getStatusText(scope.row.order_type) }}
          </Badge>
        </template>
      </el-table-column>

      <el-table-column :label="$t('Payment Method')" :min-width="160">
        <template #default="scope">
          <TransactionPaymentMethod :transaction="scope.row"/>
        </template>
      </el-table-column>

      <el-table-column :width="135">
        <template #default="scope">
          <div class="flex items-center gap-2 justify-between">
            <a v-if="scope.row.show_pay_now" :href="scope.row.custom_checkout_url"
               target="_blank" rel="noopener noreferrer"
               class="underline-link-button" :aria-label="$t('Pay Now for Invoice') + ' ' + scope.row.invoice_no">
              <DynamicIcon name="CreditCard" class="w-4 h-4" aria-hidden="true"/>
              {{ $t('Pay Now') }}
            </a>
            <a v-else :href="scope.row.receipt_download_url" target="_blank"
               class="underline-link-button" :aria-label="$t('Download Receipt for Invoice') + ' ' + scope.row.invoice_no"
                rel="noopener noreferrer">
              <DynamicIcon name="Download" aria-hidden="true"/>
              {{ $t('Receipt') }}
            </a>

            <el-dropdown
                trigger="click"
                class="fct-more-option-wrap"
                popper-class="fct-dropdown"
                @command="command => handleTransactionCommand(command, scope.row)"
                :aria-label="$t('More Actions')"
            >
                <span class="more-btn w-4 h-4 flex items-center justify-center cursor-pointer" aria-haspopup="true"
                :aria-label="$t('More Actions')">
                  <DynamicIcon name="More" class="w-1" aria-hidden="true"/>
                </span>
              <template #dropdown>
                <el-dropdown-menu>
                  <el-dropdown-item v-if="scope.row.receipt_view_url" command="view_receipt">{{ translate('View Receipt') }}
                  </el-dropdown-item>
                  <el-dropdown-item command="edit_billing_address">{{ translate('Edit Billing Address') }}
                  </el-dropdown-item>
                </el-dropdown-menu>
              </template>
            </el-dropdown>
          </div>
        </template>
      </el-table-column>
    </el-table>

    <el-dialog
        v-model="isEditingBillingAddressModal"
        :title="translate('Edit Billing Address')"
        :append-to-body="true"
        modal-class="fct-transaction-billing-address-modal"
        @open="fetchBillingAddress"
        class="fluent-cart-customer-profile-app fct-customer-root-container"
         aria-modal="true"
         role="dialog"
    >
      <div class="fct-customer-dashboard-add-address-form-wrap">
        <div class="fct-compact-form">
          <MaterialInput
              v-for="(field, index) in formFields" :key="index"
              :required="field.required"
              :label="field.label"
              v-model="billingAddressData[field.key]"
              :class="validationErrors[field.key] ? 'is-error' : ''"
          />

          <AddressComponent
              use_additional_address_fields
              v-model="billingAddressData"
              :validationErrors="validationErrors"
          />
          <MaterialInput
              :label="translate('Vat Tax ID')"
              v-model="billingAddressData['vat_tax_id']"
              :class="validationErrors['vat_tax_id'] ? 'is-error' : ''"
          />
        </div>
      </div>

      <template #footer>
        <div class="dialog-footer">
          <el-button type="info" soft @click="handleCloseModal">
            {{ translate('Cancel') }}
          </el-button>
          <el-button type="primary" :loading="updatingAddress" @click="updateBillingAddress">
            {{ translate('Update Address') }}
          </el-button>
        </div>
      </template>
    </el-dialog>
  </div>
</template>
<script type="text/babel">
import Badge from "./Badge.vue";
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";
import TransactionPaymentMethod from './_TransactionPaymentMethod.vue';
import translate from "../../translator/Translator";
import MaterialInput from "@/Bits/Components/MaterialInput.vue";
import statusLabel from "../../utils/statusLabels";
import AddressComponent from "./AddressComponent.vue";
import {dateTimeI18} from "../../translator/Translator";

export default {
  name: 'TransactionsTable',
  components: {
    AddressComponent,
    MaterialInput,
    Badge,
    DynamicIcon,
    TransactionPaymentMethod
  },
  props: {
    transactions: {
      type: Array,
      default: () => []
    },
    showTableHeader: {
      type: Boolean,
      default: true
    }
  },
  data() {
    return {
      isEditingBillingAddressModal: false,
      validationErrors: {},
      billingAddressData: {
        name: '',
        address_1: '',
        address_2: '',
        city: '',
        state: '',
        postcode: '',
        country: '',
        vat_tax_id: ''
      },
      formFields: [
        {
          key: 'name',
          label: translate('Name/Business Name'),
          required: true
        },
      ],
      selectedTransaction: null,
      updatingAddress: false,
      address_id: null
    }
  },
  methods: {
    translate,
    dateTimeI18,
    handleTransactionCommand(command, row) {
      if (command === 'view_receipt') {
        window.open(row.receipt_view_url, '_blank', 'noopener,noreferrer');
        return;
      }
      if (command === 'edit_billing_address') {
        this.selectedTransaction = row;
        this.isEditingBillingAddressModal = true;
      }
    },
    fetchBillingAddress() {
      this.$get(`customer-profile/orders/${this.selectedTransaction.uuid}/billing-address`)
          .then((response) => {
            this.billingAddressData = response.data;
            this.billingAddressData.address_1 = response.data.address_1;
            this.billingAddressData.address_2 = response.data.address_2;
            this.billingAddressData.city = response.data.city;
            this.billingAddressData.state = response.data.state;
            this.billingAddressData.postcode = response.data.postcode;
            this.billingAddressData.country = response.data.country;
            this.billingAddressData.name = response.data.name;
            this.billingAddressData.vat_tax_id = response.data.vat_tax_id;
            this.address_id = response.data.address_id;
          })
          .catch((error) => {
            if (error.message) {
              this.handleError(error);
            }
          })
          .finally(() => {
            // this.loading = false;
          });
    },
    updateBillingAddress() {
      this.updatingAddress = true;
      this.$put(`customer-profile/orders/${this.selectedTransaction.uuid}/billing-address`, {
        name: this.billingAddressData.name,
        address_1: this.billingAddressData.address_1,
        address_2: this.billingAddressData.address_2,
        city: this.billingAddressData.city,
        state: this.billingAddressData.state,
        postcode: this.billingAddressData.postcode,
        country: this.billingAddressData.country,
        vat_tax_id: this.billingAddressData.vat_tax_id,
        transaction_uuid: this.selectedTransaction.uuid,
        address_id: this.address_id
      })
          .then((response) => {
            this.handleSuccess(response.message);
            this.isEditingBillingAddressModal = false;
            this.$emit('billing-address-updated', response.formatted_address);
            // this.selectedTransaction = null;
          })
          .catch((error) => {
            this.handleError(error);
            this.validationErrors = error;
          })
          .finally(() => {
            this.updatingAddress = false;
          });
    },
    handleCloseModal() {
      this.isEditingBillingAddressModal = false;
      this.selectedTransaction = null;
    },
    getStatusText: statusLabel
  }
}
</script>
