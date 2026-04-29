<template>
    <div>
        <el-button
            v-if="updateMethod"
            @click="showUpdatePaymentModal"
            text
            class="underline-link-button"
            :aria-label="buttonText"
        >
            {{buttonText}}
        </el-button>

        <el-button
            v-else @click="showUpdatePaymentModal"
            text
            class="underline-link-button"
            :aria-label="translate('Reactivate')"
        >
            {{ translate('Reactivate') }}
        </el-button>

        <el-dialog
            v-model="updatePaymentModal"
            :title="translate('Update Payment')"
            role="dialog"
            aria-modal="true"
            aria-labelledby="update-payment-title"
            aria-describedby="update-payment-description"
        >
            <h2 id="update-payment-title" class="sr-only">{{ translate('Update Payment') }}</h2>

            <div class="fct-update-payment-method">
                <div class="fct-update-payment-method-header">
                    <label id="payment-methods-label" class="sr-only">
                        {{ translate('Select payment method') }}
                    </label>

                    <el-radio-group class="fct-update-payment-method-radio" v-model="selectedPaymentMethod" role="radiogroup" aria-labelledby="payment-methods-label">
                        <el-radio
                            v-for="(option, index) in filteredPaymentMethods"
                            :value="option.slug"
                            :key="option.slug"
                            border
                            :aria-label="`${option.title} payment method`"
                        >
                            <img :src="option.logo" :alt="`${option.title} logo`" :class="option.slug" :title="option.title"/>
                            {{ option.title }}
                        </el-radio>
                    </el-radio-group>
                </div>

                <div id="update-payment-description" class="sr-only">
                    {{ translate('Update your payment method to continue with the subscription.') }}
                </div>

                <div class="fct-update-payment-method-body">
                    <div class="fct-update-payment-method-form">
                        <div v-if="selectedPaymentMethod == 'stripe'" class="fct-update-payment-method-stripe">
                            <Stripe :subscription="subscription" @close="closeUpdatePaymentModal"
                                    @fetchSubscription="fetchSubscription" :updateMethod="updateMethod"/>
                        </div>
                        <div v-if="selectedPaymentMethod == 'paypal'" class="fct-update-payment-method-paypal">
                            <Paypal :subscription="subscription" @close="closeUpdatePaymentModal"
                                    @fetchSubscription="fetchSubscription" :updateMethod="updateMethod"/>
                        </div>
                    </div>
                </div>
            </div>

        </el-dialog>
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
        Paypal
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
    methods: {
        translate,
        showUpdatePaymentModal() {
            this.updatePaymentModal = true;
        },
        closeUpdatePaymentModal() {
            this.updatePaymentModal = false;
        },
        fetchSubscription() {
            this.$emit('fetch');
        }
    },
    mounted() {

    }
}
</script>
