<script setup>
import {ref, watch} from "vue";
import translate from "@/utils/translator/Translator";
import * as Card from "@/Bits/Components/Card/Card.js";

const props = defineProps({
    startDate: {
        type: String,
        default: null,
    },
    endDate: {
        type: String,
        default: null,
    },
    disabled: {
        type: Boolean,
        default: false,
    },
});

const emit = defineEmits(['update:startDate', 'update:endDate']);

/*
 * Whether the rule ends at all — held here rather than derived from endDate,
 * because unticking clears the date, and a computed would read that empty date
 * and tick itself straight back on.
 */
const hasEndDate = ref(false);

/*
 * The editor replaces the whole rule object on load and again after a save, so
 * endDate arrives as a new value rather than a mutation. Without this the box
 * would still describe the rule that was on screen before.
 */
watch(() => props.endDate, (value) => {
    hasEndDate.value = !!value;
}, {immediate: true});

const clearEndDate = (enabled) => {
    if (!enabled) {
        emit('update:endDate', null);
    }
};
</script>

<template>
    <Card.Container>
        <Card.Header :title="translate('Schedule')" border_bottom/>
        <Card.Body>
            <div class="fct-pricing-rule-schedule">
                <el-form label-position="top">
                    <el-form-item :label="translate('Start Date & Time')">
                        <el-date-picker
                            :model-value="startDate"
                            type="datetime"
                            value-format="YYYY-MM-DDTHH:mm:ssZ"
                            class="w-full"
                            :placeholder="translate('Immediately')"
                            :disabled="disabled"
                            @update:model-value="(value) => emit('update:startDate', value)"
                        />

                        <div class="form-note mt-2">
                            <p>{{ translate('Time at which the dynamic price becomes active and starts being applied at checkout.') }}</p>
                        </div>
                    </el-form-item>

                    <el-form-item>
                        <el-checkbox
                            v-model="hasEndDate"
                            :label="translate('Set end date & time')"
                            :disabled="disabled"
                            @change="clearEndDate"
                        />
                    </el-form-item>

                    <el-form-item v-if="hasEndDate" :label="translate('End Date & Time')">
                        <el-date-picker
                            :model-value="endDate"
                            type="datetime"
                            value-format="YYYY-MM-DDTHH:mm:ssZ"
                            class="w-full"
                            :placeholder="translate('Never')"
                            :disabled="disabled"
                            @update:model-value="(value) => emit('update:endDate', value)"
                        />

                        <div class="form-note mt-2">
                            <p>{{ translate('Time at which the dynamic price becomes inactive.') }}</p>
                        </div>
                    </el-form-item>
                </el-form>
            </div>
        </Card.Body>
    </Card.Container>
</template>
