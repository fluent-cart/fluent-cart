<script setup>
import {ref} from 'vue';
import Badge from '@/Bits/Components/Badge.vue';
import UserCan from '@/Bits/Components/Permission/UserCan.vue';
import Empty from '@/Bits/Components/Table/Empty.vue';
import Rest from '@/utils/http/Rest';
import Notify from '@/utils/Notify';
import translate from '@/utils/translator/Translator';
import CurrencyFormatter from '@/utils/support/CurrencyFormatter';
import IconButton from '@/Bits/Components/Buttons/IconButton.vue';
import DynamicIcon from '@/Bits/Components/Icons/DynamicIcon.vue';

const props = defineProps({
    requests: {
        type: Array,
        required: true
    },
    columns: {
        type: Array,
        required: true
    },
    table: {
        type: Object,
        required: true
    }
});

const actingOn = ref(0);
const linkInputs = ref({});

const decide = (row, status) => {
    actingOn.value = row.id;
    Rest.post(`withdrawal-requests/${row.id}/status`, {status})
        .then(() => {
            Notify.success(translate('The request has been updated and the customer was notified by email.'));
            props.table.fetch();
        })
        .catch((error) => {
            Notify.error(error?.message || translate('That action could not be completed.'));
        })
        .finally(() => {
            actingOn.value = 0;
        });
};

const linkOrder = (row) => {
    const orderId = parseInt(linkInputs.value[row.id], 10);
    if (!orderId) {
        Notify.error(translate('Please enter a valid order ID.'));
        return;
    }

    actingOn.value = row.id;
    Rest.post(`withdrawal-requests/${row.id}/link-order`, {order_id: orderId})
        .then(() => {
            Notify.success(translate('The request is now linked to the order and marked verified.'));
            props.table.fetch();
        })
        .catch((error) => {
            Notify.error(error?.message || translate('That order could not be linked.'));
        })
        .finally(() => {
            actingOn.value = 0;
        });
};

const statusBadge = (status) => {
    if (status === 'accepted') {
        return 'succeeded';
    }
    if (status === 'declined') {
        return 'failed';
    }
    return 'pending';
};

const rowClassName = ({row}) => row.selected_items?.length ? '' : 'fctwd-no-expand';

const verifiedLabel = (row) => {
    if (row.auth_mode === 'account_owner') return translate('Verified User');
    if (row.auth_mode === 'email_verified') return translate('Verified Mail');
    return translate('Unverified');
};

const fmtCents = (cents, currency) => CurrencyFormatter.formatForOrder(cents, currency);
</script>

<template>
    <el-table :data="requests" class="w-full compact-table" :row-class-name="rowClassName">
        <el-table-column type="expand" width="36">
            <template #default="scope">
                <div v-if="scope.row.selected_items?.length" class="px-6 py-3">
                    <el-table :data="scope.row.selected_items" size="small" class="fctwd-items-inner-table">
                        <el-table-column prop="title" :label="translate('Item')"/>
                        <el-table-column prop="qty" :label="translate('Qty')" :width="80"/>
                        <el-table-column :label="translate('Unit refund')" :width="180">
                            <template #default="{ row }">
                                <template v-if="row.unit_refund_amount">
                                    <span>{{ fmtCents(row.unit_price, scope.row.estimated_refund_currency) }}</span>
                                    <span v-if="row.unit_refund_amount > row.unit_price" class="fctwd-tax-badge">
                                        +{{ fmtCents(row.unit_refund_amount - row.unit_price, scope.row.estimated_refund_currency) }} tax
                                    </span>
                                </template>
                                <span v-else class="text-system-light dark:text-gray-500">—</span>
                            </template>
                        </el-table-column>
                        <el-table-column :label="translate('Line total')" :width="120" align="right">
                            <template #default="{ row }">
                                <strong v-if="row.unit_refund_amount">
                                    {{ fmtCents(row.unit_refund_amount * (row.qty || 1), scope.row.estimated_refund_currency) }}
                                </strong>
                                <span v-else class="text-system-light dark:text-gray-500">—</span>
                            </template>
                        </el-table-column>
                    </el-table>
                </div>
            </template>
        </el-table-column>

        <el-table-column :label="translate('Reference')" :width="130">
            <template #default="scope">
                <div class="table-cell">
                    <div class="flex flex-col gap-1">
                        <span><Badge :status="statusBadge(scope.row.status)" size="small"/></span>
                        <span class="font-medium text-system-dark dark:text-gray-50">{{ scope.row.reference }}</span>
                    </div>
                </div>
            </template>
        </el-table-column>

        <el-table-column v-if="columns.indexOf('customer') !== -1" :label="translate('Customer')" min-width="190">
            <template #default="scope">
                <div class="table-cell">
                    <div>
                        <div class="text-system-dark dark:text-gray-50">{{ scope.row.name }}</div>
                        <div class="text-xs text-system-light dark:text-gray-400">{{ scope.row.email }}</div>
                        <div v-if="scope.row.message" class="text-xs text-system-light dark:text-gray-400 mt-0.5">{{ scope.row.message }}</div>
                    </div>
                </div>
            </template>
        </el-table-column>

        <el-table-column v-if="columns.indexOf('order') !== -1" :label="translate('Order')" :width="140">
            <template #default="scope">
                <div class="table-cell">
                    <div>
                        <router-link
                            v-if="scope.row.order_id"
                            class="link"
                            :to="{name: 'view_order', params: {order_id: scope.row.order_id}}"
                        >
                            #{{ scope.row.order_id }}
                        </router-link>
                        <span v-else class="text-system-light dark:text-gray-400">{{ translate('Not matched') }}</span>
                        <div class="text-xs text-system-light dark:text-gray-400">{{ scope.row.order_reference }}</div>
                    </div>
                </div>
            </template>
        </el-table-column>

        <el-table-column v-if="columns.indexOf('received') !== -1" :label="translate('Received')" :width="170">
            <template #default="scope">
                <div class="table-cell">
                    <span class="text-system-mid dark:text-gray-300">{{ scope.row.received_at }}</span>
                </div>
            </template>
        </el-table-column>

        <el-table-column :label="translate('Verified')" :width="140">
            <template #default="scope">
                <div class="table-cell">
                    <Badge :status="scope.row.is_verified ? 'active' : 'inactive'"
                           :text="verifiedLabel(scope.row)"
                           size="small" :hide-icon="true"/>
                </div>
            </template>
        </el-table-column>

        <el-table-column :label="translate('Est. Refund')" :width="140">
            <template #default="scope">
                <div class="table-cell">
                    <span v-if="scope.row.estimated_refund" class="font-medium text-system-dark dark:text-gray-50">
                        {{ fmtCents(scope.row.estimated_refund, scope.row.estimated_refund_currency) }}
                    </span>
                    <span v-else class="text-system-light dark:text-gray-500">—</span>
                </div>
            </template>
        </el-table-column>

        <el-table-column :label="translate('Actions')" min-width="260" header-align="right">
            <template #default="scope">
                <UserCan permission="orders/manage">
                    <div class="table-cell">
                    <div class="fct-btn-group sm justify-end w-full">
                        <template v-if="scope.row.status === 'pending'">
                            
                            <el-tooltip :content="translate('Accept')" placement="top">
                                <IconButton
                                    size="small"
                                    bg="success"
                                    tag="button"
                                    :aria-label="translate('Accept')"
                                    :title="translate('Accept')"
                                    :disabled="actingOn === scope.row.id"
                                    :loading="actingOn === scope.row.id"
                                    @click="decide(scope.row, 'accepted')"
                                >
                                    <DynamicIcon name="Check"/>
                                </IconButton>
                            </el-tooltip>

                            <el-tooltip :content="translate('Decline')" placement="top">
                                <IconButton
                                    size="small"
                                    bg="danger"
                                    tag="button"
                                    :aria-label="translate('Decline')"
                                    :title="translate('Decline')"
                                    :disabled="actingOn === scope.row.id"
                                    :loading="actingOn === scope.row.id"
                                    @click="decide(scope.row, 'declined')"
                                >
                                    <DynamicIcon name="Cross"/>
                                </IconButton>
                            </el-tooltip>
                        </template>

                        <template v-if="!scope.row.order_id">
                            <el-popover
                                placement="bottom"
                                :width="200"
                                trigger="click"
                            >
                                <template #reference>
                                    <span>
                                        <el-tooltip :content="translate('Link order')" placement="top">
                                            <IconButton
                                                size="small"
                                                tag="button"
                                                :aria-label="translate('Link order')"
                                                :title="translate('Link order')"
                                                :disabled="actingOn === scope.row.id"
                                                :loading="actingOn === scope.row.id"
                                            >
                                                <DynamicIcon name="Link"/>
                                            </IconButton>
                                        </el-tooltip>
                                    </span>
                                </template>
                                <div class="fctwd-link-order-input-wrap">
                                    <el-input
                                        v-model="linkInputs[scope.row.id]"
                                        size="small"
                                        :placeholder="translate('Order ID')"
                                    />
                                    <el-button
                                        size="small"
                                        type="primary"
                                        :loading="actingOn === scope.row.id"
                                        :disabled="actingOn === scope.row.id"
                                        @click="linkOrder(scope.row)"
                                    >
                                        {{ translate('Confirm') }}
                                    </el-button>
                                </div>
                            </el-popover>
                        </template>
                    </div>
                    </div>
                </UserCan>
            </template>
        </el-table-column>

        <template #empty>
            <Empty icon="Empty/Order" has-dark :text="translate('No withdrawal requests found based on your filter')"/>
        </template>
    </el-table>
</template>

<style scoped>
.fctwd-no-expand :deep(.el-table__expand-column .el-table__expand-icon) {
    visibility: hidden;
    pointer-events: none;
}

.fctwd-items-inner-table {
    --el-table-border-color: transparent;
}

.fctwd-tax-badge {
    margin-left: 4px;
    color: #d97706;
    font-size: 11px;
}
.fctwd-link-order-input-wrap {
    display: grid;
    gap: 10px;
}
.fctwd-link-order-input-wrap .el-button {
    margin-left: auto;
}
</style>
