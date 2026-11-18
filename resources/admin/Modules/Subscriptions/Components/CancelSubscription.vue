<template>
  <fct-dialog
     :model-value="updatedModelValue"
     :loading="cancelling"
      :title="translate('Cancel Subscription')"
      width="500"
  >
    <el-form label-position="top">
      <div>
        {{translate('Are you sure to cancel the subscription?')}}
      </div>
      <br/>
      <el-form-item :label="translate('Cancellation reason')" required>
      <fct-select
          v-model="cancelReason"
          :options="[
            {label: translate('Refunded'), value: 'refunded'},
            {label: translate('Customer request'), value: 'customer_request'},
            {label: translate('Completed (EOT)'), value: 'completed'},
            {label: translate('Fraudulent'), value: 'fraudulent'},
            {label: translate('Other'), value: 'other'},
          ]"
      />
      </el-form-item>
      <el-form-item 
        v-if="cancelReason === 'other'" 
        :label="translate('Other Reason')" 
        required
        :error="otherReasonError"
      >
        <fct-input v-model="otherReason"/>
      </el-form-item>
    </el-form>
    <template #footer>
      <div class="dialog-footer">
        <fct-button @click="$emit('close')">{{translate('Cancel')}}</fct-button>
        <fct-button :loading="cancelling" type="warning" @click="cancelSubscription">
          {{translate('Cancel Subscription')}}
        </fct-button>
      </div>
    </template>
  </fct-dialog>
</template>
<script setup>
import translate from "@/utils/translator/Translator";
</script>

<script>
import translate from "@/utils/translator/Translator";
import Notify from "@/utils/Notify";

export default {
  name: "CancelSubscription",
  props: ['subscription', 'orderId', 'modelValue'],
  data() {
    return {
      cancelReason: 'customer_request',
      cancelling: false,
      otherReason: ''
    }
  },
  computed: {
    updatedModelValue: {
      get() {
        return this.modelValue;
      },
      set(val) {
        this.modelValue = val;
      }
    },
    otherReasonError() {
      if (this.cancelReason === 'other' && this.otherReason.trim() === '') {
        return translate('Please enter a reason');
      }
      return '';
    }
  },
  methods : {
    cancelSubscription() {
        this.cancelling = true;
        if (this.cancelReason === 'other') {
          if (this.otherReason.trim() === '') {
            this.$notify.error(translate('Please enter a reason'));
            this.cancelling = false;
            return;
          }
          this.cancelReason = this.otherReason;
        }

        this.$put(
            `orders/${this.orderId}/subscriptions/${this.subscription.id}/cancel`,
            {
                cancel_reason: this.cancelReason
            }
        ).then(response => {
          this.cancelling = true;
          this.$notify({
            type: "success",
            title: translate("Success"),
            message: translate("Subscription canceled successfully")
          });
          this.$emit("reload");
        })
        .catch(error => {
          this.cancelling = true;
          if (error.status_code == '422') {
            Notify.validationErrors(error);
          } else {
            Notify.error(error.data?.message);
          }
        }).finally(() => {
          this.cancelling = false;
          this.cancelSubscriptionModal = false;
        })
    },
  }
}
</script>
