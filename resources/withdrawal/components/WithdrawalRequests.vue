<script setup>
import useWithdrawalRequestTable from '@/utils/table-new/WithdrawalRequestTable';
import TableWrapper from '@/Bits/Components/TableNew/TableWrapper.vue';
import PageHeading from '@/Bits/Components/Layout/PageHeading.vue';
import UserCan from '@/Bits/Components/Permission/UserCan.vue';
import translate from '@/utils/translator/Translator';
import WithdrawalRequestTableComponent from './_WithdrawalRequestTable.vue';

const requestTable = useWithdrawalRequestTable();
</script>

<template>
    <div class="fct-withdrawal-requests-page fct-layout-width">
        <PageHeading :title="translate('Withdrawal Requests')"/>

        <UserCan permission="orders/view">
            <TableWrapper :table="requestTable" :classicTabStyle="true">
                <WithdrawalRequestTableComponent
                    v-loading="requestTable.isLoading()"
                    :requests="requestTable.getTableData()"
                    :columns="requestTable.data.columns"
                    :table="requestTable"
                />
            </TableWrapper>
        </UserCan>
    </div>
</template>
