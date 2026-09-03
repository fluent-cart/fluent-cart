<template>
  <CardContainer>
    <CardHeader border_bottom
                title_size="small"
    >
      <template #title>
        <h2 class="fct-card-header-title is-small inline-flex items-center gap-2">
          {{ $t('Subscription Details') }}
          <el-tooltip :content="billingTypeBadge.description" placement="top-start" effect="dark">
            <el-tag :type="billingTypeBadge.type" :class="billingTypeBadge.class" size="small" effect="light" round>
              {{ billingTypeBadge.label }}
            </el-tag>
          </el-tooltip>
        </h2>
      </template>
      <el-dropdown
          trigger="click"
          class="fct-more-option-wrap"
          popper-class="fct-dropdown"
          @command="(action) => handleSubscriptionAction(action)"
          v-if="permissions.canFetch || permissions.canEdit || permissions.canEditVendorIds || permissions.canPause || permissions.canResume || permissions.canAdminReactivate || permissions.canCancel || permissions.canSendReminder || permissions.canCreateRenewal || permissions.canSkipRenewal || permissions.canChargeNow"
      >
          <span class="more-btn">
            <DynamicIcon name="More"/>
          </span>
        <template #dropdown>
          <el-dropdown-menu>
            <el-dropdown-item command="fetch_subscription" v-if="permissions.canFetch">
              <el-icon>
                <Refresh/>
              </el-icon>
              {{ $t('Sync from gateway -') }} {{ paymentMethod }}
            </el-dropdown-item>

            <el-dropdown-item command="edit_subscription" v-if="permissions.canEdit">
              <el-icon>
                <Edit/>
              </el-icon>
              {{ $t('Edit Subscription') }}
            </el-dropdown-item>

            <el-dropdown-item command="edit_vendor_ids" v-if="permissions.canEditVendorIds">
              <el-icon>
                <Edit/>
              </el-icon>
              {{ $t('Edit Vendor IDs') }}
            </el-dropdown-item>

            <el-dropdown-item command="pause_subscription" v-if="permissions.canPause" :disabled="loadingState.pause">
              <el-icon>
                <VideoPause/>
              </el-icon>
              {{ $t('Pause Subscription') }}
            </el-dropdown-item>

            <el-dropdown-item command="resume_subscription" v-if="permissions.canResume" :disabled="loadingState.resume">
              <el-icon>
                <VideoPlay/>
              </el-icon>
              {{ $t('Resume Subscription') }}
            </el-dropdown-item>
            <el-dropdown-item command="reactivate_subscription" v-if="permissions.canAdminReactivate" :disabled="loadingState.reactivate">
              <el-icon>
                <VideoPlay/>
              </el-icon>
              {{ $t('Reactivate Subscription') }}
            </el-dropdown-item>
            <el-dropdown-item command="cancel_subscription" v-if="permissions.canCancel">
              <el-icon>
                <Delete/>
              </el-icon>
              <span class="text-red-600">{{ $t("Cancel Subscription") }}</span>
            </el-dropdown-item>
            <el-dropdown-item command="send_reminder" v-if="permissions.canSendReminder" :disabled="loadingState.sendReminder">
              <el-icon>
                <Promotion/>
              </el-icon>
              {{ reminderLabel }}
            </el-dropdown-item>
            <el-dropdown-item command="create_renewal" v-if="permissions.canCreateRenewal" :disabled="loadingState.createRenewal">
              <el-icon>
                <component :is="createRenewalIcon"/>
              </el-icon>
              {{ createRenewalLabel }}
            </el-dropdown-item>
            <el-dropdown-item command="skip_renewal" v-if="permissions.canSkipRenewal" :disabled="loadingState.skipRenewal">
              <el-icon>
                <CircleClose/>
              </el-icon>
              {{ $t('Skip Next Period') }}
            </el-dropdown-item>
            <el-dropdown-item command="charge_now" v-if="permissions.canChargeNow" :disabled="loadingState.chargeNow">
              <el-icon>
                <CreditCard/>
              </el-icon>
              {{ $t('Charge Now') }}
            </el-dropdown-item>
          </el-dropdown-menu>
        </template>
      </el-dropdown>
    </CardHeader>
    <CardBody>
      <el-alert
          v-if="chargeStateAlert"
          :type="chargeStateAlert.type"
          :title="chargeStateAlert.title"
          :description="chargeStateAlert.description"
          :closable="false"
          show-icon
          class="mb-4"
      />
      <div class="fct-single-subscription-pricing-table">
        <p>{{ $t('Product Name') }}</p>
        <h3>{{ subscription.display_item_name }}</h3>
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

        <li v-if="subscription.bill_times > 0">
          <div class="fct-single-subscription-details-label">
            {{ $t("Installment Progress") }}
          </div>
          <div class="fct-single-subscription-details-value">
            {{ subscription.bill_count }} / {{ subscription.bill_times }}
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

        <li v-if="showNextBillingDate">
          <div class="fct-single-subscription-details-label">
            {{ $t("Next renewal") }}
          </div>
          <div class="fct-single-subscription-details-value">
            {{ formatNumberForOrder(subscription.recurring_total, subscription) }} on
            <ConvertedTime :date-time="subscription.next_billing_date" :withTime="false"/>
            <el-tooltip v-if="subscription.has_pending_skip" :content="skipBadgeTooltip" placement="top" effect="dark" :trigger="['hover', 'focus']">
              <el-tag type="warning" size="small" effect="light" round class="fct-skip-badge"
                      tabindex="0" role="note" :aria-label="skipBadgeTooltip">
                {{ $t('Period skipped') }}
              </el-tag>
            </el-tooltip>
          </div>
        </li>

      </ul>
      <div class="fct-vendor-ids" v-if="subscription.vendor_subscription_id || subscription?.vendor_customer_id">
        <div v-if="subscription.vendor_subscription_id">
          <div class="fct-vendor-ids__label">{{ $t("Vendor Subscription ID") }}</div>
          <div class="fct-vendor-ids__value">
            <a v-if="subscription.url" :href="subscription.url" target="_blank"
               class="fct-vendor-ids__link">
              {{ subscription.vendor_subscription_id }}
            </a>
            <span v-else class="fct-vendor-ids__text">
              {{ subscription.vendor_subscription_id }}
            </span>
            <CopyToClipboard :text="subscription.vendor_subscription_id" showMode="basic_copy_btn"
                             tooltipText="Copy"/>
          </div>
        </div>
        <div v-if="subscription?.vendor_customer_id">
          <div class="fct-vendor-ids__label">{{ $t("Vendor Customer ID") }}</div>
          <div class="fct-vendor-ids__value">
            <span class="fct-vendor-ids__text">
              {{ subscription.vendor_customer_id }}
            </span>
            <CopyToClipboard :text="subscription?.vendor_customer_id" showMode="basic_copy_btn"
                             tooltipText="Copy"/>
          </div>
        </div>
        <div></div>
      </div>
    </CardBody>
    <CancelSubscription
      :subscription="subscription"
      :orderId="orderId"
      @close="cancelSubscriptionModal = false"
      v-model="cancelSubscriptionModal"
      @reload="$emit('reload')"
      @cancel-subscription="confirmCancel"
  />
  <!-- Edit Subscription Modal -->
  <el-dialog
      v-model="editSubscriptionModal"
      :title="$t('Edit Subscription')"
      width="500px"
      :close-on-click-modal="false"
  >
      <el-alert
          type="warning"
          :closable="false"
          show-icon
          class="mb-4"
      >
          {{ $t('Editing this subscription will update the details for all future renewals.') }}
      </el-alert>

      <el-alert
          v-if="hasPendingRenewal"
          type="info"
          :closable="false"
          show-icon
          class="mb-4"
      >
          {{ $t('A renewal invoice is already pending; it will be updated to the new amount.') }}
      </el-alert>

      <el-form :model="editForm" label-width="140px" v-loading="loadingState.edit">
          <el-form-item :label="$t('Next Renewal Amount')">
              <el-input-number
                  v-model="editForm.recurring_total"
                  :precision="2"
                  :min="0"
                  :step="0.01"
                  controls-position="right"
                  style="width: 100%"
              />
          </el-form-item>

          <el-form-item :label="$t('Billing Times')">
              <el-input-number
                  v-model="editForm.bill_times"
                  :min="billTimesMin"
                  :step="1"
                  controls-position="right"
                  style="width: 100%"
              />
              <div class="text-xs text-gray-500 mt-1">
                {{ $t('0 = Unlimited (renew indefinitely)') }}
                <span v-if="subscription?.bill_count > 0"> &mdash; {{ $t('must be 0 or') }} &ge; {{ subscription.bill_count }} ({{ $t('payments made') }})</span>
              </div>
          </el-form-item>

          <el-form-item :label="$t('Billing Interval')">
              <el-select v-model="editForm.billing_interval" style="width: 100%">
                  <el-option :label="$t('Daily')" value="daily" />
                  <el-option :label="$t('Weekly')" value="weekly" />
                  <el-option :label="$t('Monthly')" value="monthly" />
                  <el-option :label="$t('Quarterly')" value="quarterly" />
                  <el-option :label="$t('Half Yearly')" value="half_yearly" />
                  <el-option :label="$t('Yearly')" value="yearly" />
              </el-select>
          </el-form-item>

          <el-form-item :label="$t('Status')">
              <el-select v-model="editForm.status" style="width: 100%">
                  <el-option :label="$t('Active')" value="active" />
                  <el-option :label="$t('Paused')" value="paused" />
                  <el-option :label="$t('Trialing')" value="trialing" />
                  <el-option :label="$t('Past Due')" value="past_due" />
                  <el-option :label="$t('Expired')" value="expired" />
                  <el-option :label="$t('Completed')" value="completed" />
              </el-select>
          </el-form-item>

          <el-form-item :label="$t('Next Billing Date')">
              <el-date-picker
                  v-model="editForm.next_billing_date"
                  type="datetime"
                  :placeholder="$t('Select date')"
                  format="YYYY-MM-DD HH:mm"
                  value-format="YYYY-MM-DD HH:mm:ss"
                  style="width: 100%"
              />
          </el-form-item>

      </el-form>

      <template #footer>
          <span class="dialog-footer">
              <el-button @click="editSubscriptionModal = false">{{ $t('Cancel') }}</el-button>
              <el-button type="primary" @click="confirmSaveSubscription" :loading="loadingState.save">
                  {{ $t('Save Changes') }}
              </el-button>
          </span>
      </template>
  </el-dialog>

  <!-- Edit Vendor IDs Modal -->
  <el-dialog
      v-model="vendorIdsModal"
      :title="$t('Edit Vendor IDs')"
      width="520px"
      :close-on-click-modal="false"
  >
      <el-alert
          type="warning"
          :closable="false"
          show-icon
          class="mb-4"
      >
          {{ $t('Renewals, cancellations and incoming gateway webhooks all follow these IDs. Changing them does not change anything at the payment gateway — use this only to correct IDs that are already wrong.') }}
      </el-alert>

      <el-form :model="vendorIdsForm" label-position="top">
          <el-form-item :label="$t('Vendor Subscription ID')">
              <div class="fct-vendor-ids-edit__row">
                  <el-input v-model="vendorIdsForm.vendor_subscription_id" :placeholder="$t('e.g. sub_4L7nezJXHW')"/>
                  <el-button
                      v-if="permissions.canVerifyVendorIds"
                      @click="verifyVendorIds"
                      :loading="loadingState.verifyVendorIds"
                  >
                      {{ $t('Verify') }}
                  </el-button>
              </div>
          </el-form-item>

          <el-form-item :label="$t('Vendor Customer ID')">
              <el-input v-model="vendorIdsForm.vendor_customer_id" :placeholder="$t('e.g. cst_9GrdvYmPPn')"/>
          </el-form-item>
      </el-form>

      <el-alert
          v-if="vendorIdsVerification"
          :type="vendorIdsVerification.ok ? 'success' : 'error'"
          :closable="false"
          show-icon
          class="mb-4"
      >
          <template v-if="vendorIdsVerification.ok">
              <div>{{ $t('Found at gateway') }} &mdash; {{ vendorIdsVerification.summary }}</div>
              <div class="text-xs mt-1">
                {{ $t('This only proves the ID exists in the connected gateway account. Confirm it is the right customer before saving.') }}
              </div>
          </template>
          <template v-else>
              {{ vendorIdsVerification.message }}
          </template>
      </el-alert>

      <template #footer>
          <span class="dialog-footer">
              <el-button @click="vendorIdsModal = false">{{ $t('Cancel') }}</el-button>
              <el-button
                  type="primary"
                  @click="confirmSaveVendorIds"
                  :loading="loadingState.saveVendorIds"
                  :disabled="!vendorIdsChanged"
              >
                  {{ $t('Save Changes') }}
              </el-button>
          </span>
      </template>
  </el-dialog>
  </CardContainer>
</template>

<script>
import {markRaw} from "vue";
import {ElMessageBox} from "element-plus";
import {Refresh, Delete, VideoPause, VideoPlay, Edit, Promotion, Document, CircleClose, CreditCard} from "@element-plus/icons-vue";
import translate from "@/utils/translator/Translator";
import Permission from "@/utils/permission/Permission";
import {Container as CardContainer, Body as CardBody, Header as CardHeader} from "@/Bits/Components/Card/Card.js";
import CopyToClipboard from "@/Bits/Components/CopyToClipboard.vue";
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";
import Str from "../../../utils/support/Str";
import ConvertedTime from "@/Bits/Components/ConvertedTime.vue";
import CancelSubscription from "@/Modules/Subscriptions/Components/CancelSubscription.vue";

export default {
  name: "SubscriptionDetails",
  components: {
    CancelSubscription,
    ConvertedTime,
    CopyToClipboard,
    CardContainer,
    CardBody,
    CardHeader,
    DynamicIcon,
    Refresh,
    Delete,
    VideoPause,
    VideoPlay,
    Edit,
    Promotion,
    Document,
    CreditCard,
    CircleClose
  },
  props: ['subscription', 'orderId', 'reminderPermissions'],
  emits: ["reload", "fetchOrder"],
  data() {
    return {
      loadingState: {
        fetch: false,
        pause: false,
        resume: false,
        cancel: false,
        edit: false,
        save: false,
        sendReminder: false,
        createRenewal: false,
        skipRenewal: false,
        chargeNow: false,
        verifyVendorIds: false,
        saveVendorIds: false,
      },
      cancelSubscriptionModal: false,
      editSubscriptionModal: false,
      editForm: {
        recurring_total: 0,
        bill_times: 0,
        billing_interval: 'monthly',
        status: 'active',
        next_billing_date: ''
      },
      vendorIdsModal: false,
      vendorIdsForm: {
        vendor_subscription_id: '',
        vendor_customer_id: ''
      },
      vendorIdsVerification: null
    }
  },
  computed: {
    Str() {
      return Str
    },
    billTimesMin() {
      return 0;
    },
    // Store-billed subscriptions (manual + system/auto-charge) use the local
    // pause/resume/reactivate endpoints; only automatic ones talk to the vendor.
    isStoreBilled() {
      return ['manual', 'system'].includes(this.subscription?.collection_method);
    },
    // A renewal invoice already exists for the current period; editing re-syncs
    // it to the new amount — surfaced in the Edit modal.
    hasPendingRenewal() {
      return this.isStoreBilled && !!this.subscription?.permissions?.hasPendingRenewal;
    },
    isSystemSubscription() {
      return this.subscription?.collection_method === 'system';
    },
    skipBadgeTooltip() {
      const info = this.subscription?.last_skipped_period;
      if (!info) {
        return translate('An admin skipped the next billing period.');
      }
      const who = info.actor_name || translate('an admin');
      if (info.reason) {
        /* translators: %1$s: admin who skipped, %2$s: admin-supplied reason */
        return translate('Skipped by %1$s. Billing resumes on the date shown. Reason: %2$s', who, info.reason);
      }
      /* translators: %1$s: name of the admin who skipped the period */
      return translate('Skipped by %1$s. Billing resumes on the date shown.', who);
    },
    createRenewalLabel() {
      return this.isSystemSubscription
          ? translate('Charge Next Renewal Now')
          : translate('Create Renewal Now');
    },
    createRenewalIcon() {
      return this.isSystemSubscription ? 'CreditCard' : 'Document';
    },
    // Badge next to the card title — manual (store, admin-billed) vs
    // system (store, auto-charge) vs automatic (vendor/gateway-billed).
    billingTypeBadge() {
      const method = this.subscription?.collection_method;
      if (method === 'manual') {
        return {
          label: translate('Manual'),
          type: 'warning',
          description: translate('Customer must pay each renewal manually — no auto-charge.'),
        };
      }
      if (method === 'system') {
        return {
          label: translate('System'),
          type: 'success',
          class: 'fct-billing-badge--system',
          description: translate('Auto-charged by the store on schedule, using the saved payment method.'),
        };
      }
      return {
        label: translate('Automatic'),
        type: 'success',
        description: translate('Billed and managed entirely by the payment gateway.'),
      };
    },
    // Auto-charge (system) retry/processing state banner. Null when there is
    // nothing noteworthy — healthy system subs show no banner.
    chargeStateAlert() {
      const state = this.subscription?.system_charge_state;
      if (!state) {
        return null;
      }

      if (state.status === 'processing') {
        return {
          type: 'info',
          title: translate('Automatic charge processing'),
          description: translate('The renewal charge was submitted and is awaiting confirmation from the payment provider.'),
        };
      }

      if (!state.last_error) {
        return null;
      }

      const attempts = state.attempts;
      const maxAttempts = state.max_attempts || state.attempts;
      let description;

      if (state.next_retry_at) {
        /* translators: %1$s: failed attempt number, %2$s: total attempts, %3$s: gateway failure reason, %4$s: date/time of the next automatic charge attempt */
        description = translate('Attempt %1$s of %2$s failed: %3$s — next automatic retry: %4$s (GMT).', attempts, maxAttempts, state.last_error, state.next_retry_at);
      } else if (state.exhausted === 'yes') {
        /* translators: %1$s: failed attempt number, %2$s: total attempts, %3$s: gateway failure reason */
        description = translate('Attempt %1$s of %2$s failed: %3$s — no further automatic retries, the customer must pay manually.', attempts, maxAttempts, state.last_error);
      } else {
        /* translators: %1$s: failed attempt number, %2$s: total attempts, %3$s: gateway failure reason */
        description = translate('Attempt %1$s of %2$s failed: %3$s', attempts, maxAttempts, state.last_error);
      }

      return {
        type: 'warning',
        title: translate('Automatic charge failed'),
        description,
      };
    },
    vendorIdsChanged() {
      return this.vendorIdsForm.vendor_subscription_id !== (this.subscription.vendor_subscription_id || '')
          || this.vendorIdsForm.vendor_customer_id !== (this.subscription.vendor_customer_id || '');
    },

    permissions() {
      const sp = this.subscription?.permissions || {};
      const canManage = Permission.resolve({permission: 'subscriptions/manage'});
      const rp = this.reminderPermissions || {};
      return {
        canFetch:         canManage && !!sp.canFetch,
        canEdit:          canManage && !!sp.canEdit,
        canEditVendorIds: canManage && !!sp.canEditVendorIds,
        canVerifyVendorIds: canManage && !!sp.canVerifyVendorIds,
        canPause:         canManage && !!sp.canPause,
        canResume:        canManage && !!sp.canResume,
        canAdminReactivate:    canManage && !!sp.canAdminReactivate,
        canCancel:        canManage && !!sp.canCancel,
        canCreateRenewal: canManage && !!sp.canCreateRenewal,
        canSkipRenewal:   canManage && !!sp.canSkipRenewal,
        canChargeNow:     canManage && !!sp.canChargeNow,
        canSendReminder:  !!(rp.canSendRenewal || rp.canSendTrialEnd),
      };
    },
    reminderLabel() {
      const rp = this.reminderPermissions || {};
      if (rp.canSendTrialEnd) {
        return translate('Send Trial End Reminder');
      }
      return translate('Send Renewal Reminder');
    },
    reminderEvent() {
      const rp = this.reminderPermissions || {};
      if (rp.canSendTrialEnd) {
        return 'subscription_trial_end_reminder';
      }
      return 'subscription_renewal_reminder';
    },
    paymentMethod() {
      return this.subscription?.current_payment_method || '';
    },
    showNextBillingDate() {
      const status = (this.subscription?.status || '').toLowerCase();
      return (status === 'active' || status === 'trialing');
    },
  },
  watch: {
    // A verification result describes the exact pair of IDs that was sent. Once
    // either input moves it no longer describes what is on screen, so drop it
    // rather than let a stale "Found at gateway" vouch for an edited ID.
    vendorIdsForm: {
      deep: true,
      handler() {
        this.vendorIdsVerification = null;
      }
    }
  },
  methods: {
    handleSubscriptionAction(action) {
      if (action === "fetch_subscription") this.confirmFetch();
      else if (action === "edit_subscription") this.confirmEdit();
      else if (action === "edit_vendor_ids") this.confirmEditVendorIds();
      else if (action === "pause_subscription") this.confirmPause();
      else if (action === "resume_subscription") this.confirmResume();
      else if (action === "reactivate_subscription") this.confirmReactivate();
      else if (action === "cancel_subscription") this.confirmCancel();
      else if (action === "send_reminder") this.confirmSendReminder();
      else if (action === "create_renewal") this.confirmCreateRenewal();
      else if (action === "skip_renewal") this.confirmSkipRenewal();
      else if (action === "charge_now") this.confirmChargeNow();
    },

    confirmFetch() {
      if (!this.subscription.vendor_subscription_id && 'offline_payment' !== this.order?.payment_method) return;
      ElMessageBox.confirm(
          translate("Fetch from remote and update the subscription?"),
          translate("Warning"),
          {
            type: "warning",
            icon: markRaw(Delete),
            cancelButtonText: translate("Back"),
            confirmButtonText: translate("Fetch subscription!"),
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
                    title: translate("Success"),
                    message: translate("Subscription fetched successfully")
                  });
                  this.$emit("reload");
                } catch (e) {
                  this.$notify({
                    type: "error",
                    title: translate("Error"),
                    message: translate(e.response?.data?.message || "Failed to fetch subscription")
                  });
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

    async confirmPause() {
      // For manual subscriptions, use a simple confirm dialog
      if (this.isStoreBilled) {
        this.loadingState.pause = true;
        try {
          await this.$put(
              `orders/${this.orderId}/subscriptions/${this.subscription.id}/pause`,
              {
                data: {
                  reason: 'Paused by admin'
                }
              }
          );
          this.$notify({
            type: "success",
            title: translate("Success"),
            message: translate("Subscription paused successfully")
          });
          this.$emit("reload");
        } catch (e) {
          this.$notify({
            type: "error",
            title: translate("Error"),
            message: translate(e.response?.data?.message || "Failed to pause subscription")
          });
        } finally {
          this.loadingState.pause = false;
        }
        return;
      }

      // Automatic subscriptions
      if (!this.subscription.vendor_subscription_id) return;

      ElMessageBox.prompt(
          translate("Pause the active subscription?"),
          translate("Warning"),
          {
            type: "warning",
            icon: markRaw(Delete),
            inputPlaceholder: translate("Enter reason for pausing"),
            inputPattern: /.+/,
            inputErrorMessage: translate("Reason is required"),
            cancelButtonText: translate("Back"),
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
                    title: translate("Success"),
                    message: translate("Subscription paused successfully")
                  });
                  this.$emit("reload");
                } catch (e) {
                  this.$notify({
                    type: "error",
                    title: translate("Error"),
                    message: translate(e.response?.data?.message || "Failed to pause subscription")
                  });
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

    async confirmResume() {
      // For manual subscriptions, use a simple confirm dialog
      if (this.isStoreBilled) {
        this.loadingState.resume = true;
        try {
          await this.$put(
              `orders/${this.orderId}/subscriptions/${this.subscription.id}/resume`,
              {
                data: {
                  reason: 'Resumed by admin'
                }
              }
          );
          this.$notify({
            type: "success",
            title: translate("Success"),
            message: translate("Subscription resumed successfully")
          });
          this.$emit("reload");
        } catch (e) {
          this.$notify({
            type: "error",
            title: translate("Error"),
            message: translate(e.response?.data?.message || "Failed to resume subscription")
          });
        } finally {
          this.loadingState.resume = false;
        }
        return;
      }

      // Automatic subscriptions
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
                    title: translate("Success"),
                    message: translate("Subscription resumed successfully")
                  });
                  this.$emit("reload");
                } catch (e) {
                  this.$notify({
                    type: "error",
                    title: translate("Error"),
                    message: translate(e.response?.data?.message || "Failed to resume subscription")
                  });
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

    confirmEdit() {
      // Initialize edit form with current subscription values
      this.editForm = {
        recurring_total: this.subscription.recurring_total / 100, // Convert cents to decimal
        bill_times: this.subscription.bill_times,
        billing_interval: this.subscription.billing_interval || 'monthly',
        status: this.subscription.status,
        next_billing_date: this.subscription.next_billing_date
      };
      this.editSubscriptionModal = true;
    },

    async confirmSaveSubscription() {
      // Show confirmation before saving
      try {
        await ElMessageBox.confirm(
            translate('Are you sure you want to update this subscription? This will affect all future renewals.'),
            translate('Confirm Update'),
            {
                type: 'warning',
                icon: markRaw(Delete),
                cancelButtonText: translate('Cancel'),
                confirmButtonText: translate('Yes, Update'),
            }
        );
      } catch {
        return; // User cancelled
      }

      const billCount = this.subscription?.bill_count || 0;
      const billTimes = this.editForm.bill_times;
      if (billTimes > 0 && billTimes < billCount) {
        this.$notify({
          type: 'error',
          title: translate('Validation Error'),
          /* translators: %1$s: number of payments already made */
          message: translate('Bill times cannot be less than the number of payments already made (%1$s).', billCount)
        });
        return;
      }

      this.loadingState.save = true;
      try {
        await this.$put(
            `orders/${this.orderId}/subscriptions/${this.subscription.id}/update`,
            { data: this.editForm }
        );
        this.$notify({
          type: "success",
          title: translate("Success"),
          message: translate("Subscription updated successfully")
        });
        this.editSubscriptionModal = false;
        this.$emit("reload");
      } catch (e) {
        this.$notify({
          type: "error",
          title: translate("Error"),
          message: translate(e.response?.data?.message || "Failed to update subscription")
        });
      } finally {
        this.loadingState.save = false;
      }
    },

    confirmEditVendorIds() {
      this.vendorIdsForm = {
        vendor_subscription_id: this.subscription.vendor_subscription_id || '',
        vendor_customer_id: this.subscription.vendor_customer_id || ''
      };
      this.vendorIdsVerification = null;
      this.vendorIdsModal = true;
    },

    // Mirrors the server-side character set so an obvious typo fails before a
    // round-trip. Empty is allowed — clearing an ID is a valid correction.
    invalidVendorId(value) {
      return !!value && !/^[a-zA-Z0-9_.-]+$/.test(value);
    },

    async verifyVendorIds() {
      if (this.loadingState.verifyVendorIds) return;

      this.vendorIdsVerification = null;
      this.loadingState.verifyVendorIds = true;
      try {
        const res = await this.$post(
            `orders/${this.orderId}/subscriptions/${this.subscription.id}/verify-vendor-ids`,
            { data: this.vendorIdsForm }
        );
        const found = res?.verification || {};
        const parts = [
          found.status,
          found.amount ? `${found.amount} ${found.currency || ''}`.trim() : '',
          found.customer_id ? `${translate('customer')}: ${found.customer_id}` : ''
        ].filter(Boolean);
        this.vendorIdsVerification = { ok: true, summary: parts.join(' · ') };
      } catch (e) {
        this.vendorIdsVerification = {
          ok: false,
          // Rest.js rejects with {data, status_code} — no `response` wrapper, so the
          // gateway's own message is at e.data.message.
          message: e?.data?.message || translate('Could not verify this ID at the payment gateway.')
        };
      } finally {
        this.loadingState.verifyVendorIds = false;
      }
    },

    async confirmSaveVendorIds() {
      if (this.invalidVendorId(this.vendorIdsForm.vendor_subscription_id) ||
          this.invalidVendorId(this.vendorIdsForm.vendor_customer_id)) {
        this.$notify({
          type: 'error',
          title: translate('Validation Error'),
          message: translate('Vendor IDs may only contain letters, numbers, dots, dashes and underscores.')
        });
        return;
      }

      try {
        await ElMessageBox.confirm(
            translate('Are you sure you want to change the vendor IDs? Future renewals and gateway webhooks will use the new IDs.'),
            translate('Confirm Update'),
            {
              type: 'warning',
              icon: markRaw(Edit),
              cancelButtonText: translate('Cancel'),
              confirmButtonText: translate('Yes, Update'),
            }
        );
      } catch {
        return; // User cancelled
      }

      this.loadingState.saveVendorIds = true;
      try {
        await this.$put(
            `orders/${this.orderId}/subscriptions/${this.subscription.id}/vendor-ids`,
            { data: this.vendorIdsForm }
        );
        this.$notify({
          type: "success",
          title: translate("Success"),
          message: translate("Vendor IDs updated successfully")
        });
        this.vendorIdsModal = false;
        this.$emit("reload");
      } catch (e) {
        this.$notify({
          type: "error",
          title: translate("Error"),
          message: e?.data?.message || translate("Failed to update vendor IDs")
        });
      } finally {
        this.loadingState.saveVendorIds = false;
      }
    },

    confirmCancel() {
      this.cancelSubscriptionModal = true;
    },
    confirmSendReminder() {
      const reminderLabel = this.reminderLabel;
      ElMessageBox.confirm(
          translate('Are you sure you want to send this reminder email now?'),
          reminderLabel,
          {
            type: "info",
            icon: markRaw(Promotion),
            cancelButtonText: translate("Cancel"),
            confirmButtonText: translate("Send Now"),
            beforeClose: async (action, instance, done) => {
              if (action === "confirm") {
                try {
                  instance.confirmButtonLoading = true;
                  this.loadingState.sendReminder = true;
                  const response = await this.$post('email-notification/send-manual-reminder', {
                    event: this.reminderEvent,
                    entity_id: this.subscription.id
                  });
                  this.$notify({
                    type: "success",
                    title: translate("Success"),
                    message: response.message || translate("Reminder sent successfully")
                  });
                } catch (e) {
                  this.$notify({
                    type: "error",
                    title: translate("Error"),
                    message: e.data?.message || translate("Failed to send reminder")
                  });
                } finally {
                  instance.confirmButtonLoading = false;
                  this.loadingState.sendReminder = false;
                  done();
                }
              } else done();
            }
          }
      );
    },

    async confirmCreateRenewal() {
      const isSystem = this.isSystemSubscription;
      try {
        await ElMessageBox.confirm(
            isSystem
                ? translate('Create the next renewal and charge it now? One attempt runs immediately; a decline falls back to automatic retries.')
                : translate('Create a renewal now for the upcoming billing period?'),
            isSystem ? translate('Charge Next Renewal Now') : translate('Create Renewal Now'),
            {
              type: isSystem ? 'warning' : 'info',
              cancelButtonText: translate('Cancel'),
              confirmButtonText: isSystem ? translate('Create & Charge') : translate('Create Renewal'),
              beforeClose: async (action, instance, done) => {
                if (action === 'confirm') {
                  try {
                    instance.confirmButtonLoading = true;
                    this.loadingState.createRenewal = true;
                    const response = await this.$post(`orders/${this.orderId}/subscriptions/${this.subscription.id}/create-renewal`);
                    const status = response?.status;
                    this.$notify({
                      type: status === 'failed' ? 'warning' : 'success',
                      title: status === 'failed' ? translate('Charge Failed') : translate('Success'),
                      message: translate(response?.message || (isSystem ? 'Charge attempt completed' : 'Renewal created successfully'))
                    });
                    this.$emit('reload');
                  } catch (e) {
                    this.$notify({
                      type: 'error',
                      title: translate('Error'),
                      message: translate(e.response?.data?.message || (isSystem ? 'Failed to charge the renewal' : 'Failed to create renewal'))
                    });
                  } finally {
                    instance.confirmButtonLoading = false;
                    this.loadingState.createRenewal = false;
                    done();
                  }
                } else done();
              }
            }
        );
      } catch {
        // dialog cancelled
      }
    },

    async confirmChargeNow() {
      const details = this.subscription?.billingInfo?.details || {};
      const cardText = details.brand && details.last_4
          /* translators: %1$s: card brand, %2$s: card last four digits */
          ? translate('%1$s ****%2$s', details.brand, details.last_4)
          : translate('the saved payment method');

      try {
        await ElMessageBox.confirm(
            /* translators: %1$s: the saved payment method, e.g. "visa ****4242" */
            translate('Charge the open renewal order to %1$s now? One attempt will run immediately.', cardText),
            translate('Charge Now'),
            {
              type: 'warning',
              cancelButtonText: translate('Cancel'),
              confirmButtonText: translate('Charge Now'),
              beforeClose: async (action, instance, done) => {
                if (action === 'confirm') {
                  try {
                    instance.confirmButtonLoading = true;
                    this.loadingState.chargeNow = true;
                    const response = await this.$post(`orders/${this.orderId}/subscriptions/${this.subscription.id}/charge-now`);
                    const status = response?.status;

                    this.$notify({
                      type: status === 'failed' ? 'warning' : 'success',
                      title: status === 'failed' ? translate('Charge Failed') : translate('Success'),
                      message: translate(response?.message || 'Charge attempt completed')
                    });

                    // Reload on every outcome — even a failure updates the
                    // charge-state banner (attempt count, last error).
                    this.$emit('reload');
                  } catch (e) {
                    this.$notify({
                      type: 'error',
                      title: translate('Error'),
                      message: translate(e.response?.data?.message || 'Failed to run the charge attempt')
                    });
                  } finally {
                    instance.confirmButtonLoading = false;
                    this.loadingState.chargeNow = false;
                    done();
                  }
                } else done();
              }
            }
        );
      } catch {
        // dialog cancelled
      }
    },

    async confirmSkipRenewal() {
      const newDate = (() => {
        const sub = this.subscription;
        if (!sub?.next_billing_date) return '';
        const intervalDays = {
          daily: 1, weekly: 7, monthly: 30, quarterly: 90, half_yearly: 182, yearly: 365
        }[sub.billing_interval] || 30;
        // Safari's Date constructor rejects space-separated datetimes — normalize
        // the API's 'YYYY-MM-DD HH:mm:ss' to ISO before parsing.
        const ts = new Date(String(sub.next_billing_date).replace(' ', 'T'));
        if (isNaN(ts.getTime())) return '';
        ts.setDate(ts.getDate() + intervalDays);
        // Mirror the backend: if the billing date is overdue, keep advancing whole
        // intervals until the new date is in the future, so the preview matches what
        // skipNextPeriod() will actually persist.
        const now = new Date();
        while (ts <= now) {
          ts.setDate(ts.getDate() + intervalDays);
        }
        return ts.toLocaleDateString();
      })();

      try {
        await ElMessageBox.prompt(
            newDate
                /* translators: %1$s: the date the following renewal order will be created around */
                ? translate('Skip the next billing period? The following renewal order will be created around %1$s.', newDate)
                : translate('Skip the next billing period? The next_billing_date will advance one interval into the future.'),
            translate('Skip Next Period'),
            {
              type: 'warning',
              cancelButtonText: translate('Cancel'),
              confirmButtonText: translate('Skip Next Period'),
              inputType: 'textarea',
              inputPlaceholder: translate('Reason (optional) — shown in the timeline and audit trail'),
              inputValidator: () => true,
              beforeClose: async (action, instance, done) => {
                if (action === 'confirm') {
                  try {
                    instance.confirmButtonLoading = true;
                    this.loadingState.skipRenewal = true;
                    const response = await this.$post(`orders/${this.orderId}/subscriptions/${this.subscription.id}/skip-renewal`, {
                      reason: (instance.inputValue || '').trim()
                    });
                    this.$notify({
                      type: 'success',
                      title: translate('Success'),
                      message: response.message || translate('Billing period skipped successfully')
                    });
                    this.$emit('reload');
                  } catch (e) {
                    this.$notify({
                      type: 'error',
                      title: translate('Error'),
                      message: translate(e.response?.data?.message || 'Failed to skip period')
                    });
                  } finally {
                    instance.confirmButtonLoading = false;
                    this.loadingState.skipRenewal = false;
                    done();
                  }
                } else done();
              }
            }
        );
      } catch {
        // dialog cancelled
      }
    },

    confirmReactivate() {
      if (!this.subscription.vendor_subscription_id && !this.isStoreBilled) return;
      ElMessageBox.confirm(
          translate("Reactivate the canceled subscription?"),
          translate("Warning"),
          {
            type: "warning",
            icon: markRaw(Delete),
            cancelButtonText: translate("Back"),
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
                    title: translate("Success"),
                    message: translate("Subscription reactivated successfully")
                  });
                  this.$emit("reload");
                } catch (e) {
                  this.$notify({
                    type: "error",
                    title: translate("Error"),
                    message: translate(e.response?.data?.message || "Failed to reactivate subscription")
                  });
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
.fct-skip-badge {
  margin-left: 8px;
  cursor: default;

  &:focus-visible {
    outline: 2px solid var(--el-color-primary);
    outline-offset: 2px;
  }
}
</style>
