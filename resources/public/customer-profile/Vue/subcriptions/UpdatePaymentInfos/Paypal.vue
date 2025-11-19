<script>
import {loadSubscriptionPaypalScript} from '@/utils/paypalLoader';
import translate from "../../../translator/Translator";


export default {
  name: 'Paypal',
  props: {
    subscription: {
      type: Object,
      required: true
    },
    updateMethod: {
      type: Boolean,
      required: true,
      default: true
    }
  },
  emits: ['close', 'fetchSubscription'],
  data() {
    return {
      updatePaymentUrl: '',
      paypalClientId: window.fluentcart_customer_profile_vars?.paypal_client_id,
      selectedAddress: '',
      loading: false,
      planId: null,
      switching: false,
      reactivating: false,
      reason: '',
      errorMessage: '',
      successMessage: ''
    }
  },
  computed: {
    getBillingAddresses() {
      return this.subscription.billing_addresses;
    },
  },
  methods: {
    translate,
    closeUpdatePaymentModal() {
      this.$emit('close');
    },
    async loadPaypal() {
      this.loading = true;
      let Paypal = null;
      try {
        Paypal = await loadSubscriptionPaypalScript();
      } catch (error) {
        this.handleError(error?.message || 'Error in loading paypal');
        this.loading = false;
      }

      this.paypal = Paypal;
      await this.loadPaypalButtons();
      this.loading = false;
    },
    async getPlanId() {
      if (this.updateMethod) {
        return await this.getPlanIdForUpdate();
      } else {
        return '';
      }
    },
    async getPlanIdForUpdate() {
      try {
        const response = await this.$post('customer-profile/subscriptions/' + this.subscription.uuid + '/get-or-create-plan', {
          data: {
            method: 'paypal',
            reason: this.reason
          },
          _fluentCart_rest_nonce: window.fluentCartRestVars.rest.nonce
        });

        if (!response || !response.plan || !response.plan.id) {
          this.errorMessage = translate('Invalid plan ID response!');
          this.handleError(this.errorMessage);
        }
        return response;
      } catch (error) {
        this.errorMessage = error?.message || 'Error in getPlanId';
        this.handleError(this.errorMessage);
        throw error; // Re-throw to be caught by the caller
      }
    },
    async confirmNewPaypalSubscriptionSwitch(newPaypalSubscriptionId, vendorOrderId) {
      this.loading = true;
      this.$post('customer-profile/subscriptions/' + this.subscription.uuid + '/confirm-subscription-switch', {
        data: {
          newVendorSubscriptionId: newPaypalSubscriptionId,
          vendorOrderId: vendorOrderId,
          method: 'paypal'
        },
        _fluentCart_rest_nonce: window.fluentCartRestVars.rest.nonce
      }).then(response => {
        if (response?.status === 'success') {
          this.handleSuccess(translate('PayPal subscription updated successfully'));
          this.loading = false;
          this.$emit('close');
          this.$emit('fetchSubscription');
        } else {
          if (response?.message) {
            this.errorMessage = response?.message;
          }
          this.loading = false;
          this.handleError(this.errorMessage);
        }
      }).catch(error => {
        this.errorMessage = error?.message || translate('Error in confirming new paypal subscription');
        this.handleError(this.errorMessage);
        this.loading = false;
        this.$emit('close');
      });
    },

    async loadPaypalButtons() {
      const container = this.$refs.paypalButtonContainer;
      let planId = null;
      let newVendorSubscriptionId = null;
      let vendorOrderId = null;
      container.innerHTML = '';
      let that = this;
      try {
        const buttons = window.paypal.Buttons({
          style: {
            shape: 'pill',
            layout: 'vertical',
            label: 'paypal',
            size: 'responsive',
            disableMaxWidth: true
          },
          createSubscription: async function (data, actions) {
            return actions.subscription.create({
              'plan_id': planId,
              'custom_id': that.subscription.uuid
            });
          },
          // update payment method
          onApprove: function (data, actions) {
            if (data.subscriptionID) {
              newVendorSubscriptionId = data.subscriptionID;
              vendorOrderId = data.orderID;
              // if (that.updateMethod) {
                that.confirmNewPaypalSubscriptionSwitch(newVendorSubscriptionId, vendorOrderId);
              // }
              // else {
              //   that.confirmNewPaypalSubscriptionReactivation(newVendorSubscriptionId, vendorOrderId);
              // }
            }
          },
          onClick: async function (data, actions) {
            try {
              const response = await that.getPlanId();
              if (!response || !response.plan || !response.plan.id) {
                return actions.reject();
              }
              planId = response.plan.id;
            } catch (error) {
              return actions.reject();
            }
          },
          onError: (err) => {
            this.paymentLoader?.changeLoaderStatus('error');
            this.paymentLoader?.hideLoader();
            alert('PayPal error: ' + err.toString());
          }

        });

        await buttons.render(container);
      } catch (err) {
        console.error('PayPal Buttons failed to render:', err);
      } finally {
        this.loading = false;
      }

    }
  },
  mounted() {
    this.$nextTick(() => {
      this.loadPaypal();
    });
    const primary = this.getBillingAddresses?.find(addr => addr.is_primary);
    if (primary) {
      this.selectedAddress = primary.id;
    }
    if (this.updateMethod) {
      this.reason = 'update_payment_method'; // changing paypal account
    }

    // Note: right now it is not needed, because we are not allowing to update payment method in paypal from the frontend
    // this.updatePaymentUrl = `https://www.sandbox.paypal.com/myaccount/autopay/connect/${this.subscription.vendor_subscription_id}/funding`;
  },
}
</script>

<template>
  <div role="region" aria-labelledby="paypal-section-heading">
    <h2 id="paypal-section-heading" class="sr-only">{{ translate('PayPal Payment') }}</h2>

    <div>
      <div id="paypal-button-container" ref="paypalButtonContainer" v-loading="loading" aria-live="polite"
           :aria-label="translate('PayPal payment button')"></div>
    </div>
    <div class="error-message" v-if="errorMessage" role="alert"
         aria-live="assertive">
      {{ errorMessage }}
    </div>
    <div class="success-message" v-if="successMessage" role="status"
         aria-live="polite">
      {{ successMessage }}
    </div>
    <div class="dialog-footer">
      <el-button size="small" @click="closeUpdatePaymentModal" :aria-label="translate('Cancel')">{{ translate('Cancel') }}</el-button>
      <!-- <a :href="updatePaymentUrl" target="_blank" v-if="this.subscription?.current_payment_method == 'paypal'">
          <el-button type="primary">Update in PayPal</el-button>
      </a> -->
    </div>
  </div>
</template>


<style scoped>
.dialog-footer {
  display: flex;
  justify-content: flex-end;
  margin-top: 10px;
  gap: 10px;
}

.error-message {
  color: red;
  margin-top: 10px;
}
.success-message {
  color: #67c23a;
  margin-top: 10px;
}
</style>
