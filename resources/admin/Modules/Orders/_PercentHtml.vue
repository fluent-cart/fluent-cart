<template>
    <span v-if="val" class="fct_percent_change" :class="{ fct_percent_negative: val < 0 }">
        <el-icon>
            <arrow-down v-if="val < 0" />
            <arrow-up v-else />
        </el-icon>
        {{Math.abs(val)}}%
    </span>
</template>

<script type="text/babel">
import {calculatePercent} from '@/Bits/common.js';
import {ArrowUp, ArrowDown} from '@element-plus/icons-vue';
import {markRaw} from 'vue';

export default {
    name: 'PercentHtml',
    props: ['current', 'prev'],
    components: {
        ArrowUp: markRaw(ArrowUp),
        ArrowDown: markRaw(ArrowDown)
    },
    methods: {
        calculatePercent
    },
    computed: {
        val() {
            return this.calculatePercent(this.current, this.prev);
        }
    }
}
</script>
