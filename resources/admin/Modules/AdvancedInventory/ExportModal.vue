<template>
    <div class="fct-inventory-export-modal-wrap">
        <el-button @click="dialogVisible = true" type="primary">
            {{ translate('Export') }}
        </el-button>

        <el-dialog
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

                    <el-radio-group v-model="inventoryState" class="fct-export-states-options">
                        <el-radio :value="'all_states'">
                            {{ translate('All states') }}
                            <span>
                                - {{ translate('Export all inventory states') }}
                            </span>
                        </el-radio>

                        <el-radio :value="'available'">
                            {{ translate('Available') }}
                            <span>
                                - {{ translate('Export only available column') }}
                            </span>
                        </el-radio>
                    </el-radio-group>
                </div>

                <!-- Export Scope Section -->
                <div class="fct-export-scope-section">
                    <label class="fct-export-label">
                        {{ translate('Export') }}
                    </label>

                    <el-radio-group v-model="exportScope" class="fct-export-options">
                        <el-radio :value="'current_page'">
                            {{ translate('Current page') }}
                        </el-radio>

                        <el-radio :value="'all_variants'">
                            {{ translate('All variants') }}
                        </el-radio>

                        <el-radio
                            :value="'selected'"
                            :disabled="!selectedItemsCount"
                        >
                            {{ translate('Selected:') }}
                            {{ selectedItemsCount }}
                            {{ selectedItemsCount > 1 ? translate('variants') : translate('variant') }}
                        </el-radio>
                    </el-radio-group>
                </div>

                <!-- Export Format Section -->
                <div class="fct-export-format-section">
                    <label class="fct-export-label">
                        {{ translate('Export as') }}
                    </label>

                    <el-radio-group v-model="exportFormat" class="fct-export-format-options">
                        <el-radio :value="'csv_spreadsheet'">
                            {{ translate('CSV for Excel, Numbers, or other spreadsheet programs') }}
                        </el-radio>

                        <el-radio :value="'csv_plain'">
                            {{ translate('Plain CSV file') }}
                        </el-radio>
                    </el-radio-group>
                </div>
            </div>

            <div class="dialog-footer">
                <el-button @click="handleClose">
                    {{ translate('Cancel') }}
                </el-button>
                <el-button type="primary" @click="handleConfirm">
                    {{ translate('Export Variants') }}
                </el-button>
            </div>
        </el-dialog>
    </div>
</template>

<script setup>
import { defineOptions, ref } from 'vue';
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

