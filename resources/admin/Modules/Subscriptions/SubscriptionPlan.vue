<template>
    <CardContainer>
      <CardHeader
        :title="$t('Subscription Plan')"
        border_bottom
        title_size="small"
      >
        <el-dropdown
          trigger="click"
          class="fct-more-option-wrap"
          popper-class="fct-dropdown"
          @command="(action) => handleSubscriptionAction(action)"
          v-if="permissions.canFetch || permissions.canPause || permissions.canResume || permissions.canCancel"
        >
          <span class="more-btn" >
            <DynamicIcon name="More" />
          </span>
          <template #dropdown>
            <el-dropdown-menu>
              <el-dropdown-item command="fetch_subscription" v-if="permissions.canFetch">
                <el-icon><refresh /></el-icon>
                {{ $t('Fetch subscription -') }} {{ paymentMethod}}
              </el-dropdown-item>
  
              <el-dropdown-item command="pause_subscription" v-if="permissions.canPause">
                <el-icon><refresh /></el-icon>
                {{ $t('Pause subscription -') }} {{ paymentMethod }}
              </el-dropdown-item>
  
              <el-dropdown-item command="resume_subscription" v-if="permissions.canResume">
                <el-icon><refresh /></el-icon>
                {{ $t('Resume subscription -') }} {{ paymentMethod }}
              </el-dropdown-item>
              <el-dropdown-item command="cancel_subscription" v-if="permissions.canCancel">
                <el-icon><delete /></el-icon>
                <span class="text-red-600">{{ $t("Cancel subscription") }}</span>
              </el-dropdown-item>
            </el-dropdown-menu>
          </template>
        </el-dropdown>
      </CardHeader>
  
      <CardBody>
        <plans
          @reload="$emit('reload')"
          :getOrderUrl="getOrderUrl"
          :subscription="subscription"
          :order="order"
          :header="$t('Subscription Plans #') + (index + 1)"
        />
      </CardBody>
    </CardContainer>

<!--  Cancel Modal -->
  <CancelSubscription
    :subscription="subscription"
    :orderId="orderId"
    @close="cancelSubscriptionModal = false"
    v-model="cancelSubscriptionModal"
    @reload="$emit('reload')"
    @cancelSubscription="confirmCancel"
  />

  </template>
  
  <script>
  import { markRaw } from "vue";
  import { ElMessageBox } from "element-plus";
  import { Refresh, Delete } from "@element-plus/icons-vue";
  import translate from "@/utils/translator/Translator";
  import { Container as CardContainer, Header as CardHeader, Body as CardBody } from '@/Bits/Components/Card/Card.js';
  import Plans from "../Orders/_Plans.vue";
  import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";
  import CancelSubscription from "./Components/CancelSubscription.vue";
  
  export default {
    name: "SubscriptionPlanItem",
    props: ["subscription", "order", "orderId", "index", "getOrderUrl"],
    emits: ["reload", "fetchOrder", "cancelSubscription"],
    components: {
      CardContainer,
      CardHeader,
      CardBody,
      Plans,
      Refresh,
      Delete,
      DynamicIcon,
      CancelSubscription
    },
    data() {
      return {
        loadingState: {
          pause: false,
          resume: false,
          cancel: false,
          fetch: false,
          reactivate: false
        },
        cancelSubscriptionModal: false,
        cancelReason: ''
      };
    },
    computed: {
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
            return this.order?.payment_method || '';
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
                    { data: { vendor_charge_id: this.subscription.vendor_subscription_id } }
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
            confirmButtonText: "Pause Subscription!",
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
            confirmButtonText: translate("Reactivate Subscription!"),
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
    }
  };
  </script>
