import translate, {translateNumber} from "@/utils/translator/Translator";
import Str from "@/utils/support/Str";

// The sale type the reports use (ReportHelper::saleTypeSql); an installment plan's orders are not subscriptions or renewals
const labels = () => ({
    payment: translate('One-time'),
    subscription: translate('New subscription'),
    renewal: translate('Renewal'),
    installment: translate('Installment sign-up'),
    installment_payment: translate('Installment payment'),
});

export function orderTypeText(order) {
    const type = order.sale_type || order.type;

    // Which payment of the plan this order is, e.g. "Installment 3/6"
    if (isInstallmentOrder(order) && order.installment_number && order.installment_total) {
        /* translators: %1$s is the payment number, %2$s is the number of payments in the plan */
        return translate('Installment %1$s/%2$s', translateNumber(order.installment_number), translateNumber(order.installment_total));
    }

    return labels()[type] || Str.headline(type || '');
}

export function isInstallmentOrder(order) {
    return ['installment', 'installment_payment'].includes(order.sale_type);
}
