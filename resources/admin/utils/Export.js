import Arr from '@/utils/support/Arr';

const formatHeader = (header) => {
    // Replace underscores with spaces and capitalize the words
    return header
        .replace(/_/g, " ")
        .replace(/\b\w/g, (char) => char.toUpperCase());
}

const convertToCSV = (data) => {
    const keys = Object.keys(data[0]);
    const headers = keys.map(formatHeader);

    const csvRows = [headers.join(",")];

    data.forEach((row) => {
        const values = keys.map((key) => {
            let val = row[key];

            // If it's an object/array → stringify
            if (typeof val === "object") {
                val = JSON.stringify(val);
            }

            // Escape quotes & commas
            if (typeof val === "string") {
                val = `"${val.replace(/"/g, '""')}"`;
            }

            return val;
        });

        csvRows.push(values.join(","));
    });

    return csvRows.join("\n");
}

export const exportCSV = (data, filename = "export.csv") => {
    return new Promise((resolve, reject) => {
        try {
            if (!data || !data.length) {
                throw new Error("No data to export");
            }

            const csv = convertToCSV(data);
            const blob = new Blob([csv], { type: "text/csv" });
            const link = document.createElement("a");

            const onFocus = () => {
                window.removeEventListener('focus', onFocus);
                resolve();
            };

            window.addEventListener('focus', onFocus);

            link.href = URL.createObjectURL(blob);
            link.download = filename;
            link.click();

            URL.revokeObjectURL(link.href);

            setTimeout(() => {
                resolve();
            }, 200);
        } catch (error) {
            reject(error);
        }
    });
}

/**
 * Export data to CSV using explicit column definitions.
 *
 * @param {Array}  data     - Array of row objects
 * @param {Array}  columns  - Array of { header, accessor, formatter? }
 * @param {string} filename - Download filename
 * @returns {Promise}
 */
export const exportCSVWithColumns = (data, columns, filename = "export.csv") => {
    return new Promise((resolve, reject) => {
        try {
            if (!data || !data.length) {
                throw new Error("No data to export");
            }

            const headers = columns.map(col => col.header);
            const csvRows = [headers.join(",")];

            data.forEach((row) => {
                const values = columns.map((col) => {
                    const path = col.accessor || col.key;
                    let val = path ? Arr.get(row, path, '') : '';

                    if (typeof col.formatter === 'function') {
                        val = col.formatter(val, row);
                    }

                    if (val === null || val === undefined) {
                        val = '';
                    }

                    if (typeof val === 'object') {
                        val = JSON.stringify(val);
                    }

                    val = String(val);

                    if (val.includes(',') || val.includes('"') || val.includes('\n')) {
                        val = '"' + val.replace(/"/g, '""') + '"';
                    }

                    return val;
                });

                csvRows.push(values.join(","));
            });

            const csv = csvRows.join("\n");
            const bom = '\uFEFF';
            const blob = new Blob([bom + csv], { type: "text/csv;charset=utf-8;" });
            const link = document.createElement("a");

            link.href = URL.createObjectURL(blob);
            link.download = filename;
            link.click();

            URL.revokeObjectURL(link.href);

            setTimeout(() => {
                resolve();
            }, 200);
        } catch (error) {
            reject(error);
        }
    });
}

/**
 * Export data to JSON using explicit column definitions.
 *
 * @param {Array}  data     - Array of row objects
 * @param {Array}  columns  - Array of { header, accessor, formatter? }
 * @param {string} filename - Download filename
 * @returns {Promise}
 */
export const exportJSONWithColumns = (data, columns, filename = "export.json") => {
    return new Promise((resolve, reject) => {
        try {
            if (!data || !data.length) {
                throw new Error("No data to export");
            }

            const rows = data.map((row) => {
                return columns.reduce((exportRow, col) => {
                    const path = col.accessor || col.key;
                    let val = path ? Arr.get(row, path, '') : '';

                    if (typeof col.formatter === 'function') {
                        val = col.formatter(val, row);
                    }

                    if (val === undefined) {
                        val = null;
                    }

                    exportRow[col.header || col.key] = val;
                    return exportRow;
                }, {});
            });

            const json = JSON.stringify(rows, null, 2);
            const blob = new Blob([json], { type: "application/json;charset=utf-8;" });
            const link = document.createElement("a");

            link.href = URL.createObjectURL(blob);
            link.download = filename;
            link.click();

            URL.revokeObjectURL(link.href);

            setTimeout(() => {
                resolve();
            }, 200);
        } catch (error) {
            reject(error);
        }
    });
}
