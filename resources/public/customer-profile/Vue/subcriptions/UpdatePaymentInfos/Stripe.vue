<template>
    <div role="form" aria-labelledby="stripe-payment-heading">
        <h2 id="stripe-payment-heading" class="sr-only">{{ translate('Stripe Payment Method') }}</h2>

        <div class="stripe-card-form" v-loading="loading">
            <div class="form-group">
                <label>{{ translate('Card Number') }}</label>
                <div id="card-number" class="stripe-element" aria-describedby="card-errors"></div>
            </div>

            <!-- Expiry and CVC (Side by Side) -->
            <div class="form-row col-2">
                <div class="form-group">
                    <label for="card-expiry">{{ translate('MM/YY') }}</label>
                    <div id="card-expiry" class="stripe-element" aria-describedby="card-errors"></div>
                </div>
                <div class="form-group">
                    <label for="card-cvc">{{ translate('CVC') }}</label>
                    <div id="card-cvc" class="stripe-element" aria-describedby="card-errors"></div>
                </div>
            </div>

            <div id="card-errors" class="sr-only" role="alert" aria-live="assertive"></div>
        </div>

        <!-- Rate limit notice -->
        <div v-if="showRateLimitNote && remainingAttempts >= 0" class="rate-limit-notice" :class="{ 'rate-limit-notice-warning': remainingAttempts === 0 }" role="note">
            <p class="rate-limit-text" v-if="remainingAttempts > 0">
                {{ translate('You can update your card') }} 
                <strong>{{ remainingAttempts }}</strong> 
                {{ translate('more time') }}{{ remainingAttempts !== 1 ? 's' : '' }} 
                {{ translate('today') }}.
            </p>
            <p class="rate-limit-text" v-else>
                {{ translate('You have reached your 24 hours limit for updating card. Please try again tomorrow.') }}
            </p>
        </div>

        <div class="fct-update-payment-form-item">
            <h3 class="form-heading">{{ translate('Billing Address') }}</h3>
            <div class="form-input-container">
                <template v-if="getBillingAddresses.length">
                    <label for="billing-select" class="sr-only">
                        {{ translate('Select billing address') }}
                    </label>

                    <el-select
                        id="billing-select"
                        v-model="selectedAddress"
                        :placeholder="translate('Select')"
                        aria-describedby="billing-help"
                    >
                        <el-option
                            v-for="address in getBillingAddresses"
                            :key="address.id"
                            :label="getAddress(address.formatted_address)"
                            :value="address.id"
                        />
                    </el-select>

                    <small id="billing-help" class="sr-only">
                        {{ translate('Choose an existing billing address or add a new one in profile settings.') }}
                    </small>
                </template>
                <span v-else>
                    <span class="text-red-500">
                        {{ translate('No billing address found,') }}
                    </span> 

                    {{ translate('please add a new address from') }} 

                    <span
                        class="cursor-pointer text-system-dark underline font-medium"
                        @click="$router.push({ name: 'profile' })"
                        role="link"
                        tabindex="0"
                    >
                        {{ translate('here') }}
                    </span>
                </span>

            </div>
        </div>

        <!-- error message div -->
        <div class="error-message" v-if="errorMessage" role="alert" aria-live="assertive">
            {{ errorMessage }}
        </div>

        <div class="success-message" v-if="successMessage" role="alert" aria-live="assertive">
            {{ successMessage }}
        </div>

        <div class="dialog-footer">
            <el-button size="small" @click="closeUpdatePaymentModal" :aria-label="translate('Cancel')">{{ translate('Cancel') }}</el-button>
            <el-button 
                size="small" 
                type="primary" 
                @click="processNewPaymentMethod" 
                :loading="updating || switching"
                :disabled="updating || switching || invalidCard"
                :aria-disabled="updating || switching || invalidCard"
                :aria-label="updateMethod ? translate('Update') : translate('Reactivate')"
            >
                {{ updateMethod ? translate('Update') : translate('Reactivate') }}
            </el-button>
        </div>
    </div>
</template>

<script type="text/babel">
import InputField from "../../parts/InputField.vue";
import SelectField from "../../parts/SelectField.vue";
import ArrowDown from "@/Bits/Components/Icons/ArrowDown.vue";
import ArrowUp from "@/Bits/Components/Icons/ArrowUp.vue";
import {loadStripeScript} from '@/utils/stripeLoader';
import translate from "../../../translator/Translator";

export default {
    components: {
        InputField,
        SelectField,
        ArrowDown,
        ArrowUp
    },
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
    data() {
        return {
            currentPaymentMethod: '',
            cardNumber: null,
            cardExpiry: null,
            cardCvc: null,
            selectedAddress: '',
            updating: false,
            switching: false,
            reactivating: false,
            invalidCard: false,
            elementStates: {
                cardNumber: false,
                cardExpiry: false,
                cardCvc: false
            },
            elementStyle: {
                base: {
                    fontSize: '16px',
                    color: '#32325d',
                    '::placeholder': {
                        color: '#aab7c4',
                    },
                },
                invalid: {
                    color: '#fa755a',
                }
            },
            editing: true,
            stripe: null,
            stripePubKey: window.fluentcart_customer_profile_vars?.stripe_pub_key,// Ensure countryList is included in data
            loading: false,
            errorMessage: '',
            successMessage: '',
            remainingAttempts: 0,
            showRateLimitNote: false
        };
    },
    computed: {
        getBillingAddresses() {
            return this.subscription.billing_addresses || [{}];
        },
    },
    methods: {
        translate,
        handleElementChange(event) {
            this.elementStates[event.elementType] = event.complete;
            // Check if ALL elements are complete
            this.invalidCard = !(
                this.elementStates.cardNumber &&
                this.elementStates.cardExpiry &&
                this.elementStates.cardCvc
            );
        },
        async loadAndMountStripeElements() {
            this.loading = true;
            let Stripe = null;
            try {
                Stripe = await loadStripeScript();
            } catch (error) {
                this.handleError(error?.message || translate('Error in loading stripe'));
                this.loading = false;
            }

            this.stripe = Stripe(this.stripePubKey);
            const elements = this.stripe.elements();
            // Custom style with minimal borders

            this.cardNumber = elements.create('cardNumber', {
                style: this.elementStyle,
                showIcon: true,
            });
            this.cardNumber.mount('#card-number');

            this.cardExpiry = elements.create('cardExpiry', {
                style: this.elementStyle
            });
            this.cardExpiry.mount('#card-expiry');

            this.cardCvc = elements.create('cardCvc', {
                style: this.elementStyle
            });
            this.cardCvc.mount('#card-cvc');

            this.loading = false;

            // Add change listeners
            this.cardNumber.on('change', this.handleElementChange);
            this.cardExpiry.on('change', this.handleElementChange);
            this.cardCvc.on('change', this.handleElementChange);
        },
        processNewPaymentMethod() {
            this.save();
        },
        async save() {
            this.updating = true;
            const paymentMethod = await this.createPaymentMethod();
            if (paymentMethod && this.currentPaymentMethod == 'stripe') {
                this.updatePaymentMethodInStripe(paymentMethod, 'verify');
            } else if (paymentMethod && this.currentPaymentMethod != 'stripe') {
                this.switchPaymentMethod(paymentMethod, 'verify');
            }
        },
        async switchPaymentMethod(pm, verification_status = 'verify', customer_id = '') {
            this.switching = true;
            this.errorMessage = '';
            try {
                const response = await this.$post('customer-profile/subscriptions/' + this.subscription?.uuid + '/switch-payment-method', {
                    data: {
                        newPaymentMethod: 'stripe',
                        currentPaymentMethod: this.currentPaymentMethod,
                        vendorPaymentMethod: pm,
                        verification_status: verification_status,
                        customer_id: customer_id
                    },
                    _fluentCart_rest_nonce: window.fluentCartRestVars.rest.nonce
                });

                if (response?.status === 'requires_action') {
                    const {setupIntent, error} = await this.stripe.confirmCardSetup(response?.client_secret, {
                        payment_method: pm.id
                    });
                    if (error) {
                        this.errorMessage = error.message || translate('Card verification failed');
                        this.switching = false;
                        return;
                    }
                    if (setupIntent.status === 'succeeded') {
                        await this.switchPaymentMethod(pm, 'verified', response?.customer_id);
                    } else {
                        this.errorMessage = translate('Card verification failed');
                        this.switching = false;
                    }
                } else if (response?.status === 'failed') {
                    this.resetData();
                    this.errorMessage = response?.message || translate('Card verification failed');
                    this.switching = false;
                    this.handleError(this.errorMessage);
                } else if (response?.status === 'success') {
                    this.resetData();
                    this.handleSuccess(translate("Payment method updated Successfully!"));
                    this.successMessage = translate("Payment method updated Successfully!");
                    this.closeUpdatePaymentModal();
                    this.$emit('fetchSubscription');
                } else {
                    this.resetData();
                    this.errorMessage = response?.message || translate('Something went wrong');
                    this.handleError(error?.message || translate('Error in switching payment method'));
                }
            } catch (error) {
                this.resetData();
                this.errorMessage = error?.message || translate('Something went wrong');
                this.handleError(error?.message || translate('Error in switching payment method'));
                this.switching = false;
            } finally {
                this.switching = false;
            }
        },

        async createPaymentMethod() {
            const billingAddress = this.getBillingAddresses.find(addr => addr.id == this.selectedAddress);
            try {
                // Step 1: Create PaymentMethod
                const {paymentMethod, error} = await this.stripe.createPaymentMethod({
                    type: 'card',
                    card: this.cardNumber, // Only need cardNumber element
                    billing_details: {
                        name: billingAddress.name,
                        email: billingAddress.email,
                        phone: billingAddress.phone,
                        address: {
                            country: billingAddress.country,
                            line1: billingAddress.address_1,
                            line2: billingAddress.address_2,
                            city: billingAddress.city,
                            state: billingAddress.state,
                            postal_code: billingAddress.postcode
                        }
                    }
                });

                return paymentMethod;
            } catch (err) {
                this.errorMessage = err?.message || translate('Error in creating payment method');
                this.resetData();
                this.handleError(this.errorMessage);

            }
        },
        async updatePaymentMethodInStripe(pm, verification_status = 'verify') {
            this.updating = true;
            this.errorMessage = '';
            try {
              console.log('update...payment method', this.updateMethod, this.subscription);
                const response = await this.$post(`customer-profile/subscriptions/${this.subscription.uuid}/update-payment-method`, {
                    data: {
                        newPaymentMethod: pm,
                        method: 'stripe',
                        vendor_subscription_id: this.subscription.vendor_subscription_id,
                        verification_status: verification_status
                    },
                    _fluentCart_rest_nonce: window.fluentCartRestVars.rest.nonce
                });

                if (response?.status === 'requires_action') {
                    // Handle 3D Secure authentication
                    const {setupIntent, error} = await this.stripe.confirmCardSetup(response?.client_secret, {
                        payment_method: pm.id
                    });

                    if (error) {
                        this.errorMessage = error.message || translate('Card verification failed');
                        this.updating = false;
                        return;
                    }

                    if (setupIntent.status === 'succeeded') {
                        // Re-call the backend to finalize the update
                        await this.updatePaymentMethodInStripe(pm, 'verified');
                    } else {
                        this.errorMessage = translate('Card verification failed');
                        this.updating = false;
                    }
                } else if (response?.status === 'failed') {
                    this.errorMessage = response?.message || translate('Card verification failed');
                    this.resetData();
                    this.handleError(this.errorMessage);
                } else if (response?.status === 'success') {
                    this.handleSuccess(translate("Payment method updated Successfully!"));
                    this.resetData();
                    this.closeUpdatePaymentModal();
                    this.$emit('fetchSubscription');
                } else {
                    this.errorMessage = response?.message || translate('Something went wrong');
                    this.resetData();
                    this.handleError(error?.message || translate('Error in updating payment method'));
                }

            } catch (error) {
                this.errorMessage = error?.message || translate('Something went wrong');
                // this.resetData();
                this.handleError(error?.message || translate('Error in updating payment method'));
                this.updating = false;
            }
        },
        resetData() {
            this.cardNumber.clear();
            this.cardExpiry.clear();
            this.cardCvc.clear();
            this.updating = false;
            this.switching = false;
            this.reactivating = false;
            this.editing = false;
            this.errorMessage = '';
        },
        closeUpdatePaymentModal() {
            this.resetData();
            this.$emit('close');
        },
        async fetchRemainingAttempts() {
            // Only fetch if current payment method is stripe
            if (this.currentPaymentMethod === 'stripe') {
                try {
                    const response = await this.$get(`customer-profile/subscriptions/${this.subscription.uuid}/setup-intent-attempts`);
                    // Response structure: { remaining: 3 } (direct key from sendSuccess)
                    if (response?.remaining !== undefined && response?.remaining !== null) {
                        this.remainingAttempts = response.remaining;
                        this.showRateLimitNote = true;
                    }
                } catch (error) {
                    // Silently fail if we can't fetch rate limit info
                    console.error('Failed to fetch rate limit info', error);
                }
            }
        }
    },
    mounted() {
        this.$nextTick(() => {
            this.loadAndMountStripeElements()
        });
        console.log('update...payment method mounted', this.updateMethod);
        this.invalidCard = true;
        this.currentPaymentMethod = this.subscription?.current_payment_method;
        const primary = this.getBillingAddresses?.find(addr => addr.is_primary);
        if (primary) {
            this.selectedAddress = primary.id;
        }
        // Fetch remaining attempts if applicable
        this.fetchRemainingAttempts();
    }
};
</script>



<style scoped>
.dialog-footer {
    display: flex;
    justify-content: flex-end;
    gap: 10px;
    margin-top: 10px;
}

.stripe-card-form {
    width: 100%;
    margin: 0 auto;
    font-family: sans-serif;
}

.stripe-element {
    border: 1px solid #ddd;
    border-radius: 4px;
    padding: 12px 15px;
    background: white;
}

.stripe-element--focus {
    border-color: #666;
    outline: none;
}

.error-message {
    color: #f56c6c;
    margin-top: 10px;
}

.success-message {
    color: #67c23a;
    margin-top: 10px;
}

.rate-limit-notice {
    background-color: #f0f9ff;
    border: 1px solid #bae6fd;
    border-radius: 4px;
    padding: 12px 15px;
    margin-bottom: 20px;
}

.rate-limit-notice-warning {
    background-color: #fef3c7;
    border: 1px solid #fcd34d;
}

.rate-limit-text {
    margin: 0;
    color: #0369a1;
    font-size: 14px;
}

.rate-limit-notice-warning .rate-limit-text {
    color: #92400e;
}

.rate-limit-text strong {
    font-weight: 600;
}
</style>
  
