export default class SubscriptionHelper {
    static canBeUpdated(subscription) {
        return [
            'active',
            'on-pause',
            'paused',
            'trialing',
        ].includes(subscription?.value.overridden_status ? subscription?.value?.overridden_status : subscription?.value?.status);
    }

    static canBeUpgraded(subscription) {
        return subscription.value.can_upgrade;
    }

    static canBeReactivated(subscription) {
        return subscription.value?.reactivate_url;
    }

    static isCancelable(subscription) {
        // if subscription has bill times, basically is installments, then it is not cancelable
        // if subscription has bill times, basically is installments, then it is not cancelable from the customer profile
        if (subscription?.value?.bill_times > 1) {
            return false;
        }
        return [
            'active',
            'on-pause',
            'pending',
            'trialing',
        ].includes(subscription?.value?.status);
    }
}
