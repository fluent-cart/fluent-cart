// fct-* components: FluentCart's own names for the UI kit, registered on the admin and
// portal apps so add-on templates write <fct-button> instead of <el-button>. They wrap
// Element Plus today; a library swap rewrites them, not the add-ons.
// Removing a name or a prop breaks shipped add-ons: bump FLUENTCART_MODULE_API.
// API list: dev-docs/admin-design-system/fct-components-inventory.md
import FctButton from './Button.vue';
import FctChoice from './Choice.vue';
import FctDatePicker from './DatePicker.vue';
import FctDialog from './Dialog.vue';
import FctDropdown from './Dropdown.vue';
import FctInput from './Input.vue';
import FctPopover from './Popover.vue';
import FctProgress from './Progress.vue';
import FctSelect from './Select.vue';
import FctSkeleton from './Skeleton.vue';
import FctSwitch from './Switch.vue';
import FctTable from './Table.vue';
import FctTableColumn from './TableColumn.vue';
import FctTooltip from './Tooltip.vue';

export const fctComponents = {
    FctButton,
    FctChoice,
    FctDatePicker,
    FctDialog,
    FctDropdown,
    FctInput,
    FctPopover,
    FctProgress,
    FctSelect,
    FctSkeleton,
    FctSwitch,
    FctTable,
    FctTableColumn,
    FctTooltip,
};

export function registerFctComponents(app) {
    Object.keys(fctComponents).forEach((name) => app.component(name, fctComponents[name]));
}
