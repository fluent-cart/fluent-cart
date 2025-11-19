<template>
    <div class="fct-transaction-details -mt-[1px]" v-if="transactions.length > 0">

        <div class="fct-table-wrap">
            <el-table :data="transactions" class="w-full">
                <el-table-column :label="translate('# Date')" width="auto">
                    <template #default="scope">
                        <div>
                              <p class="p-0 m-0 text-xs text-gray-500">#{{ translateNumber(scope.row.id) }}</p>
                              <ConvertedTime :date-time="scope.row.created_at"/>
                        </div>
                    </template>
                </el-table-column>
                <el-table-column :label="translate('Gateway ID')" width="160">
                    <template #default="scope">
                        <span v-if="scope.row.vendor_charge_id == ''">--</span>
                        <a v-else class="text-primary-500 dark:text-gray-50 link" target="_blank" :href="scope.row.url">
                            {{ scope.row.vendor_charge_id }}
                        </a>
                    </template>
                </el-table-column>
                <el-table-column :label="translate('Payment Method')" width="140">
                    <template #default="scope">
                        <div class="flex items-center gap-2.5">
                            <div class="w-7" v-if="getCardBrand(scope.row.card_brand, scope.row.payment_method_type)">
                                <img class="w-full" :title="scope.row.payment_method" :src="getCardBrand(scope.row.card_brand, scope.row.payment_method_type)" :alt="scope.row.card_brand">
                            </div>

                            <span v-else class="capitalize">{{scope.row.payment_method_type}}</span>

                            <span v-if="scope.row.card_last_4 && scope.row.card_last_4 != '0'">
                              {{ '...' + scope.row.card_last_4 }}
                            </span>
                        </div>
                    </template>
                </el-table-column>
                <el-table-column :label="translate('Total')" width="auto">
                    <template #default="scope">
                        <span>{{ formatNumber(scope.row.total) }}</span>
                    </template>
                </el-table-column>

                <el-table-column :label="translate('Status')" width="120">
                    <template #default="scope">
                        <el-tooltip v-if="scope.row.transaction_type == 'dispute'"
                            effect="dark"
                            :placement="'top'"
                            :content="'Transaction dispute, please resolve it from action menu. Reason: ' + scope.row?.meta?.dispute_reason"
                            popper-class="fct-label-hint-popover fct-tooltip"
                        >
                        <Badge class="m-2 b-red"
                            status="dispute">
                            Dispute
                        </Badge>
                        </el-tooltip>
                        <el-tooltip
                            v-if="scope.row?.meta?.reason"
                            effect="dark"
                            :placement="'top'"
                            :content="translate(scope.row?.meta?.reason)"
                            popper-class="fct-label-hint-popover fct-tooltip"
                        >
                            <Badge
                                v-if="scope.row?.meta?.disputed == 'yes' && scope.row?.meta?.dispute_resolved == 'no'"
                                :status="(scope.row.status)">
                                {{ scope.row.status }}
                                {{ scope?.row?.transaction_type }}
                            </Badge>
                            <Badge v-else :status="(scope.row.status)">
                                {{ scope.row.status }}
                            </Badge>
                        </el-tooltip>
                        <Badge v-else :status="(scope.row.status)">
                            {{ scope.row.status }}
                        </Badge>
                    </template>
                </el-table-column>
                <el-table-column v-if="hasDisputedTransaction(transactions)" :label="translate('Actions')" width="140">
                    <template #default="scope">
                        <el-button type="warning" v-if="scope.row.transaction_type == 'dispute' && scope.row.status == 'succeeded'" size="small" @click="handleDispute(scope.row)">
                            {{ translate('Handle dispute') }}
                        </el-button>
                    </template>
                </el-table-column>
            </el-table>
        </div>

        <!-- dispute handle modal -->
        <el-dialog v-model="disputeHandleModal"
        :title="'Handle Dispute #' + disputeTransaction?.meta?.dispute_id" width="800px">
            <el-form-item v-if="disputeTransaction?.meta?.dispute_reason">
                    <div class="text-sm">
                    Dispute Reason:  {{ disputeTransaction?.meta?.dispute_reason }}
                    </div>
                </el-form-item>    
            <el-form-item>
                <div class="text-sm text-gray-700">
                    {{ translate('You may either counter the dispute by providing evidence to prove that the cardholder is the legitimate purchaser, or you can accept this dispute immediately to refund the cardholder and close the dispute.') }}
                </div>
            </el-form-item>
            <el-form :model="disputeForm" label-position="top" v-if="disputeTransaction?.meta?.is_dispute_actionable">
                <el-form-item :label="translate('Note')" prop="dispute_note">
                    <el-input v-model="disputeForm.dispute_note" placeholder="(optional)" type="text" />
                </el-form-item>
            </el-form>
            <el-form-item v-if="disputeTransaction?.meta?.is_charge_refundable">
                <div class="text-sm flex gap-2">
                    <DynamicIcon class="w-6 h-6" name="Information"/>
                    {{ translate('This dispute has refundable action, so you may refund the charge to the customer for a dispute resolution.') }}
                </div>
            </el-form-item>
            <template #footer>
                <a :href="disputeTransaction?.url"
                    target="_blank" 
                    class="el-button el-button--warning is-link"
                >
                {{ translate('See dispute on ') + disputeTransaction?.payment_method }}
                </a>
                <el-button v-if="disputeTransaction?.meta?.is_charge_refundable" type="primary" @click="() => $emit('triggerRefundModal')" :loading="loadingDisputeAction">
                    {{ translate('Refund customer') }}
                </el-button>
                <el-button v-if="disputeTransaction?.meta?.is_dispute_actionable" type="primary" @click="handleDisputeSubmit" :loading="loadingDisputeAction">
                    {{ translate('Accept Dispute') }}
                </el-button>
            </template>
        </el-dialog>
    </div>
</template>

<script type="text/babel">
import {ArrowDown, Check} from '@element-plus/icons-vue';
import {markRaw} from 'vue';
import Badge from '@/Bits/Components/Badge.vue';
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";
import Str from "../../utils/support/Str";
import {getCardBrand} from "@/Bits/common.js";
import ConvertedTime from "@/Bits/Components/ConvertedTime.vue";
import translate, {translateNumber} from "@/utils/translator/Translator";

export default {
    name: 'Transaction',
    computed: {
        Str() {
            return Str
        }
    },
    components: {
        ConvertedTime,
        ArrowDown: markRaw(ArrowDown),
        Check,
        Badge,
        DynamicIcon
    },
    props: ['transactions', 'transaction', 'header', 'transaction_data', 'order_id'],
    emits: ['reload'],
    data() {
        return {
            //status: this.transaction.status,
            visible: false,
            doing_action: false,
            disputeHandleModal: false,
            disputeForm: {
                dispute_note: ''
            },
            disputeTransaction: null,
            loadingDisputeAction: false
        }
    },
    methods: {
        translate,
        getCardBrand,
        translateNumber,
        handleDispute(transaction) {
            this.disputeHandleModal = true;
            this.disputeTransaction = transaction;
        },
        handleDisputeSubmit(transaction) {
            this.loadingDisputeAction = true;
            this.$post('orders/' + this.order_id + '/transactions/' + this.disputeTransaction.id + '/accept-dispute', {
                dispute_note: this.disputeForm.dispute_note
            })
                .then(response => {
                    this.$notify.success(response.message);
                    this.$emit('reload');
                    this.disputeHandleModal = false;
                })
                .catch(errors => {
                    this.$notify.error(errors.message);
                })
                .finally(() => {
                    this.disputeHandleModal = false;
                    this.loadingDisputeAction = false;
                });
        },
        hasDisputedTransaction(transactions) {
            return transactions.some(transaction => transaction.transaction_type == 'dispute' && transaction.status == 'succeeded');
        }
        // changeStatus(newStatus) {
        //   if (this.status == this.transaction.status) {
        //     return;
        //   }
        //   this.doing_action = true;
        //   this.$put('orders/' + this.transaction.order_id + '/transactions/' + this.transaction.id + '/status', {
        //     status: newStatus
        //   })
        //       .then(response => {
        //         this.$notify.success(response.message);
        //         this.visible = false;
        //         this.$emit('reload');
        //       })
        //       .catch((errors) => {
        //         this.handleError(errors);
        //       })
        //       .finally(() => {
        //         this.doing_action = false;
        //       });
        // },
    }
}
</script>
<style>
span.fct_card_brand {
    padding: 2px 6px;
    background: #1185ff;
    color: white;
    border-radius: 3px;
    font-size: 13px;
    margin-right: 9px;
}
</style>
