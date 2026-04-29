<template>
    <div>
      <div v-if="refundNote" class="fct-refund-note-list">
        <div v-for="(item, key) in refundNote" :key="key" class="fct-refund-note-item">
          <span class="message">{{ item.message }}</span>
          <Badge :status="item.status"/>
        </div>
      </div>

      <div v-else>
        <el-form label-position="top" require-asterisk-position="right">
            <el-row :gutter="10">
                <el-col :lg="24">
                    <el-form-item :label="$t('Refund with transaction')" required="">
                        <el-select v-model="refund.transaction_id" :placeholder="$t('Select')"
                                   @change="updateMaxRefund">
                            <el-option v-for="item in refundableTransactions" :key="item.id"
                                       :label="
                                       /* translators: %1$s - transaction id, %2$s - refundable amount */
                                       translate(
                                   '#%1$s - Paid %2$s',
                                           item.id,
                                           CurrencyFormatter.formatNumber(item.refundable, true)
                                       )"
                                       :value="item.id">
                            </el-option>
                        </el-select>
                    </el-form-item>


                    <el-form-item :label="$t('Select Item/s')">
                        <el-select v-model="refund.item_ids" :placeholder="$t('Select')" multiple
                                   @change="updateMaxRefund">
                            <el-option v-for="item in order.order_items"
                                       :key="item.id"
                                       :value="item.id"
                                       :label="item.post_title + ' - ' + item.title + '  ' + CurrencyFormatter.formatNumber(item.line_total - item.refund_total)"
                                       class="refund-item"
                                       :disabled="Number(item.line_total) - Number(item.refund_total) <= 0"
                            >

                                <div class="refund-item-info">
                                    <p>
                                        {{ item.post_title }}
                                        <span>-{{ item.title }}</span>
                                    </p>
                                    <div class="right">
                                      <span class="price">
                                         {{
                                          /* translators: %s - refundable amount */
                                          translate('Refundable: %s', CurrencyFormatter.formatNumber(item.line_total - item.refund_total))
                                        }}
                                      </span>
                                      <span class="price light">
                                        {{
                                          /* translators: %s - item price */
                                          translate('Item price: %s', CurrencyFormatter.formatNumber(item.line_total))
                                        }}
                                      </span>
                                    </div>
                                </div>
                            </el-option>
                        </el-select>
                    </el-form-item>

                    <el-col :lg="24">
                        <el-form-item :label="$t('Refund amount')" required="">
                            <el-input type="number" :min="1" :max="maxRefundAmount" v-model.number="refund.amount"
                                      @input="checkMaxRefundAmount">
                                <template #prefix="">
                                    <span v-html="CurrencyFormatter.currencySign"></span>
                                </template>
                            </el-input>
                            <div class="form-note">
                                <p>{{ $t('Max refund amount for this transaction:') }}
                                    <span style="color: #F04438; font-weight: 500;">
                                      {{ CurrencyFormatter.formatNumber(maxRefundAmount * 100) }}
                                    </span>
                                </p>
                            </div>
                        </el-form-item>

                        <el-col :lg="24" v-if="isSubscriptionCancelable">
                            <el-form-item :label="$t('Subscription')">
                                <el-checkbox :label="$t('Cancel Subscription (if any)')"
                                             v-model="refund.cancelSubscription">
                                </el-checkbox>
                            </el-form-item>
                        </el-col>
                        <el-col :lg="24"
                                v-if="isAnyProductManageStock">
                            <el-form-item>
                                <el-checkbox v-model="refund.manageStock">{{
                                        $t('Restock Quantity')
                                    }}
                                </el-checkbox>
                            </el-form-item>
                        </el-col>

                        <el-col :lg="24" v-if="refund.manageStock">
                          <el-table
                              :data="refundTableItems"
                              border
                              style="width: 100%; margin-top: 15px;"
                          >
                            <el-table-column
                                prop="post_title"
                                :label="$t('Product')"
                                min-width="200"
                            >
                              <template #default="{ row }">
                                <span>{{ row.post_title }} - {{ row.title }}</span>
                              </template>
                            </el-table-column>

                            <el-table-column
                                prop="quantity"
                                :label="$t('Quantity')"
                                width="120"
                                align="center"
                            >
                              <template #default="{ row }">
                                <span>{{ row.quantity }}</span>
                              </template>
                            </el-table-column>

                            <el-table-column
                                :label="$t('Restore Quantity')"
                                width="180"
                                align="center"
                            >
                              <template #default="{ row }">
                                <el-input-number
                                    v-model="row.restore_quantity"
                                    :min="0"
                                    :max="row.quantity"
                                />
                              </template>
                            </el-table-column>
                          </el-table>
                        </el-col>

                        <el-col :lg="24">
                          <el-form-item :label="$t('Reason of refund')">
                            <el-input v-model="refund.reason"/>
                            <div class="form-note form-note-tooltip">
                              <p>
                                {{ translate('Your customers can see this reason') }}
                                <span>
                                            <el-tooltip
                                                popper-class="fct-tooltip"
                                                placement="top"
                                                :content="translate('If set, possible values are duplicate fraudulent, and requested_by_customer If you believe the charge to be fraudulent, specifying fraudulent as the reason will add the associated card and email to your block lists')"
                                            >
                                              <DynamicIcon name="InformationFill"/>
                                            </el-tooltip>
                                          </span>
                              </p>
                            </div>
                          </el-form-item>
                        </el-col>
                    </el-col>
                </el-col>
            </el-row>
        </el-form>
        <div class="dialog-footer is-border">
            <el-button type="primary" @click.prevent="processRefund" :loading="refundProcessing"
                       :disabled="refund.amount <= 0">
                {{refundProcessing ? translate('Processing') : translate('Refund')}}
              <span
                v-if="refund.amount"></span>{{ CurrencyFormatter.formatNumber(refund.amount * 100) }}
            </el-button>
        </div>
      </div>
    </div>
</template>
<script>
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";
import {formatNumber} from "@/Bits/productService";
import VariationSelector from "@/Bits/Components/VariationSelector.vue";
import translate from "@/utils/translator/Translator";
import CurrencyFormatter from "@/utils/support/CurrencyFormatter";
import Arr from "@/utils/support/Arr";
import Badge from "@/Bits/Components/Badge.vue";

export default {
    name: "Refund",
    components: {
      Badge,
        VariationSelector,
        DynamicIcon
    },
    props: ['order_id', 'order', 'is_subscription', 'subscription_status', 'showRefundModal'],
    data() {
        return {
            transactions: {},
            maxRefundAmount: '',
            refundProcessing: false,
            refundableTransactions: [],
            isShippingRefundable: false,
            refund: {
                item_ids: [],
                transaction_id: '',
                amount: 0,
                cancelSubscription: true,
                reason: '',
                availableAmount: '',
                paymentMethod: '',
                manageStock: false
            },
            totalPaid: 0,
            refundNote: ''
        }
    },
    computed: {
        CurrencyFormatter() {
            return CurrencyFormatter
        },
        isAnyProductManageStock() {
            for (const item of this.order.order_items) {
                if (item?.variants && item?.variants?.manage_stock === "1") {
                    return true;
                }
            }
            return false;
        },
        isSubscriptionCancelable() {
            return this.is_subscription && this.subscription_status !== 'canceled' && this.subscription_status !== 'expired';
        },
        checkMaxRefundAmount() {
            if (this.refund.amount > this.maxRefundAmount) {
                this.refund.amount = this.maxRefundAmount;
            }
        },
      refundTableItems() {
          let items = [];
          if (!this.refund.item_ids || this.refund.item_ids.length === 0) {
            items = this.order.order_items.filter(i => i.payment_type !== 'signup_fee');
          } else {
            items = this.order.order_items.filter(i =>
              this.refund.item_ids.includes(i.id) && i.payment_type !== 'signup_fee'
            );
          }

          return items.map(i => ({
            ...i,
            restore_quantity: i.restore_quantity || 0, // default restore qty
            refund_quantity: Number(i.refund_total) || 0 // refunded qty
          }));
        }
    },
    watch: {
      showRefundModal(val) {
        this.refundNote = '';
      },
      'order.order_items': {
        immediate: true,
        handler(items) {
          // Only preselect if there is exactly one refundable item
          const refundableItems = items.filter(item => Number(item.line_total) - Number(item.refund_total) > 0);
          if (refundableItems.length === 1) {
            this.refund.item_ids = [refundableItems[0].id];
          } else {
            this.refund.item_ids = [];
          }
        }
      }
    },
    methods: {
        translate,
        formatNumber,
        updateMaxRefund() {
            const id = this.refund.transaction_id;
            let transaction = this.refundableTransactions.find(item => item.id === id);
            this.refund.amount = transaction.refundable
                ? parseFloat(transaction.refundable / 100) : 0;

            this.refund.paymentMethod = transaction.payment_method;
            const selectedItems = Arr.whereIn(this.order.order_items, 'id', this.refund.item_ids);
            const maxRefundableAmount = selectedItems.reduce((total, item) => {
                    return total + ((item.line_total - item.refund_total) / 100);
                }, 0
            );

            if (this.refund.amount > maxRefundableAmount && selectedItems.length > 0) {
                this.refund.amount = maxRefundableAmount;
            }

            this.maxRefundAmount = this.refund.amount;
        },
        updateRefundable() {
            // Only include transactions with status 'paid'
            this.refundableTransactions = this.transactions
                .filter(item => item.status === 'succeeded')
                .map(item => {
                    return {
                        ...item,
                        refundable: item.total - (parseInt(item.meta?.refunded_total) || 0),
                    };
                });

            if (this.refundableTransactions.length !== 0) {
                this.refund.transaction_id = this.refundableTransactions[0].id;
                this.refund.payment_method = this.refundableTransactions[0].payment_method;
                this.updateMaxRefund(this.refund.transaction_id);
            }
        },
        processRefund() {
            this.refundProcessing = true;

            if (!this.refund.amount) {
                this.refundProcessing = false;
                return this.handleMessage('Please add refund amount.', 'error');
            }

            if (this.refund.amount > this.refund.availableAmount) {
                this.refundProcessing = false;
                return this.handleMessage(`Refund amount should not exceed: ${this.appVars.shop.currency_sign}${this.refund.availableAmount}`, 'error');
            }

            // get only id, quantity, restore_quantity from this.refundTableItems
            const refundedItems = this.refundTableItems
              .filter(i => i.restore_quantity > 0)
              .map(i => ({
                id: i.id,
                variation_id: i.object_id,
                quantity: i.quantity,
                restore_quantity: i.restore_quantity
              }));

            this.refund.refunded_items = refundedItems;

            this.$post("orders/" + this.order_id + '/refund', {
                refund_info: this.refund,
                refunded_items: refundedItems
            }).then(res => {
                // this.handleSuccess(res.message);
                this.refundNote = res;
            }).catch(errors => {
                this.handleError(errors);
            }).finally(() => {
                this.updateRefundable();
                this.refundProcessing = false;
            })
        }
    },
    mounted() {
        this.refund.availableAmount = parseInt(this?.order?.total_paid) - parseInt(this?.order?.total_refund);

        this.transactions = this.order?.transactions;
        if (this.transactions.length) {
            this.updateRefundable();
        }
    }
}
</script>
