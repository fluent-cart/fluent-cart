<template>
    <div class="fct-customer-dashboard-table">
        <el-table :data="rowsRange" :show-header="false">
            <el-table-column label="#" :width="150">
                <el-skeleton animated class="py-3">
                    <template #template>
                        <el-skeleton-item varient="p" class="w-3/5"/>
                    </template>
                </el-skeleton>
            </el-table-column>
            <el-table-column :width="300" :label="$t('Items')">
                <el-skeleton animated>
                    <template #template>
                        <el-skeleton-item varient="p" class="w-3/5"/>
                    </template>
                </el-skeleton>
            </el-table-column>
            <el-table-column :label="$t('Status')" width="120">
                <el-skeleton animated>
                    <template #template>
                        <el-skeleton-item varient="p" class="w-3/5"/>
                    </template>
                </el-skeleton>
            </el-table-column>
            <el-table-column :label="$t('Amount')" width="90">
                <el-skeleton animated>
                    <template #template>
                        <el-skeleton-item varient="p" class="w-3/5"/>
                    </template>
                </el-skeleton>
            </el-table-column>
        </el-table>
    </div>
</template>
<script type="text/babel">
export default {
    name: 'OrderTableLoader',
    props: {
        rowsRange: {
            type: Array,
            default: [1, 2]
        }
    }
}
</script>
