<template>
    <div class="fct-inventory-export-modal-wrap">
        <fct-button @click="dialogVisible = true" type="primary">
            {{ translate('Export') }}
        </fct-button>

        <fct-dialog
            v-model="dialogVisible"
            :title="translate('Export Inventory')"
            @close="handleClose"
            :append-to-body="true"
        >
            <div class="fct-export-inventory-content">

                <!-- Inventory States Section -->
                <div class="fct-export-states-section">
                    <label class="fct-export-label">
                        {{ translate('Inventory states shown') }}
                    </label>

                    <fct-choice
                        type="radio"
                        v-model="inventoryState"
                        class="fct-export-states-options"
                        :options="[
                            {value: 'all_states', label: translate('All states'), note: translate('Export all inventory states')},
                            {value: 'available', label: translate('Available'), note: translate('Export only available column')}
                        ]"
                    >
                        <template #option="{option}">
                            {{ option.label }}
                            <span>
                                - {{ option.note }}
                            </span>
                        </template>
                    </fct-choice>
                </div>

                <!-- Export Scope Section -->
                <div class="fct-export-scope-section">
                    <label class="fct-export-label">
                        {{ translate('Export') }}
                    </label>

                    <fct-choice
                        v-model="exportScope"
                        type="radio"
                        class="fct-export-options"
                        :options="[
                            {label: translate('Current page'), value: 'current_page'},
                            {label: translate('All variants'), value: 'all_variants'},
                            {
                                label: translate('Selected:') + ' ' + selectedItemsCount + ' ' + (selectedItemsCount > 1 ? translate('variants') : translate('variant')),
                                value: 'selected',
                                disabled: !selectedItemsCount
                            }
                        ]"
                    />
                </div>

                <!-- Export Format Section -->
                <div class="fct-export-format-section">
                    <label class="fct-export-label">
                        {{ translate('Export as') }}
                    </label>

                    <fct-choice
                        v-model="exportFormat"
                        type="radio"
                        class="fct-export-format-options"
                        :options="[
                            {label: translate('CSV for Excel, Numbers, or other spreadsheet programs'), value: 'csv_spreadsheet'},
                            {label: translate('Plain CSV file'), value: 'csv_plain'}
                        ]"
                    />
                </div>
            </div>

            <div class="dialog-footer">
                <fct-button @click="handleClose">
                    {{ translate('Cancel') }}
                </fct-button>
                <fct-button type="primary" @click="handleConfirm">
                    {{ translate('Export Variants') }}
                </fct-button>
            </div>
        </fct-dialog>
    </div>
</template>

<script setup>
import { ref } from 'vue';
import translate from '@/utils/translator/Translator';

defineOptions({
    name: 'ExportModal'
});

const props = defineProps({
    selectedItemsCount: {
        type: Number,
        default: 0
    }
});

const emit = defineEmits(['export']);

const dialogVisible = ref(false);
const exportScope = ref('current_page');
const inventoryState = ref('all_states');
const exportFormat = ref('csv_spreadsheet');

const handleClose = () => {
    dialogVisible.value = false;
};

const handleConfirm = () => {
    emit('export', {
        scope: exportScope.value,
        inventoryState: inventoryState.value,
        format: exportFormat.value
    });
    dialogVisible.value = false;
};
</script>

