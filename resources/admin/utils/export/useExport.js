import { onBeforeUnmount, ref } from 'vue';
import Rest from '@/utils/http/Rest';
import Notify from '@/utils/Notify';
import translate from '@/utils/translator/Translator';
import AppConfig from '@/utils/Config/AppConfig';
import BrowserExportWriter from '@/utils/export/BrowserExportWriter';

const emptyProgress = () => ({
    fetched: 0,
    total: 0,
    percentage: 0,
    batches: 0
});

export function useExport(config) {
    const schema = ref(null);
    const isSchemaLoading = ref(false);
    const schemaError = ref('');
    const isExporting = ref(false);
    const isCancelled = ref(false);
    const isComplete = ref(false);
    const hasError = ref(false);
    const errorMessage = ref('');
    const writerMode = ref('');
    const progress = ref(emptyProgress());
    const isProActive = Boolean(AppConfig.get('app_config.isProActive'));

    let activeWriter = null;
    let activeRequestController = null;
    let isDisposed = false;

    const resetState = () => {
        isExporting.value = false;
        isCancelled.value = false;
        isComplete.value = false;
        hasError.value = false;
        errorMessage.value = '';
        writerMode.value = '';
        progress.value = emptyProgress();
    };

    const loadSchema = () => {
        if (!isProActive || schema.value || isSchemaLoading.value) {
            return Promise.resolve(schema.value);
        }

        isSchemaLoading.value = true;
        schemaError.value = '';

        return Rest.get(`data-export/${config.entity}/schema`)
            .then((response) => {
                schema.value = response.export;
                return schema.value;
            })
            .catch((error) => {
                schemaError.value = error?.data?.message || translate('Unable to load export options.');
                return Promise.reject(error);
            })
            .finally(() => {
                isSchemaLoading.value = false;
            });
    };

    const cancelExport = () => {
        if (isExporting.value) {
            isCancelled.value = true;
            activeRequestController?.abort();
        }
    };

    const buildFilename = (format) => {
        const now = new Date();
        const date = [
            now.getFullYear(),
            String(now.getMonth() + 1).padStart(2, '0'),
            String(now.getDate()).padStart(2, '0')
        ].join('-');

        return `${config.filenamePrefix}-${date}.${format}`;
    };

    const sanitizeFilters = (filters) => {
        const sanitized = {...(filters || {})};
        [
            'page',
            'per_page',
            'with',
            'select',
            'include_ids',
            'query_timestamp'
        ].forEach((key) => delete sanitized[key]);

        return sanitized;
    };

    const updateProgress = (batch) => {
        const batchCount = batch.records.length;
        const total = progress.value.total || Number(batch.total || 0);
        const fetched = progress.value.fetched + batchCount;

        progress.value = {
            fetched,
            total,
            percentage: total ? Math.min(100, Math.round((fetched / total) * 100)) : 0,
            batches: progress.value.batches + 1
        };
    };

    const failExport = (error) => {
        const message = error?.data?.message
            || error?.message
            || translate('Export failed. Please try again.');
        const abortPromise = activeWriter ? activeWriter.abort() : Promise.resolve();

        return abortPromise.finally(() => {
            activeRequestController = null;
            hasError.value = true;
            errorMessage.value = message;
            isExporting.value = false;
            activeWriter = null;
        });
    };

    const finishCancelledExport = () => {
        const abortPromise = activeWriter ? activeWriter.abort() : Promise.resolve();

        return abortPromise.finally(() => {
            activeRequestController = null;
            isExporting.value = false;
            activeWriter = null;
            Notify.info(translate('Export cancelled'));
        });
    };

    const requestBatches = async (options, initialMaxRecords) => {
        let cursor = 0;
        let maxRecords = initialMaxRecords;

        while (true) {
            if (isCancelled.value) {
                await finishCancelledExport();
                return;
            }

            const payload = {
                format: options.format,
                scope: options.scope,
                ids: options.ids || [],
                columns: options.columns || [],
                modules: options.modules || [],
                filters: sanitizeFilters(
                    options.scope === 'all' ? config.buildAllParams() : config.buildParams()
                ),
                cursor,
                max_records: maxRecords
            };

            activeRequestController = new AbortController();
            const response = await Rest.post(
                `data-export/${config.entity}/batch`,
                payload,
                null,
                {signal: activeRequestController.signal}
            );
            activeRequestController = null;
            const batch = response?.export;

            if (!batch || !Array.isArray(batch.records)) {
                throw new Error(translate('The export response was invalid.'));
            }

            if (isCancelled.value) {
                await finishCancelledExport();
                return;
            }

            if (!batch.records.length && !progress.value.fetched) {
                await activeWriter.abort();
                activeWriter = null;
                isExporting.value = false;
                Notify.info(translate('No data to export'));
                return;
            }

            await activeWriter.writeRecords(batch.records);
            updateProgress(batch);
            const reachedTotal = progress.value.total
                && progress.value.fetched >= progress.value.total;

            if (reachedTotal || !batch.has_more || !batch.cursor || !batch.records.length) {
                await activeWriter.close();
                activeWriter = null;
                isExporting.value = false;
                isComplete.value = true;
                progress.value.percentage = 100;
                return;
            }

            cursor = batch.cursor;
            maxRecords = Number(batch.batch?.next_max || maxRecords);
        }
    };

    const startExport = (options) => {
        resetState();

        if (!schema.value) {
            hasError.value = true;
            errorMessage.value = translate('Export options are not available.');
            return Promise.resolve();
        }

        const selectedColumns = schema.value.csv_columns.filter(
            (column) => options.columns.includes(column.key)
        );
        const selectedModules = schema.value.json_modules
            .filter((module) => options.modules.includes(module.key) || module.required)
            .map((module) => module.key);

        activeWriter = new BrowserExportWriter({
            filename: buildFilename(options.format),
            format: options.format,
            entity: config.entity,
            schemaVersion: schema.value.schema_version,
            columns: selectedColumns,
            modules: selectedModules,
            memoryLimitMessage: translate(
                'This export exceeds the 64 MB safe in-memory limit. Use a Chromium-based browser to write the file directly to disk.'
            )
        });
        isExporting.value = true;

        return activeWriter.open()
            .then((writer) => {
                writerMode.value = writer.mode;
                return writer.start();
            })
            .then(() => {
                const initialRecords = Number(schema.value.batching?.initial_records || 500);
                return requestBatches(options, initialRecords);
            })
            .catch((error) => {
                if (error && error.name === 'AbortError') {
                    activeRequestController = null;
                    if (isDisposed) {
                        return Promise.resolve(activeWriter?.abort()).finally(() => {
                            activeWriter = null;
                            isExporting.value = false;
                        });
                    }

                    return finishCancelledExport();
                }

                return failExport(error);
            });
    };

    onBeforeUnmount(() => {
        isDisposed = true;
        isCancelled.value = true;
        activeRequestController?.abort();
        activeWriter?.abort();
        activeWriter = null;
    });

    return {
        schema,
        isSchemaLoading,
        schemaError,
        isProActive,
        isExporting,
        isCancelled,
        isComplete,
        hasError,
        errorMessage,
        writerMode,
        progress,
        loadSchema,
        startExport,
        cancelExport,
        resetState
    };
}
