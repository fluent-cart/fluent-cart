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
