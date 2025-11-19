<template>
  <CardContainer>
    <CardHeader border_bottom :title="$t('Subscription Details')"
                title_size="small"
    >
      <el-dropdown
          trigger="click"
          class="fct-more-option-wrap"
          popper-class="fct-dropdown"
          @command="(action) => handleSubscriptionAction(action)"
          v-if="subscription.vendor_subscription_id && (permissions.canFetch || permissions.canPause || permissions.canResume || permissions.canCancel)"
      >
          <span class="more-btn">
            <DynamicIcon name="More"/>
          </span>
        <template #dropdown>
          <el-dropdown-menu>
            <el-dropdown-item command="fetch_subscription" v-if="permissions.canFetch">
              <el-icon>
                <refresh/>
              </el-icon>
              {{ $t('Fetch subscription -') }} {{ paymentMethod }}
            </el-dropdown-item>

            <el-dropdown-item command="pause_subscription" v-if="permissions.canPause">
              <el-icon>
                <refresh/>
              </el-icon>
              {{ $t('Pause subscription -') }} {{ paymentMethod }}
            </el-dropdown-item>

            <el-dropdown-item command="resume_subscription" v-if="permissions.canResume">
              <el-icon>
                <refresh/>
              </el-icon>
              {{ $t('Resume subscription -') }} {{ paymentMethod }}
            </el-dropdown-item>
            <el-dropdown-item command="cancel_subscription" v-if="permissions.canCancel">
              <el-icon>
                <delete/>
              </el-icon>
              <span class="text-red-600">{{ $t("Cancel subscription") }}</span>
            </el-dropdown-item>
          </el-dropdown-menu>
        </template>
      </el-dropdown>
    </CardHeader>
    <CardBody>
      <div class="fct-single-subscription-pricing-table">
        <p>{{ $t('Product Name') }}</p>
        <h3>{{ subscription.item_name }}</h3>
      </div>
      <ul class="fct-single-subscription-details">
        <li>
          <div class="fct-single-subscription-details-label">
            {{ $t("Billing Cycle") }}
          </div>
          <div class="fct-single-subscription-details-value" v-html="subscription.payment_info"/>
        </li>

        <li>
          <div class="fct-single-subscription-details-label">
            {{ $t("Active Payment Gateway") }}
          </div>
          <div class="fct-single-subscription-details-value">
            <span v-if="!subscription.current_payment_method">----</span>

            <div v-else class="flex items-center gap-2.5">
              <span>
                {{ Str.headline(subscription.current_payment_method) }}
              </span>
            </div>

          </div>
        </li>

        <li>
          <div class="fct-single-subscription-details-label">
            {{ $t("Initial Purchase ID") }}
          </div>
          <div class="fct-single-subscription-details-value">
            #{{ subscription.parent_order_id }}
          </div>
        </li>

        <li>
          <div class="fct-single-subscription-details-label">
            {{ $t("Auto-cancellation") }}
          </div>
          <div class="fct-single-subscription-details-value">
            {{ subscription.expire_at ? subscription.expire_at : '---' }}
          </div>
        </li>

        <li>
          <div class="fct-single-subscription-details-label">
            {{ $t("Started") }}
          </div>
          <div class="fct-single-subscription-details-value">
            <ConvertedTime :date-time="subscription.created_at" :withTime="false"/>
          </div>
        </li>

        <li>
          <div class="fct-single-subscription-details-label">
            {{ $t("Next invoice") }}
          </div>
          <div class="fct-single-subscription-details-value">
            {{ formatNumber(subscription.recurring_total) }} on
            <ConvertedTime :date-time="subscription.next_billing_date" :withTime="false"/>
          </div>
        </li>

        <li>
          <div class="fct-single-subscription-details-label">
            {{ $t("Vendor Subscription ID") }}
          </div>
          <div class="fct-single-subscription-details-value">
            <div class="flex items-center justify-between gap-2 w-full"
                 v-if="subscription.vendor_subscription_id">
              <el-tooltip class="w-full" :content="subscription.vendor_subscription_id" placement="top"
                          popper-class="fct-tooltip">
                            <span
                                class="truncate w-[calc(100%-2rem)] overflow-hidden text-ellipsis whitespace-nowrap block">
                              {{ subscription.vendor_subscription_id }}
                            </span>
              </el-tooltip>
              <CopyToClipboard :text="subscription.vendor_subscription_id" showMode="basic_copy_btn"
                               tooltipText="Copy"/>
            </div>
            <span v-else class="text-system-light dark:text-gray-300">
              {{ $t('n/a') }}
            </span>
          </div>
        </li>
        <li v-if="subscription?.vendor_subscription_id">
          <div class="fct-single-subscription-details-label">
            View on {{ subscription?.current_payment_method }}:
          </div>
          <a target="_blank" class="fct-single-subscription-details-value !underline focus:shadow-none" :href="subscription?.url">{{ subscription?.vendor_subscription_id }}
          </a>
        </li>
        <li>
          <div class="fct-single-subscription-details-label">
            {{ $t("Vendor Customer ID") }}
          </div>
          <div class="fct-single-subscription-details-value">
            <div class="flex items-center justify-between"
                 v-if="subscription?.vendor_customer_id">
              <el-tooltip class="w-full" :content="subscription?.vendor_customer_id" placement="top"
                          popper-class="fct-tooltip">
                            <span
                                class="truncate w-[calc(100%-2rem)] overflow-hidden text-ellipsis whitespace-nowrap block">
                              {{ subscription?.vendor_customer_id }}
                            </span>
              </el-tooltip>
              <CopyToClipboard :text="subscription?.vendor_customer_id" showMode="basic_copy_btn"
                               tooltipText="Copy"/>
            </div>
            <span v-else class="text-system-light dark:text-gray-300">
              {{ $t('n/a') }}
            </span>
          </div>
        </li>
      </ul>
    </CardBody>
    <CancelSubscription
      :subscription="subscription"
      :orderId="orderId"
      @close="cancelSubscriptionModal = false"
      v-model="cancelSubscriptionModal"
      @reload="$emit('reload')"
      @cancelSubscription="confirmCancel"
  />
  </CardContainer>
</template>

<script>
import {markRaw} from "vue";
import {ElMessageBox} from "element-plus";
import {Refresh, Delete} from "@element-plus/icons-vue";
import translate from "@/utils/translator/Translator";
import {Container as CardContainer, Body as CardBody, Header as CardHeader} from "@/Bits/Components/Card/Card.js";
import CopyToClipboard from "@/Bits/Components/CopyToClipboard.vue";
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";
import Str from "../../../utils/support/Str";
import ConvertedTime from "@/Bits/Components/ConvertedTime.vue";
import CancelSubscription from "@/Modules/Subscriptions/Components/CancelSubscription.vue";

export default {
  name: "SubscriptionDetails",
  props: ['subscription', 'orderId'],
  emits: ["reload", "fetchOrder"],
  data() {
    return {
      loadingState: {
        fetch: false,
        pause: false,
        resume: false,
        cancel: false,
      },
      cancelSubscriptionModal: false,
    }
  },
  components: {
    CancelSubscription,
    ConvertedTime,
    CopyToClipboard,
    CardContainer,
    CardBody,
    CardHeader,
    DynamicIcon,
    Refresh,
    Delete
  },
  computed: {
    Str() {
      return Str
    },
    permissions() {
      const status = (this.subscription?.status || "").toLowerCase();
      let vendor_subscription_id = this.subscription?.vendor_subscription_id;
      return {
        canFetch: true,
        canPause: vendor_subscription_id && !['canceled', 'paused', 'trialing', 'pending', 'intended', 'active(collection paused)'].includes(status),
        canResume: vendor_subscription_id && ['paused', 'active(collection paused)'].includes(status),
        canCancel: vendor_subscription_id && !['canceled'].includes(status),
      };
    },
    paymentMethod() {
      return this.subscription?.current_payment_method || '';
    },
  },
  methods: {
    handleSubscriptionAction(action) {
      if (action === "fetch_subscription") this.confirmFetch();
      else if (action === "pause_subscription") this.confirmPause();
      else if (action === "resume_subscription") this.confirmResume();
      else if (action === "cancel_subscription") this.confirmCancel();
    },

    confirmFetch() {
      if (!this.subscription.vendor_subscription_id && 'offline_payment' !== this.order?.payment_method) return;
      ElMessageBox.confirm(
          "Fetch from remote and update the subscription?",
          "Warning",
          {
            type: "warning",
            icon: markRaw(Delete),
            cancelButtonText: "Back",
            confirmButtonText: "Fetch subscription!",
            beforeClose: async (action, instance, done) => {
              if (action === "confirm") {
                try {
                  instance.confirmButtonLoading = true;
                  this.loadingState.fetch = true;
                  await this.$put(
                      `orders/${this.orderId}/subscriptions/${this.subscription.id}/fetch`,
                      {data: {vendor_charge_id: this.subscription.vendor_subscription_id}}
                  );
                  this.$notify({
                    type: "success",
                    title: "Success",
                    message: "Subscription fetched successfully"
                  });
                  this.$emit("reload");
                } catch (e) {
                  this.$notify({
                    type: "error",
                    title: "Error",
                    message: translate(e.response?.data?.message || "Failed to fetch subscription")
                  });
                  console.error(e);
                } finally {
                  instance.confirmButtonLoading = false;
                  this.loadingState.fetch = false;
                  done();
                }
              } else done();
            }
          }
      );
    },

    confirmPause() {
      if (!this.subscription.vendor_subscription_id) return;
      ElMessageBox.prompt(
          "Pause the active subscription?",
          "Warning",
          {
            type: "warning",
            icon: markRaw(Delete),
            inputPlaceholder: "Enter reason for pausing",
            inputPattern: /.+/,
            inputErrorMessage: "Reason is required",
            cancelButtonText: "Back",
            confirmButtonText: translate("Pause Subscription!"),
            beforeClose: async (action, instance, done) => {
              if (action === "confirm") {
                try {
                  instance.confirmButtonLoading = true;
                  this.loadingState.pause = true;
                  await this.$put(
                      `orders/${this.orderId}/subscriptions/${this.subscription.id}/pause`,
                      {
                        data: {
                          vendor_subscription_id: this.subscription.vendor_subscription_id,
                          reason: instance.inputValue
                        }
                      }
                  );
                  this.$notify({
                    type: "success",
                    title: "Success",
                    message: "Subscription paused successfully"
                  });
                  this.$emit("reload");
                } catch (e) {
                  this.$notify({
                    type: "error",
                    title: "Error",
                    message: translate(e.response?.data?.message || "Failed to pause subscription")
                  });
                  console.error(e);
                } finally {
                  instance.confirmButtonLoading = false;
                  this.loadingState.pause = false;
                  done();
                }
              } else done();
            }
          }
      );
    },

    confirmResume() {
      if (!this.subscription.vendor_subscription_id) return;
      ElMessageBox.prompt(
          translate("Resume the paused subscription?"),
          translate("Warning"),
          {
            type: "warning",
            icon: markRaw(Delete),
            inputPlaceholder: translate("Enter reason for resuming"),
            inputPattern: /.+/,
            inputErrorMessage: translate("Reason is required"),
            cancelButtonText: translate("Back"),
            confirmButtonText: translate("Resume Subscription!"),
            beforeClose: async (action, instance, done) => {
              if (action === "confirm") {
                try {
                  instance.confirmButtonLoading = true;
                  this.loadingState.resume = true;
                  await this.$put(
                      `orders/${this.orderId}/subscriptions/${this.subscription.id}/resume`,
                      {
                        data: {
                          vendor_subscription_id: this.subscription.vendor_subscription_id,
                          reason: instance.inputValue
                        }
                      }
                  );
                  this.$notify({
                    type: "success",
                    title: "Success",
                    message: "Subscription resumed successfully"
                  });
                  this.$emit("reload");
                } catch (e) {
                  this.$notify({
                    type: "error",
                    title: "Error",
                    message: "Failed to resume subscription"
                  });
                  console.error(e);
                } finally {
                  instance.confirmButtonLoading = false;
                  this.loadingState.resume = false;
                  done();
                }
              } else done();
            }
          }
      );
    },

    confirmCancel() {
      if (!this.subscription.vendor_subscription_id) return;
      this.cancelSubscriptionModal = true;
    },
    confirmReactivate() {
      if (!this.subscription.vendor_subscription_id) return;
      ElMessageBox.confirm(
          "Reactivate the canceled subscription?",
          "Warning",
          {
            type: "warning",
            icon: markRaw(Delete),
            cancelButtonText: "Back",
            confirmButtonText: "Reactivate Subscription!",
            beforeClose: async (action, instance, done) => {
              if (action === "confirm") {
                try {
                  instance.confirmButtonLoading = true;
                  this.loadingState.reactivate = true;
                  await this.$put(
                      `orders/${this.orderId}/subscriptions/${this.subscription.id}/reactivate`,
                      {
                        data: {
                          vendor_subscription_id: this.subscription.vendor_subscription_id
                        }
                      }
                  );
                  this.$notify({
                    type: "success",
                    title: "Success",
                    message: "Subscription reactivated successfully"
                  });
                  this.$emit("reload");
                } catch (e) {
                  this.$notify({
                    type: "error",
                    title: "Error",
                    message: translate(e.response?.data?.message || "Failed to reactivate subscription")
                  });
                  console.error(e);
                } finally {
                  instance.confirmButtonLoading = false;
                  this.loadingState.reactivate = false;
                  done();
                }
              } else done();
            }
          }
      );
    }
  },
}
</script>

<style scoped lang="scss">

</style>
