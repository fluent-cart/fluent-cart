<template>
    <div>
        <fct-button
            v-if="updateMethod"
            @click="showUpdatePaymentModal"
            text
            class="underline-link-button"
            :aria-label="buttonText"
        >
            {{buttonText}}
        </fct-button>

        <fct-button
            v-else @click="showUpdatePaymentModal"
            text
            class="underline-link-button"
            :aria-label="translate('Reactivate')"
        >
            {{ translate('Reactivate') }}
        </fct-button>

        <fct-dialog
            v-model="updatePaymentModal"
            :title="translate('Update Payment')"
            aria-describedby="update-payment-description"
        >
            <h2 id="update-payment-title" class="sr-only">{{ translate('Update Payment') }}</h2>

            <div class="fct-update-payment-method">
                <div class="fct-update-payment-method-header">
                    <fct-choice
                        type="radio"
                        border
                        class="fct-update-payment-method-radio"
                        v-model="selectedPaymentMethod"
                        :aria-label="translate('Select payment method')"
                        :options="filteredPaymentMethods.map((option) => ({label: option.title, value: option.slug, logo: option.logo, ariaLabel: `${option.title} payment method`}))"
                    >
                        <template #option="{option}">
                            <img :src="option.logo" :alt="`${option.label} logo`" :class="option.value" :title="option.label"/>
                            {{ option.label }}
                        </template>
                    </fct-choice>
                </div>

                <div id="update-payment-description" class="sr-only">
                    {{ translate('Update your payment method to continue with the subscription.') }}
                </div>

                <div class="fct-update-payment-method-body">
                    <div class="fct-update-payment-method-form">
                        <div v-if="selectedPaymentMethod == 'stripe'" class="fct-update-payment-method-stripe">
                            <Stripe :subscription="subscription" @close="closeUpdatePaymentModal"
                                    @fetch-subscription="fetchSubscription" :updateMethod="updateMethod"/>
                        </div>
                        <div v-if="selectedPaymentMethod == 'paypal'" class="fct-update-payment-method-paypal">
                            <Paypal :subscription="subscription" @close="closeUpdatePaymentModal"
                                    @fetch-subscription="fetchSubscription" :updateMethod="updateMethod"/>
                        </div>
                        <div v-if="subscription.card_update_url && !['stripe', 'paypal'].includes(selectedPaymentMethod)" class="fct-update-payment-method-redirect">
                            <p>{{ translate('You will be redirected to complete the update.') }}</p>
                            <fct-button type="primary" @click="openCardUpdateUrl">
                                {{ translate('Continue') }}
                            </fct-button>
                        </div>
                    </div>
                </div>
            </div>

        </fct-dialog>
    </div>
</template>

<script type="text/babel">
import InputField from "../../parts/InputField.vue";
import Stripe from './Stripe.vue';
import Paypal from './Paypal.vue';
import translate from "../../../translator/Translator";

export default {
    name: 'UpdatePaymentInfos',
    components: {
        InputField,
        Stripe,
        Paypal,
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
        },
        buttonText: {
            type: String,
            default: 'Update Payment Method'
        }
    },
    data() {
        return {
            updatePaymentModal: false,
            selectedPaymentMethod: this.subscription.current_payment_method,
            selectedAddress: '',
            stripePubKey: window.fluentcart_customer_profile_vars.stripe_pub_key,
            stripe: null,
            cardNumber: null,
            cardExpiry: null,
            cardCvc: null,
            loading: false,
            error: '',
            updating: false,
            invalidInput: false
        }
    },
    computed: {
        getAllPaymentMethods() {
            return window.fluentcart_customer_profile_vars?.payment_methods || [];
        },
        filteredPaymentMethods() {
          if (this.subscription.can_switch_payment_method === true) {
            return (this.getAllPaymentMethods || []).filter(
                method => {
                    return this.subscription?.switchable_payment_methods?.includes(method.slug) || method.slug === this.subscription.current_payment_method;
                }
            );
          }

          return (this.getAllPaymentMethods || []).filter(method => method.slug === this.subscription.current_payment_method);
        },
    },
    mounted() {

    },
    methods: {
        translate,
        showUpdatePaymentModal() {
            this.updatePaymentModal = true;
        },
        closeUpdatePaymentModal() {
            this.updatePaymentModal = false;
        },
        openCardUpdateUrl() {
            window.open(this.subscription.card_update_url, '_blank');
        },
        fetchSubscription() {
            this.$emit('fetch');
        }
    }
}
</script>
