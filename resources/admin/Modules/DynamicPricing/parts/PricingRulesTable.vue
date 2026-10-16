<script setup>
import {ref} from "vue";
import {useRouter} from "vue-router";
import dayjs from "dayjs";
import translate from "@/utils/translator/Translator";
import {$confirm, formatDate} from "@/Bits/common";
import Badge from "@/Bits/Components/Badge.vue";
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";
import RouteCell from "@/Bits/Components/TableNew/RouteCell.vue";
import Empty from "@/Bits/Components/Table/Empty.vue";
import Permission from "@/utils/permission/Permission";
import Rest from "@/utils/http/Rest";
import Notify from "@/utils/Notify";

const props = defineProps({
    pricingRuleTable: {
        type: Object,
        required: true,
    }
});

const router = useRouter();

const canManage = Permission.has('dynamic_pricing/manage');

const pendingStatusUpdates = ref(new Set());

const statusToggleLabel = (row) => {
    /* translators: %1$s: rule display name */
    return translate('Toggle status for %1$s', row.display_name);
};

const actionsLabel = (row) => {
    /* translators: %1$s: rule display name */
    return translate('Actions for %1$s', row.display_name);
};

const priceTypeChip = (priceType) => {
    const chips = {
        discount: {
            label: translate('Discount'),
            icon: 'Tag',
            type: 'success'
        },
        fee: {
            label: translate('Fee'),
            icon: 'Tag',
            type: 'info'
        },
    };
    return chips[priceType] || {label: priceType, icon: 'Tag', type: 'info'};
};

const appliesToChip = (appliesTo) => {
    const chips = {
        checkout: {
            label: translate('Checkout'),
            icon: 'Cart',
            type: 'blue'
        },
        shipping: {
            label: translate('Shipping'),
            icon: 'Truck',
            type: 'blue'
        },
        line_item: {
            label: translate('Line Item'),
            icon: 'ShoppingBag',
            type: 'blue'
        },
    };
    return chips[appliesTo] || {label: appliesTo, icon: 'Cart', type: 'blue'};
};

const whenToApplyLabels = {
    first: translate('First purchase only'),
    renewals: translate('Store managed renewals'),
    upgrades: translate('Upgrades & downgrades'),
};

/*
 * A short note after the chips: the purchases the rule is limited to, then how
 * many conditions it checks. A rule for every purchase with no conditions
 * needs no note.
 */
const ruleNote = (row) => {
    const config = row.config || {};
    const notes = [];

    if (whenToApplyLabels[config.when_to_apply]) {
        notes.push(whenToApplyLabels[config.when_to_apply]);
    }

    // Conditions are stored as groups of rows; every row is one condition.
    const groups = Array.isArray(config.conditions) ? config.conditions : [];
    const count = groups.reduce((total, group) => total + (Array.isArray(group) ? group.length : 0), 0);

    if (count === 1) {
        notes.push(translate('1 condition'));
    } else if (count > 1) {
        /* translators: %1$s: number of conditions */
        notes.push(translate('%1$s conditions', count));
    }

    return notes.join(' · ');
};

/*
 * When the rule runs, in a few words. Schedule dates are stored in UTC and the
 * pricing engine compares them with the current UTC time, so they are compared
 * the same way here.
 */
const createdLabel = (row) => {
    /* translators: %1$s: date the rule was created */
    return translate('Created %1$s', formatDate(row.created_at));
};

const scheduleLabel = (row) => {
    const now = dayjs.utc();
    const start = row.start_date ? dayjs.utc(row.start_date) : null;
    const end = row.end_date ? dayjs.utc(row.end_date) : null;

    if (end && end.isBefore(now)) {
        /* translators: %1$s: end date */
        return translate('Ended %1$s', formatDate(row.end_date));
    }

    if (start && start.isAfter(now)) {
        return end
            ? `${formatDate(row.start_date)} – ${formatDate(row.end_date)}`
            /* translators: %1$s: start date */
            : translate('Starts %1$s', formatDate(row.start_date));
    }

    if (end) {
        /* translators: %1$s: end date */
        return translate('Until %1$s', formatDate(row.end_date));
    }

    return translate('No end date');
};

const toggleStatus = (row) => {
    if (pendingStatusUpdates.value.has(row.id)) {
        return;
    }

    const previousStatus = row.status;
    const newStatus = previousStatus === 'active' ? 'inactive' : 'active';

    pendingStatusUpdates.value.add(row.id);
    row.status = newStatus; // optimistic

    Rest.put(`pricing_rules/${row.id}/status`, {status: newStatus})
        .then(() => {
            Notify.success(translate('Status updated successfully'));
        })
        .catch((errors) => {
            row.status = previousStatus; // rollback
            Notify.error(errors?.data?.message || translate('Failed to update status'));
        })
        .finally(() => {
            pendingStatusUpdates.value.delete(row.id);
        });
};

const handleCommand = (command, row) => {
    if (command === 'edit') {
        router.push({name: 'dynamic_pricing_edit', params: {id: row.id}});
        return;
    }

    if (command === 'delete') {
        deleteRule(row);
    }
};

const deleteRule = (row) => {
    /* translators: %1$s: rule display name */
    $confirm(translate('Are you sure you want to delete this rule : %1$s ?', row.display_name), translate('Confirm Delete!'), {
        confirmButtonText: translate('Yes, Delete!'),
        cancelButtonText: translate('Cancel'),
        type: 'warning'
    }).then(() => {
        Rest.delete(`pricing_rules/${row.id}`)
            .then((response) => {
                Notify.success(response?.message || translate('Pricing rule deleted successfully'));
                props.pricingRuleTable.fetch();
            })
            .catch((errors) => {
                Notify.error(errors?.data?.message || translate('Failed to delete pricing rule'));
            });
    }).catch(() => {
    });
};
</script>

<template>
    <el-table
        :data="pricingRuleTable.getTableData()"
        :class="['w-full', { 'compact-table': canManage }]"
    >
        <el-table-column :label="translate('Rule')" :width="200">
            <template #default="scope">
                <RouteCell v-if="canManage" class="hover:no-underline"
                           :to="{ name: 'dynamic_pricing_edit', params: { id: scope.row.id } }">
                    <div class="flex flex-col gap-1.5">
                        <span class="block">
                            {{ scope.row.display_name }}
                        </span>
                        <span class="block text-xs text-system-light dark:text-gray-400">{{ scope.row.name }}</span>
                    </div>
                </RouteCell>
                <div v-else>
                    <span class="block">{{ scope.row.display_name }}</span>
                    <span class="block text-xs text-system-light dark:text-gray-400">{{ scope.row.name }}</span>
                </div>
            </template>
        </el-table-column>

        <el-table-column v-if="pricingRuleTable.isColumnVisible('type')" :label="translate('What it does')" :width="340">
            <template #default="scope">
                <RouteCell v-if="canManage" class="hover:no-underline"
                           :to="{ name: 'dynamic_pricing_edit', params: { id: scope.row.id } }">
                    <span class="inline-flex items-center flex-wrap gap-1.5 text-sm">
                        <Badge
                            :type="priceTypeChip(scope.row.price_type).type"
                            :text="priceTypeChip(scope.row.price_type).label"
                            :icon="priceTypeChip(scope.row.price_type).icon"
                        />
                        {{ translate('to') }}
                        <Badge
                            :type="appliesToChip(scope.row.applies_to).type"
                            :text="appliesToChip(scope.row.applies_to).label"
                            :icon="appliesToChip(scope.row.applies_to).icon"
                        />
                        <span v-if="ruleNote(scope.row)" class="text-system-mid dark:text-gray-400">
                            · {{ ruleNote(scope.row) }}
                        </span>
                    </span>
                </RouteCell>
                <span v-else class="inline-flex items-center flex-wrap gap-1.5 text-sm">
                    <Badge
                        :type="priceTypeChip(scope.row.price_type).type"
                        :text="priceTypeChip(scope.row.price_type).label"
                        :icon="priceTypeChip(scope.row.price_type).icon"
                    />
                    {{ translate('to') }}
                    <Badge
                        :type="appliesToChip(scope.row.applies_to).type"
                        :text="appliesToChip(scope.row.applies_to).label"
                        :icon="appliesToChip(scope.row.applies_to).icon"
                    />
                    <span v-if="ruleNote(scope.row)" class="text-system-mid dark:text-gray-400">
                        · {{ ruleNote(scope.row) }}
                    </span>
                </span>
            </template>
        </el-table-column>

        <el-table-column v-if="pricingRuleTable.isColumnVisible('date')" :label="translate('Schedule')" :width="160">
            <template #default="scope">
                <RouteCell v-if="canManage" class="hover:no-underline"
                           :to="{ name: 'dynamic_pricing_edit', params: { id: scope.row.id } }">
                    <div class="flex flex-col gap-1.5">
                        <span class="block">{{ scheduleLabel(scope.row) }}</span>
                        <span class="block text-xs text-system-mid dark:text-gray-400">
                            {{ createdLabel(scope.row) }}
                        </span>
                    </div>
                </RouteCell>
                <div v-else>
                    <span class="block">{{ scheduleLabel(scope.row) }}</span>
                    <span class="block text-xs text-system-mid dark:text-gray-400">
                        {{ createdLabel(scope.row) }}
                    </span>
                </div>
            </template>
        </el-table-column>

        <el-table-column v-if="pricingRuleTable.isColumnVisible('status')" :label="translate('Status')" :width="80" align="center">
            <template #default="scope">
                <el-switch
                    v-if="canManage"
                    :model-value="scope.row.status === 'active'"
                    :disabled="pendingStatusUpdates.has(scope.row.id)"
                    :loading="pendingStatusUpdates.has(scope.row.id)"
                    :aria-label="statusToggleLabel(scope.row)"
                    @change="toggleStatus(scope.row)"
                />
                <Badge v-else :status="scope.row.status"/>
            </template>
        </el-table-column>

        <el-table-column v-if="canManage" :label="translate('Actions')" :width="80" align="right">
            <template #default="scope">
                <el-dropdown
                    trigger="click"
                    class="fct-more-option-wrap"
                    popper-class="fct-dropdown"
                    placement="bottom-end"
                    @command="(command) => handleCommand(command, scope.row)"
                >
                    <button class="more-btn me-2" type="button" :aria-label="actionsLabel(scope.row)">
                        <DynamicIcon name="More"/>
                    </button>
                    <template #dropdown>
                        <el-dropdown-menu>
                            <el-dropdown-item command="edit">
                                <DynamicIcon name="Edit"/>
                                {{ translate("Edit") }}
                            </el-dropdown-item>
                            <el-dropdown-item class="item-destructive" command="delete">
                                <DynamicIcon name="Delete"/>
                                {{ translate("Delete") }}
                            </el-dropdown-item>
                        </el-dropdown-menu>
                    </template>
                </el-dropdown>
            </template>
        </el-table-column>

        <template #empty>
            <Empty 
                icon="Empty/ListView" :has-dark="true"
                :text="translate('No pricing rules found!')"
            />
        </template>
    </el-table>
</template>
