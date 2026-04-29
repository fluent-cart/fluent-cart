<template>
    <div class="fct-subscription-details" role="main" aria-labelledby="subscription-details-title">
        <div class="fct-single-order-header flex items-center gap-3 justify-between">
            <div class="flex items-center gap-3">
                <h2 id="subscription-details-title" class="title">
                    {{ translate('Subscription Details') }}
                </h2>

                <Badge :status="subscription.status" size="small" :aria-label="translate('Subscription status: ' + subscription.status)"/>

                <div class="leading-[20px]" v-if="showManageButton">
                    <router-link
                        class="simple-link-btn"
                        :to="{
                            name: 'view_subscription',
                            params: { subscription_uuid: subscription.uuid },
                        }"
                        :aria-label="translate('Manage subscription')"
                    >
                        {{ translate('Manage') }}
                    </router-link>
                </div>
            </div>

            <div class="action-btns">
                <el-dropdown v-if="isCancelable && showCancelButton" trigger="click" :hide-on-click="false" role="menu" aria-haspopup="true" aria-expanded="false">
                    <IconButton tag="button" size="x-small" class="simple-icon-btn" :aria-label="translate('Subscription options')">
                        <DynamicIcon name="More" class="w-[5px]" aria-hidden="true"/>
                    </IconButton>
                    <template #dropdown>
                        <el-dropdown-menu role="menu">
                            <el-dropdown-item role="menuitem">
                                <el-popover
                                    popper-class="fluent-cart-customer-profile-app"
                                    trigger="click"
                                    placement="bottom"
                                    :width="260"
                                    ref="popoverRef"
                                    role="alertdialog"
                                    aria-modal="true"
                                    aria-labelledby="cancel-popover-title"
                                    aria-describedby="cancel-popover-desc"
                                >
                                    <h3 id="cancel-popover-title" class="sr-only">
                                        {{ translate('Cancel Subscription') }}
                                    </h3>

                                    <div id="cancel-popover-desc">
                                        {{ $t('You can continue using the product until the current subscription ends. Cancel Now?') }}
                                    </div>

                                    <div class="flex justify-end gap-2">
                                        <el-button class="el-button--x-small" text @click="closePopover" :aria-label="$t('Cancel')">
                                            {{ $t('Cancel') }}
                                        </el-button>
                                        <el-button 
                                            class="el-button--x-small" 
                                            type="primary" 
                                            :loading="cancelling"
                                            @click="cancelAutoRenew"
                                            :aria-label="$t('Yes, Proceed')"
                                        >
                                            {{ $t('Yes, Proceed') }}
                                        </el-button>
                                    </div>

                                    <template #reference>
                                        <span :aria-label="$t('Cancel')">{{ $t('Cancel') }}</span>
                                    </template>
                                </el-popover>
                            </el-dropdown-item>
                        </el-dropdown-menu>
                    </template>
                </el-dropdown>
            </div>
        </div>


        <div class="fct-customer-dashboard-content-table-item pl-3">
            <div class="left-content grid gap-1">
                <div class="title">
                    {{ subscription.item_name }}
                </div>

                <div class="inline-flex gap-1 items-center" role="status" aria-live="polite">
                    <span class="text" v-html="subscription.payment_info"></span>
                </div>

                <span class="text" v-if="subscription.next_billing_date" role="status" aria-live="polite">
                    {{ translate('Your next billing date is:') }}
                    {{ dateTimeI18(subscription.next_billing_date) }}
                </span>

                <span class="text" v-if="subscription.trial_ends_at" role="status" aria-live="polite">
                    <!-- translators: %s is the trial end date -->
                    {{ translate('Your trial ends on: %s', subscription.trial_ends_at) }}
                </span>

                <span class="text !flex gap-1 items-center" v-if="subscription.status === 'active'" role="status" aria-live="polite">
                    <span>{{ translate('Subscription is active') }}</span>
                    {{ translate('on') }}
                    <span>{{ subscription.billingInfo.payment_type }}</span>

                    <span 
                        v-if="subscription.billingInfo.details?.last_4" 
                        class="mr-2.5" 
                        :aria-label="translate('Card ending with') + ' ' + subscription.billingInfo.details?.last_4"
                    >
                        **** {{ subscription.billingInfo.details?.last_4 }}
                    </span>

                    <updatePaymentInfos 
                        :subscription="subscription" 
                        @fetch="fetchSubscription" 
                        :updateMethod="true"
                        v-if="showUpdateButton && subscription.can_update_payment_method"
                    />
                </span>

            </div>


            <div class="right-content">
                <UpgradePlan
                    v-if="subscription.can_upgrade"
                    :variation_id="subscription.variation_id"
                    :order_hash="order.uuid"
                />
            </div>

        </div>

    </div>
</template>

<script setup>
import translate from "../../translator/Translator";
import Badge from "@/Bits/Components/Badge.vue";
import UpgradePlan from "./UpdatePaymentInfos/UpgradePlan.vue";
import {computed, getCurrentInstance, ref} from "vue";
import Rest from "@/utils/http/Rest";
import Notify from "@/utils/Notify";
import SubscriptionHelper from "../../SubscriptionHelper.js";
import UpdatePaymentInfos from "./UpdatePaymentInfos/index.vue";
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";
import IconButton from "@/Bits/Components/Buttons/IconButton.vue";
import {dateTimeI18} from "../../translator/Translator";

const props = defineProps({
    subscription: Object,
    order: Object,
    subscription_uuid: String,
    showManageButton: {
        type: Boolean,
        default: false
    },
    showCancelButton: {
        type: Boolean,
        default: false
    },
    showUpdateButton: {
        type: Boolean,
        default: false
    }
});

const emit = defineEmits(['fetch-subscription']);

const popoverRef = ref(null);
const cancelling = ref(false);
const subscriptionUuid = ref(props.subscription_uuid);
const $this = getCurrentInstance().ctx;
const subscriptionData = ref(props.subscription);

const isCancelable = computed(() => {
    return SubscriptionHelper.isCancelable(subscriptionData);
});

const fetchSubscription = () => {
    loading.value = true;

    Rest.get(`customer-profile/subscription/${subscriptionUuid.value}`)
        .then((response) => {
            subscriptionData.value = response.subscription;
        })
        .catch((error) => {
            Notify.error(error);
        })
        .finally(() => {
            loading.value = false;
        });
}
const closePopover = () => {
    popoverRef.value?.hide();
}



</script>

