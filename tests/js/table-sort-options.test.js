import {afterEach, describe, expect, it} from 'vitest';

// Rest.js reads window.jQuery and AppConfig decodes currency symbols through a
// <textarea> at import time, so both globals have to exist before the table
// module is pulled in — hence the dynamic import below.
global.window = {location: {origin: 'https://example.test'}, navigator: {userAgent: ''}};
global.document = {
    createElement: () => ({innerHTML: '', value: '', style: {}}),
    addEventListener: () => {},
    removeEventListener: () => {},
    documentElement: {style: {}},
    body: {},
};
const {default: Table} = await import('../../resources/admin/utils/table-new/Table.js');

/**
 * Sort options reach the admin table the same way the advanced filter options
 * and the custom columns do: PHP puts a `value => label` map on
 * `fluentCartAdminApp.table_config.{table}.filters.sorts` (see
 * BaseFilter::getSortOptions(), hook `fluent_cart/{filterName}_table_sorts`)
 * and the table class renders it. The column each value orders by is resolved
 * server side, so it never appears here.
 *
 * Core tables declare nothing in JS — PHP is the single source. The JS list
 * survives only for tables whose endpoint has no filter class to declare them
 * on (the pro inventory screen, the withdrawal add-on), so both paths are
 * pinned below.
 *
 * ColumnSort.vue renders `table.getSortOptions()`, so this pins what that
 * popover shows — the table's own columns first, registered ones appended, and
 * a value declared on both sides listed once with the table's own label.
 */
/** A core table: no JS list, everything comes from PHP. */
class StubTable extends Table {
    getTableName() {
        return 'stub_table';
    }
}

/** A table with no filter class behind it, so it still declares its own. */
class StubLocalTable extends StubTable {
    getSortableColumns() {
        return [
            {label: 'Order ID', value: 'id'},
            {label: 'Total', value: 'total_amount'},
        ];
    }
}

const withRegisteredSorts = (sorts) => {
    global.window.fluentCartAdminApp = {
        table_config: {
            stub_table: {filters: {sorts}},
        },
    };

    // The Table constructor only registers an onBeforeMount hook, which is a
    // no-op outside a component instance — no DOM needed for these getters.
    return new StubTable({fetch: false});
};

const withLocalList = (sorts) => {
    withRegisteredSorts(sorts);
    return new StubLocalTable({fetch: false});
};

describe('Table.getSortOptions()', () => {

    afterEach(() => {
        delete global.window.fluentCartAdminApp;
    });

    it('renders the server map for a table that declares nothing itself', () => {
        const table = withRegisteredSorts({id: 'Order ID', total_amount: 'Total'});

        expect(table.getSortOptions()).toEqual([
            {label: 'Order ID', value: 'id'},
            {label: 'Total', value: 'total_amount'},
        ]);
    });

    it('offers no sort at all when the server sent nothing', () => {
        const table = withRegisteredSorts(undefined);

        expect(table.getSortOptions()).toEqual([]);
    });

    it('keeps the order the server declared', () => {
        const table = withRegisteredSorts({updated_at: 'Last Updated', best_seller: 'Best Selling'});

        expect(table.getSortOptions().map((column) => column.value)).toEqual([
            'updated_at',
            'best_seller',
        ]);
    });

    it('appends server options after a table that still declares its own', () => {
        const table = withLocalList({updated_at: 'Last Updated'});

        expect(table.getSortOptions()).toEqual([
            {label: 'Order ID', value: 'id'},
            {label: 'Total', value: 'total_amount'},
            {label: 'Last Updated', value: 'updated_at'},
        ]);
    });

    it('does not list a value declared on both sides twice', () => {
        const table = withLocalList({id: 'ID'});

        expect(table.getSortOptions()).toHaveLength(2);
        expect(table.getSortOptions()[0].label).toBe('Order ID');
    });
});
