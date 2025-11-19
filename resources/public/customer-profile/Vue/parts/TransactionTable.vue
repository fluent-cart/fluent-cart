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
            {{ formatDate(scope.row.created_at) }}
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

      <el-table-column :width="125">
        <template #default="scope">
          <div class="flex items-center gap-2 justify-between">
            <a :href="scope.row.receipt_url" target="_blank"
               class="underline-link-button" :aria-label="$t('Download Receipt for Invoice') + ' ' + scope.row.invoice_no"
                rel="noopener noreferrer">
              <DynamicIcon name="Download" class="w-4 h-4" aria-hidden="true"/>
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
import Badge from "@/Bits/Components/Badge.vue";
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";
import TransactionPaymentMethod from './_TransactionPaymentMethod.vue';
import translate from "../../translator/Translator";
import MaterialInput from "@/Bits/Components/MaterialInput.vue";
import Str from "@/utils/support/Str";
import AddressComponent from "./AddressComponent.vue";

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
    handleTransactionCommand(command, row) {
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
    getStatusText(status) {
      switch (status) {
        case 'completed':
          return translate('Completed');
        case 'paid':
          return translate('Paid');
        case 'active':
          return translate('Active');
        case 'publish':
          return translate('Published');
        case 'draft':
          return translate('Draft');
        case 'shipped':
          return translate('Shipped');
        case 'success':
          return translate('Success');
        case 'licensed':
          return translate('Licensed');
        case 'succeeded':
          return translate('Succeeded');
        case 'failed':
          return translate('Failed');
        case 'error':
          return translate('Error');
        case 'canceled':
          return translate('Canceled');
        case 'expired':
          return translate('Expired');
        case 'partially_paid':
          return translate('Partially Paid');
        case 'intended':
          return translate('Intended');
        case 'scheduled':
          return translate('Scheduled');
        case 'on-hold':
          return translate('On Hold');
        case 'pending':
          return translate('Pending');
        case 'unpaid':
          return translate('Unpaid');
        case 'warning':
          return translate('Warning');
        case 'processing':
          return translate('Processing');
        case 'future':
          return translate('Future');
        case 'inactive':
          return translate('Inactive');
        case 'dispute':
          return translate('Dispute');
        case 'disabled':
          return translate('Disabled');
        case 'beta':
          return translate('Beta');
        case 'subscription':
          return translate('Subscription');
        case 'renewal':
          return translate('Renewal');
        case 'payment':
          return translate('Payment');
        default:
          return Str.headline(status);
      }
    }
  }
}
</script>
