<template>
    <div class="fct-order-stats-row">
        <el-skeleton animated v-for="i in 4" :key="i">
            <template #template>
                <div class="p-4 bg-gray-25 rounded dark:bg-primary-500">
                    <div class="flex items-center gap-2 mb-3">
                        <el-skeleton-item variant="p" class="rounded w-10 h-10 block" />
                        <div class="right flex flex-col flex-1 gap-2">
                            <el-skeleton-item variant="p" class="rounded w-4/5 block" />
                            <el-skeleton-item variant="p" class="rounded w-1/2 block" />
                        </div>
                    </div>
                    <div>
                        <el-skeleton-item variant="p" class="rounded w-2/3 block" />
                    </div>
                </div>
            </template>
        </el-skeleton>
    </div>
</template>
