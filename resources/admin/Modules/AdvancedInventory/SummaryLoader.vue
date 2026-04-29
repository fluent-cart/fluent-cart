<template>
    <el-skeleton loading="loading" animated>
        <template #template>
            <div class="grid grid-cols-4 gap-5">
                <div v-for="index in 4" :key="index" class="flex items-center gap-2.5">
                    <el-skeleton-item class="w-10 h-10 flex-shrink-0" />
                    <div class="w-full">
                        <el-skeleton-item class="w-[80%]"/>
                        <el-skeleton-item class="w-[50%]"/>
                    </div>
                </div>
            </div>
        </template>
    </el-skeleton>
</template>

<script>
    export default {
        name: 'SummaryLoader',
    }
</script>