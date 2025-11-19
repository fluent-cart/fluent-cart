<template>
    <div class="fct-upgrade-plan">
        <el-button :type="buttonType" size="small" @click="showUpgradePlanModal" :aria-label="buttonText">
            {{ buttonText }}
        </el-button>

        <el-dialog
            v-model="upgradePlanModal"
            :append-to-body="true"
            :title="$t('Upgrade Options')"
            class="fct-upgrade-plan-dialog fluent-cart-customer-profile-app"
            role="dialog"
            aria-modal="true"
            aria-labelledby="upgrade-dialog-title"
        >
        <h2 id="upgrade-dialog-title" class="sr-only">{{ $t('Upgrade Options') }}</h2>

            <div v-if="upgradePaths.length > 0" class="fct-upgrade-variation-table pb-10">
                <el-table :data="upgradePaths" :aria-label="translate('Upgrade options table')">
                    <el-table-column :label="translate('Package')" :width="290" :aria-label="translate('Package')">
                        <template #default="scope">
                            <div class="upgrade-title">{{ scope.row.title }}</div>
                        </template>
                    </el-table-column>
                  <el-table-column :label="translate('Price')" :width="100" :aria-label="translate('Price')">
                    <template #default="scope">
                      <div class="plan-price">
                        <div class="plan-price">
                          {{ formatNumber(scope.row.original_price, scope.row.currency) }}
                        </div>
                      </div>
                    </template>
                  </el-table-column>
                    <el-table-column :label="translate('Amount to pay')" :width="200" :aria-label="translate('Amount to pay')">
                        <template #default="scope">
                            <div class="upgrade-cost">
                                <div class="upgrade-title">
                                    <span v-html="scope.row.payment_summary"></span>
                                </div>
                            </div>
                        </template>
                    </el-table-column>

                    <el-table-column :label="translate('Action')" :width="110" :aria-label="translate('Action')">
                        <template #default="scope">
                            <div class="upgrade-action">
                                <el-button 
                                    class="el-button--x-small" 
                                    tag="a" 
                                    :href="scope.row.upgrade_url"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    :aria-label="$t('Upgrade to') + ' ' + scope.row.title"
                                >
                                    {{ $t('Upgrade') }}
                                </el-button>
                            </div>
                        </template>
                    </el-table-column>
                </el-table>
            </div>
            <div v-else-if="loading" role="status" aria-live="polite">
                <el-skeleton :animated="true" :rows="2"/>
            </div>
            <div v-else role="alert" aria-live="assertive">
                {{ translate('No upgrade found for this purchase') }}
            </div>
        </el-dialog>
    </div>
</template>

<script type="text/babel">
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";
import CopyToClipboard from "@/Bits/Components/CopyToClipboard.vue";
import translate from "../../../translator/Translator";

export default {
    name: 'UpgradePlan',
    components: {
        CopyToClipboard,
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
