/**
 * When the products do not fill the last row of the grid, how many sit in it and how many to show instead.
 * Null when there is one row or less, or when the rows are full. fillTo is null if it would pass the maximum.
 */
export const lastRowHint = (perPage, columns, max) => {
    const count = Number(perPage);
    const perRow = Number(columns);

    if (!count || !perRow || count <= perRow) {
        return null;
    }

    const lastRow = count % perRow;
    if (lastRow === 0) {
        return null;
    }

    const fillTo = count + (perRow - lastRow);

    return {lastRow, columns: perRow, fillTo: fillTo <= max ? fillTo : null};
};
