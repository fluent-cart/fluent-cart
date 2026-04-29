<template>
    <div class="fct-early-installment-payment">
        <el-button type="primary" @click="showModal = true" :aria-label="$t('Pay Remaining Installments')">
            {{ $t('Pay Remaining Installments') }}
        </el-button>

        <el-dialog
            v-model="showModal"
            :append-to-body="true"
            :title="$t('Complete Installment Payment')"
            class="fct-early-payment-dialog fluent-cart-customer-profile-app"
            role="dialog"
            aria-modal="true"
        >
            <div class="fct-early-payment-content">
                <div class="fct-installment-progress mb-4">
                    <div class="flex justify-between items-center mb-2">
                        <span class="text-sm text-system-mid">{{ $t('Installment Progress') }}</span>
                        <span class="text-sm font-medium">
                            {{ subscription.bill_count }} / {{ subscription.bill_times }}
                        </span>
                    </div>
                    <el-progress
                        :percentage="Math.round((subscription.bill_count / subscription.bill_times) * 100)"
                        :show-text="false"
                        :stroke-width="8"
                    />
                </div>

                <div class="fct-installment-summary p-3 rounded" style="background: var(--fct-bg-secondary, #f5f7fa);">
                    <div class="flex justify-between items-center mb-2">
                        <span class="text-sm text-system-mid">{{ $t('Remaining installments') }}</span>
                        <span class="text-sm font-medium">{{ subscription.remaining_installments }}</span>
                    </div>
                    <div class="flex justify-between items-center mb-2">
                        <span class="text-sm text-system-mid">{{ $t('Per installment') }}</span>
                        <span class="text-sm">{{ formatNumber(subscription.recurring_amount) }}</span>
                    </div>
                    <div class="flex justify-between items-center pt-2" style="border-top: 1px solid var(--fct-border-color, #e4e7ed);">
                        <span class="text-sm font-semibold">{{ $t('Total to pay now') }}</span>
                        <span class="text-base font-semibold">{{ formatNumber(totalAmount) }}</span>
                    </div>
                </div>
            </div>

            <template #footer>
                <el-button @click="showModal = false">{{ $t('Cancel') }}</el-button>
                <el-button type="primary" :loading="loading" @click="proceedToCheckout">
                    {{ $t('Proceed to Payment') }}
                </el-button>
            </template>
        </el-dialog>
    </div>
</template>

<script type="text/babel">
export default {
    name: 'EarlyInstallmentPayment',
    props: {
        subscription: {
            type: Object,
            required: true
        }
    },
    data() {
        return {
            showModal: false,
            loading: false
        };
    },
    computed: {
        totalAmount() {
            return this.subscription.recurring_amount * this.subscription.remaining_installments;
        }
    },
    methods: {
        proceedToCheckout() {
            this.loading = true;
            this.$post(`customer-profile/subscriptions/${this.subscription.uuid}/initiate-early-payment`)
                .then((response) => {
                    window.location.href = response.checkout_url;
                })
                .catch((error) => {
                    this.handleError(error);
                })
                .finally(() => {
                    this.loading = false;
                });
        }
    }
};
</script>
