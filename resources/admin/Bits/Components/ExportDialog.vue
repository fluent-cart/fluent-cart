<script setup>
import { computed, ref, watch } from 'vue';
import { CircleCheckFilled } from '@element-plus/icons-vue';
import translate from '@/utils/translator/Translator';
import Animation from '@/Bits/Components/Animation.vue';
import ProFeatureNotice from '@/Bits/Components/ProFeatureNotice.vue';

defineOptions({
    name: 'ExportDialog'
});

const props = defineProps({
    modelValue: {
        type: Boolean,
        default: false
    },
    title: {
        type: String,
        default: ''
    },
    selectedCount: {
        type: Number,
        default: 0
    },
    currentPageCount: {
        type: Number,
        default: 0
    },
    totalCount: {
        type: Number,
        default: 0
    },
    itemLabelSingular: {
        type: String,
        default: ''
    },
    itemLabelPlural: {
        type: String,
        default: ''
    },
    hasActiveFilter: {
        type: Boolean,
        default: false
    },
    exportState: {
        type: Object,
        required: true
    }
});

const emit = defineEmits(['update:modelValue', 'export']);
const exportScope = ref('current_page');
const exportFormat = ref('csv');
const selectedColumnKeys = ref(new Set());
const selectedModuleKeys = ref(new Set());

const dialogVisible = computed({
    get() {
        return props.modelValue;
    },
    set(value) {
        emit('update:modelValue', value);
    }
});

const schema = computed(() => props.exportState.schema.value || {
    formats: [],
    csv_columns: [],
    json_modules: []
});
const availableFormats = computed(() => schema.value.formats || []);
const columns = computed(() => schema.value.csv_columns || []);
const modules = computed(() => schema.value.json_modules || []);
const selectedColumnCount = computed(() => selectedColumnKeys.value.size);
const selectedModuleCount = computed(() => selectedModuleKeys.value.size);
const areAllColumnsSelected = computed(() => {
    return columns.value.length > 0 && selectedColumnCount.value === columns.value.length;
});
const isColumnSelectionPartial = computed(() => {
    return selectedColumnCount.value > 0 && !areAllColumnsSelected.value;
});
const isCsv = computed(() => exportFormat.value === 'csv');
const isJson = computed(() => exportFormat.value === 'json');
const showConfig = computed(() => {
    return props.exportState.isProActive
        && !props.exportState.isSchemaLoading.value
        && schema.value.entity
        && !props.exportState.isExporting.value
        && !props.exportState.isComplete.value
        && !props.exportState.hasError.value;
});
const hasSelectionError = computed(() => {
    return isCsv.value
        ? selectedColumnCount.value === 0
        : selectedModuleCount.value === 0;
});
const isExportDisabled = computed(() => {
    const hasInvalidScope = (exportScope.value === 'current_page' && !props.currentPageCount)
        || (exportScope.value === 'selected' && !props.selectedCount)
        || (exportScope.value === 'search_results' && !searchResultsCount.value);

    return props.exportState.isExporting.value || hasSelectionError.value || hasInvalidScope;
});
const singularLabel = computed(() => props.itemLabelSingular || translate('item'));
const pluralLabel = computed(() => props.itemLabelPlural || translate('items'));
const searchResultsCount = computed(() => props.hasActiveFilter ? props.totalCount : 0);
const supportsDirectWriter = computed(() => typeof window.showSaveFilePicker === 'function');

const getCountLabel = (count) => count === 1 ? singularLabel.value : pluralLabel.value;
const allScopeLabel = computed(() => {
    const label = translate('All %s', pluralLabel.value);

    return props.hasActiveFilter
        ? translate('%1$s (%2$s)', label, translate('filter applied'))
        : label;
});
const searchScopeLabel = computed(() => {
    return translate(
        '%1$s %2$s matching the current view',
        searchResultsCount.value,
        getCountLabel(searchResultsCount.value)
    );
});

const getFormatDescription = (format) => {
    return format.value === 'json'
        ? translate('JSON preserves selected database modules and their related rows.')
        : translate('CSV creates one row per record and works well in spreadsheet apps.');
};

const initializeSelections = () => {
    exportFormat.value = availableFormats.value[0]?.value || 'csv';
    selectedColumnKeys.value = new Set(
        columns.value.filter((column) => column.default_selected).map((column) => column.key)
    );
    selectedModuleKeys.value = new Set(
        modules.value
            .filter((module) => module.default_selected || module.required)
            .map((module) => module.key)
    );
};

const toggleColumn = (key) => {
    const next = new Set(selectedColumnKeys.value);
    next.has(key) ? next.delete(key) : next.add(key);
    selectedColumnKeys.value = next;
};

const toggleModule = (module) => {
    if (module.required) {
        return;
    }

    const next = new Set(selectedModuleKeys.value);
    next.has(module.key) ? next.delete(module.key) : next.add(module.key);
    selectedModuleKeys.value = next;
};

const selectAllColumns = () => {
    selectedColumnKeys.value = new Set(columns.value.map((column) => column.key));
};

const deselectAllColumns = () => {
    selectedColumnKeys.value = new Set();
};

const toggleAllColumns = (isChecked) => {
    isChecked ? selectAllColumns() : deselectAllColumns();
};

watch(() => props.modelValue, (isVisible) => {
    if (!isVisible) {
        return;
    }

    exportScope.value = props.currentPageCount ? 'current_page' : 'all';
    if (!props.exportState.isExporting.value) {
        props.exportState.resetState();
    }
    props.exportState.loadSchema()
        .then(() => {
            if (!props.exportState.isExporting.value) {
                initializeSelections();
            }
        })
        .catch(() => undefined);
});

const handleExport = () => {
    if (hasSelectionError.value || (exportScope.value === 'search_results' && !searchResultsCount.value)) {
        return;
    }

    emit('export', {
        scope: exportScope.value,
        format: exportFormat.value,
        columns: Array.from(selectedColumnKeys.value),
        modules: Array.from(selectedModuleKeys.value)
    });
};

const handleClose = () => {
    if (props.exportState.isExporting.value) {
        props.exportState.cancelExport();
    }
    dialogVisible.value = false;
};

const handleCancel = () => {
    if (props.exportState.isExporting.value) {
        props.exportState.cancelExport();
    } else {
        dialogVisible.value = false;
    }
};
</script>

<template>
    <el-dialog
        v-model="dialogVisible"
        :title="title || translate('Export')"
        :append-to-body="true"
        :close-on-click-modal="!exportState.isExporting.value"
        :close-on-press-escape="!exportState.isExporting.value"
        class="fct-export-dialog"
        @close="handleClose"
    >
        <ProFeatureNotice
            v-if="!exportState.isProActive"
            :title="translate('Advanced data export is a Pro feature')"
            :text="translate('Export configurable CSV files or schema-rich JSON data directly from your browser.')"
            placement="feature_lock_data_export"
        />

        <div v-else-if="exportState.isSchemaLoading.value" class="fct-export-loading">
            <div class="fct-export-loading-line"></div>
            <div class="fct-export-loading-line is-short"></div>
            <div class="fct-export-loading-grid">
                <div v-for="index in 6" :key="index" class="fct-export-loading-option"></div>
            </div>
        </div>

        <el-alert
            v-else-if="exportState.schemaError.value"
            :title="exportState.schemaError.value"
            type="error"
            :closable="false"
            show-icon
        />

        <div v-if="showConfig" class="fct-export-dialog-content">
            <section class="fct-export-section">
                <div class="fct-export-section-heading">
                    <span class="fct-export-label">
                        {{ translate('Records to export') }}
                        <span class="fct-export-section-hint">
                            &lpar;{{ translate('Choose which records to include') }}&rpar;
                        </span>
                    </span>
                </div>

                <el-select v-model="exportScope" class="fct-export-options">
                    <el-option
                        value="current_page"
                        :disabled="!currentPageCount"
                        :label="currentPageCount
                            ? `${translate('Current page')} (${currentPageCount} ${getCountLabel(currentPageCount)})`
                            : translate('Current page')"
                    />
                    <el-option value="all" :label="allScopeLabel" />
                    <el-option
                        value="selected"
                        :disabled="!selectedCount"
                        :label="`${translate('Selected')} (${selectedCount} ${getCountLabel(selectedCount)})`"
                    />
                    <el-option
                        value="search_results"
                        :disabled="!searchResultsCount"
                        :label="searchScopeLabel"
                    />
                </el-select>
            </section><!-- .fct-export-section -->

            <section class="fct-export-section">
                <div class="fct-export-section-heading">
                    <span class="fct-export-label">{{ translate('File format') }}</span>
                </div>

                <div class="fct-export-format-options">
                    <button
                        v-for="format in availableFormats"
                        :key="format.value"
                        type="button"
                        class="fct-export-format-card"
                        :class="{'is-selected': exportFormat === format.value}"
                        :aria-pressed="exportFormat === format.value"
                        @click="exportFormat = format.value"
                    >
                        <span class="fct-export-format-name">
                            {{ format.label }}
                        </span>
                        <span class="fct-export-format-description">
                            {{ getFormatDescription(format) }}
                        </span>
                        <el-icon class="fct-export-format-check">
                            <CircleCheckFilled />
                        </el-icon>
                    </button>
                </div>
            </section><!-- .fct-export-section -->

            <section v-if="isCsv" class="fct-export-section fct-csv-columns-section">
                <div class="fct-export-selection-heading">
                    <div class="fct-export-selection-heading-left">
                        <span class="fct-export-label">
                            {{ translate('CSV columns') }}
                        </span>
                        <span class="fct-export-selection-count">
                            &lpar;{{ translate('%1$s of %2$s selected', selectedColumnCount, columns.length) }}&rpar;
                        </span>
                    </div>
                </div>

                <div class="fct-export-columns-wrap">
                    <el-checkbox
                        :model-value="areAllColumnsSelected"
                        :indeterminate="isColumnSelectionPartial"
                        :disabled="!columns.length"
                        @change="toggleAllColumns"
                    >
                        {{ translate('Select all') }}
                    </el-checkbox>

                    <div class="fct-export-columns-grid">
                        <el-checkbox
                            v-for="column in columns"
                            :key="column.key"
                            :model-value="selectedColumnKeys.has(column.key)"
                            @change="toggleColumn(column.key)"
                        >
                            {{ column.label }}
                        </el-checkbox>
                    </div>
                </div>
            </section><!-- .fct-export-section -->

            <section v-if="isJson" class="fct-export-section fct-json-modules-section">
                <div class="fct-export-selection-heading">
                    <div class="fct-export-selection-heading-left">
                        <span class="fct-export-label">
                            {{ translate('Data modules') }}
                        </span>
                        <span class="fct-export-selection-count">
                            &lpar;{{ translate('%s selected', selectedModuleCount) }}&rpar;
                        </span>
                    </div>
                </div>
                <div class="fct-export-modules">
                    <div
                        v-for="module in modules"
                        :key="module.key"
                        class="fct-export-module"
                        :class="{
                            'is-selected': selectedModuleKeys.has(module.key),
                            'is-required': module.required
                        }"
                        @click="toggleModule(module)"
                    >
                        <el-checkbox
                            :model-value="selectedModuleKeys.has(module.key)"
                            :disabled="module.required"
                            @click.stop
                            @change="toggleModule(module)"
                        >
                            {{ module.label }}
                            <small v-if="module.required" class="fct-export-required">
                                {{ translate('Required') }}
                            </small>
                        </el-checkbox>
                        <span>{{ module.description }}</span>
                    </div>
                </div>
                <el-alert
                    v-if="schema.sensitive_values_redacted"
                    :title="translate('Authentication hashes, payment tokens, and other credentials are excluded or redacted from JSON exports.')"
                    type="info"
                    :closable="false"
                    show-icon
                />
            </section><!-- .fct-export-section -->

            <el-alert
                v-if="hasSelectionError"
                :title="isCsv ? translate('Select at least one CSV column') : translate('Select at least one data module')"
                type="warning"
                :closable="false"
                show-icon
            />

            <p class="fct-export-memory-note">
                {{
                    supportsDirectWriter
                        ? translate('Large exports are written to disk batch by batch to keep browser memory low.')
                        : translate('This browser prepares the final download in memory. For very large exports, use a Chromium-based browser for direct-to-disk writing.')
                }}
            </p>
        </div>

        <div v-if="exportState.isExporting.value" class="fct-export-progress-section" role="status" aria-live="polite">
            <div class="fct-export-progress-title">
                {{ translate('Exporting %s', pluralLabel) }}
            </div>
            <div class="fct-export-progress-meta">
                <span class="fct-export-progress-text">
                    {{
                        exportState.progress.value.total
                            ? translate(
                                '%1$s of %2$s records written',
                                exportState.progress.value.fetched,
                                exportState.progress.value.total
                            )
                            : translate('%s records written', exportState.progress.value.fetched)
                    }}
                </span>
                <span class="fct-export-progress-percentage">
                    {{ exportState.progress.value.percentage }}%
                </span>
            </div>
            <Animation :visible="true" fade :duration="300">
                <el-progress
                    :percentage="exportState.progress.value.percentage"
                    :stroke-width="6"
                    striped
                    striped-flow
                    :show-text="false"
                    class="fct-export-progress-bar"
                />
            </Animation>
            <p class="fct-export-progress-note">
                {{
                    exportState.writerMode.value === 'direct'
                        ? translate('Writing each completed batch directly to your file.')
                        : translate('Keeping each response batch small while preparing the download.')
                }}
            </p>
        </div>

        <div v-if="exportState.hasError.value" class="fct-export-error-section">
            <el-alert
                :title="exportState.errorMessage.value || translate('Export failed')"
                type="error"
                :closable="false"
                show-icon
            />
        </div>

        <div v-if="exportState.isComplete.value" class="fct-export-complete-section">
            <el-alert
                :title="translate('Export completed successfully')"
                :description="translate('%s records exported', exportState.progress.value.fetched)"
                type="success"
                :closable="false"
                show-icon
            />
        </div>

        <template #footer>
            <div class="dialog-footer">
                <el-button @click="handleCancel">
                    {{ exportState.isExporting.value ? translate('Cancel export') : translate('Close') }}
                </el-button>
                <el-button
                    v-if="showConfig"
                    type="primary"
                    :disabled="isExportDisabled"
                    @click="handleExport"
                >
                    {{ translate('Export file') }}
                </el-button>
            </div>
        </template>
    </el-dialog>
</template>
