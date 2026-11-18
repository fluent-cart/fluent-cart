<template>
    <div class="fct-upgrade-plan">
        <fct-button :type="buttonType" size="small" @click="showUpgradePlanModal" :aria-label="buttonText">
            {{ buttonText }}
        </fct-button>

        <fct-dialog
            v-model="upgradePlanModal"
            :append-to-body="true"
            :title="$t('Upgrade Options')"
            class="fct-upgrade-plan-dialog fluent-cart-customer-profile-app"
        >
        <h2 id="upgrade-dialog-title" class="sr-only">{{ $t('Upgrade Options') }}</h2>

            <div v-if="upgradePaths.length > 0" class="fct-upgrade-variation-table pb-10">
                <fct-table :data="upgradePaths" :aria-label="translate('Upgrade options table')">
                    <fct-table-column :label="translate('Package')" :width="290">
                        <template #default="scope">
                            <div class="upgrade-title">{{ scope.row.title }}</div>
                        </template>
                    </fct-table-column>
                  <fct-table-column :label="translate('Price')" :width="100">
                    <template #default="scope">
                      <div class="plan-price">
                        <div class="plan-price" v-if="scope.row.is_available !== false">
                          {{ formatNumber(scope.row.original_price, true, false, scope.row.currency) }}
                        </div>
                        <div class="plan-price" v-else>&mdash;</div>
                      </div>
                    </template>
                  </fct-table-column>
                    <fct-table-column :label="translate('Amount to pay')" :width="200">
                        <template #default="scope">
                            <div class="upgrade-cost">
                                <div class="upgrade-title">
                                    <span v-if="scope.row.is_available === false">{{ scope.row.unavailable_reason }}</span>
                                    <span v-else v-html="scope.row.payment_summary"></span>
                                </div>
                            </div>
                        </template>
                    </fct-table-column>

                    <fct-table-column :label="translate('Action')" :width="110">
                        <template #default="scope">
                            <div class="upgrade-action">
                                <fct-button
                                    v-if="scope.row.is_available !== false"
                                    class="el-button--x-small" 
                                    tag="a" 
                                    :href="scope.row.upgrade_url"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    :aria-label="$t('Upgrade to') + ' ' + scope.row.title"
                                >
                                    {{ $t('Upgrade') }}
                                </fct-button>
                            </div>
                        </template>
                    </fct-table-column>
                </fct-table>
            </div>
            <div v-else-if="loading" class="p-5" role="status" aria-live="polite">
                <fct-skeleton :animated="true" :rows="2"/>
            </div>
            <div v-else class="p-5" role="alert" aria-live="assertive">
                {{ translate('No upgrade found for this purchase') }}
            </div>
        </fct-dialog>
    </div>
</template>

<script type="text/babel">
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";
import translate from "../../../translator/Translator";

export default {
    name: 'UpgradePlan',
    components: {
        DynamicIcon
    },
    props: {
        variation_id: {
            type: [String, Number],
            required: true
        },
        order_hash: {
            type: String,
            required: true
        },
        buttonText: {
            type: String,
            default: 'Upgrade'
        },
        buttonType: {
            type: String,
            default: 'default'
        },
    },
    data() {
        return {
            upgradePlanModal: false,
            selectedUpgradePath: '',
            upgradePaths: [],
            loading: false,
        }
    },
    computed: {
        currencySign() {
            return this.appVars.shop.currency_sign;
        }
    },
    methods: {
        translate,
        showUpgradePlanModal() {
            this.fetchPaths();
            this.upgradePlanModal = true;
        },
        fetchPaths() {
            this.loading = true;
            // call the rest api to get upgrade-paths
            this.$get(`customer-profile/orders/${this.order_hash}/upgrade-paths`, {
                variation_id: this.variation_id
            })
                .then((response) => {
                    this.upgradePaths = response.upgradePaths;
                })
                .catch((error) => {
                    this.handleError(error);
                })
                .finally(() => {
                    this.loading = false;
                });
        },
        closeUpgradePlanModal() {
            this.upgradePlanModal = false;
        }
    },
}
</script>
