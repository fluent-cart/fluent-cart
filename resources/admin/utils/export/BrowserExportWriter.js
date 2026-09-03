const encoder = new TextEncoder();
const MAX_MEMORY_BYTES = 64 * 1024 * 1024;

const escapeCsvFormula = (value) => {
    if (/^[\x00-\x20]*[=+\-@]/.test(value)) {
        return "'" + value;
    }

    return value;
};

const csvValue = (value) => {
    if (value === null || value === undefined) {
        return '';
    }

    if (typeof value === 'object') {
        value = JSON.stringify(value);
    }

    value = escapeCsvFormula(String(value));

    if (value.includes(',') || value.includes('"') || value.includes('\n') || value.includes('\r')) {
        return '"' + value.replace(/"/g, '""') + '"';
    }

    return value;
};

export default class BrowserExportWriter {
    constructor(options) {
        this.filename = options.filename;
        this.format = options.format;
        this.entity = options.entity;
        this.schemaVersion = options.schemaVersion;
        this.columns = options.columns || [];
        this.modules = options.modules || [];
        this.memoryLimitMessage = options.memoryLimitMessage || 'Export exceeds the safe in-memory limit.';
        this.parts = [];
        this.memoryBytes = 0;
        this.writable = null;
        this.isFirstJsonRecord = true;
        this.mode = 'memory';
    }

    open() {
        if (typeof window.showSaveFilePicker !== 'function') {
            return Promise.resolve(this);
        }

        const mime = this.format === 'json' ? 'application/json' : 'text/csv';
        const extension = '.' + this.format;

        return window.showSaveFilePicker({
            suggestedName: this.filename,
            types: [{
                description: this.format === 'json' ? 'JSON file' : 'CSV file',
                accept: {
                    [mime]: [extension]
                }
            }]
        })
            .then((handle) => handle.createWritable())
            .then((writable) => {
                this.writable = writable;
                this.mode = 'direct';
                return this;
            })
            .catch((error) => Promise.reject(error));
    }

    start() {
        if (this.format === 'csv') {
            const headers = this.columns.map((column) => csvValue(column.label));
            return this.write('\uFEFF' + headers.join(',') + '\r\n');
        }

        const envelope = {
            schema_version: this.schemaVersion,
            entity: this.entity,
            modules: this.modules
        };
        const prefix = JSON.stringify(envelope).slice(0, -1) + ',"records":[';

        return this.write(prefix);
    }

    writeRecords(records) {
        if (this.format === 'csv') {
            const chunk = records.map((record) => {
                return this.columns.map((column) => csvValue(record[column.key])).join(',');
            }).join('\r\n');

            return this.write(chunk ? chunk + '\r\n' : '');
        }

        const chunks = [];
        records.forEach((record) => {
            if (!this.isFirstJsonRecord) {
                chunks.push(',');
            }
            chunks.push(JSON.stringify(record));
            this.isFirstJsonRecord = false;
        });

        return this.write(chunks.join(''));
    }

    close() {
        const finalize = this.format === 'json' ? this.write(']}') : Promise.resolve();

        return finalize.then(() => {
            if (this.writable) {
                return this.writable.close();
            }

            const mime = this.format === 'json'
                ? 'application/json;charset=utf-8'
                : 'text/csv;charset=utf-8';
            const blob = new Blob(this.parts, {type: mime});
            const url = URL.createObjectURL(blob);
            const link = document.createElement('a');
            link.href = url;
            link.download = this.filename;
            document.body.appendChild(link);
            link.click();
            link.remove();

            window.setTimeout(() => URL.revokeObjectURL(url), 1000);
            this.parts = [];
            this.memoryBytes = 0;

            return Promise.resolve();
        });
    }

    abort() {
        this.parts = [];
        this.memoryBytes = 0;

        if (this.writable && typeof this.writable.abort === 'function') {
            return this.writable.abort().catch(() => undefined);
        }

        return Promise.resolve();
    }

    write(value) {
        if (!value) {
            return Promise.resolve();
        }

        const encoded = encoder.encode(value);

        if (this.writable) {
            return this.writable.write(encoded);
        }

        if (this.memoryBytes + encoded.byteLength > MAX_MEMORY_BYTES) {
            return Promise.reject(new Error(
                this.memoryLimitMessage
            ));
        }

        this.parts.push(encoded);
        this.memoryBytes += encoded.byteLength;
        return Promise.resolve();
    }
}
