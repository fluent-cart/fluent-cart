<script setup>
import translate from "@/utils/translator/Translator";
import Empty from "@/Bits/Components/Table/Empty.vue";
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";
import ConvertedTime from "@/Bits/Components/ConvertedTime.vue";
import GroupTermsPanel from "./GroupTermsPanel.vue";
import { ref } from "vue";
import Confirmation from "@/utils/Confirmation";
import Notify from "@/utils/Notify";
import Rest from "@/utils/http/Rest";

const props = defineProps({
    attrGroupsTable: {
        type: Object,
        required: true,
    }
});

const emit = defineEmits(['edit']);

const isDeleting = ref(false);
const tableRef = ref(null);
const panelRefs = {};
const getGroupTypeLabel = (group) => {
    const typeMap = {
        options: translate('Options'),
        color: translate('Color'),
        image: translate('Image'),
    };
    const type = group.settings?.type || 'options';
    return typeMap[type] || type;
};

const getGroupStylingLabel = (group) => {
    const stylingMap = {
        dropdown: translate('Dropdown'),
        button: translate('Button'),
    };
    const styling = group.settings?.styling || '';
    return stylingMap[styling] || '--';
};

const handleDelete = (row) => {
    if (isDeleting.value) return;

    if (row.used_terms_count > 0) {
        Notify.error(translate("Group's terms are already in use, please remove the terms from product first."));
        return;
    }

    Confirmation.ofDelete(
        translate('Are you sure you want to delete this group? This action is not recoverable.'),
        translate('Confirm Delete!'),
        { confirmButtonText: translate('Yes, Delete this group') }
    ).then(() => {
        isDeleting.value = true;
        Rest.delete('options/attr/group/' + row.id)
            .then(response => {
                Notify.success(response.message);
                props.attrGroupsTable.fetch();
            })
            .catch(errors => {
                Notify.error(errors.data?.message || translate('Delete failed'));
            })
            .finally(() => {
                isDeleting.value = false;
            });
    }).catch(() => {});
};

const handleExpandChange = (row, expandedRows) => {
    if (!tableRef.value) return;
    const isExpanding = expandedRows.some(r => r.id === row.id);
    if (!isExpanding) return;
    expandedRows.forEach(expandedRow => {
        if (expandedRow.id !== row.id) {
            tableRef.value.toggleRowExpansion(expandedRow, false);
        }
    });
};

const handleCommand = (command) => {
    if (command.action === 'edit') {
        emit('edit', command.row);
    } else if (command.action === 'delete') {
        handleDelete(command.row);
    }
};
</script>

<template>
    <el-table ref="tableRef" :data="attrGroupsTable.getTableData()" row-key="id" class="w-full compact-table" @expand-change="handleExpandChange">

        <el-table-column type="expand" :width="80">
            <template #default="scope">
                <GroupTermsPanel
                    :group="scope.row"
                    :ref="el => { if (el) panelRefs[scope.row.id] = el; else delete panelRefs[scope.row.id]; }"
                />
            </template>
        </el-table-column>

        <el-table-column v-if="attrGroupsTable.isColumnVisible('title')" :min-width="150" :label="translate('Title')">
            <template #default="scope">
                <div class="table-cell">{{ scope.row.title || translate('No Name') }}</div>
            </template>
        </el-table-column>

        <el-table-column :min-width="100" :label="translate('Type')">
            <template #default="scope">
                <div class="table-cell">{{ getGroupTypeLabel(scope.row) }}</div>
            </template>
        </el-table-column>

        <el-table-column :min-width="100" :label="translate('Styling')">
            <template #default="scope">
                <div class="table-cell">{{ getGroupStylingLabel(scope.row) }}</div>
            </template>
        </el-table-column>

        <el-table-column :min-width="80" :label="translate('Terms')">
            <template #default="scope">
                <div class="table-cell">{{ scope.row.terms_count || 0 }}</div>
            </template>
        </el-table-column>

        <el-table-column :min-width="120" :label="translate('Date')">
            <template #default="scope">
                <div class="table-cell"><ConvertedTime :date-time="scope.row.created_at"/></div>
            </template>
        </el-table-column>

        <el-table-column :width="80" align="right" :label="translate('Actions')">
            <template #default="scope">
                <div class="table-cell justify-end">
                <el-dropdown
                    trigger="click"
                    class="fct-more-option-wrap"
                    popper-class="fct-dropdown"
                    :disabled="isDeleting"
                    @command="handleCommand"
                    placement="bottom-end"
                >
                    <span class="more-btn mr-2">
                        <DynamicIcon name="More"/>
                    </span>
                    <template #dropdown>
                        <el-dropdown-menu>
                            <el-dropdown-item :command="{ action: 'edit', row: scope.row }">
                                <DynamicIcon name="Edit"/>
                                {{ translate('Edit') }}
                            </el-dropdown-item>
                            <el-dropdown-item
                                :command="{ action: 'delete', row: scope.row }"
                                class="item-destructive"
                            >
                                <DynamicIcon name="Delete"/>
                                {{ translate('Delete') }}
                            </el-dropdown-item>
                        </el-dropdown-menu>
                    </template>
                </el-dropdown>
                </div>
            </template>
        </el-table-column>

        <template #empty>
            <Empty icon="Empty/ListView" :has-dark="true"
                   :text="translate('No attribute groups found!')"/>
        </template>
    </el-table>

</template>
