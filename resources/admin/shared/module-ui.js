// `@fluent-cart/ui` — what add-on bundles may use from core's admin app, as
// flat named exports so a missing name fails at module load, not at render.
// Only names a shipped add-on imports; removing or renaming one breaks it and
// must bump FLUENTCART_MODULE_API.
import {Body, Container, Footer, Header} from '@/Bits/Components/Card/Card.js';
import {Item as FluidTabItem, Tab as FluidTabContainer} from '@/Bits/Components/FluidTab/FluidTab.js';
import Alert from '@/Bits/Components/Alert.vue';
import Animation from '@/Bits/Components/Animation.vue';
import Badge from '@/Bits/Components/Badge.vue';
import ChartTab from '@/Bits/Components/ChartTab.vue';
import CopyToClipboard from '@/Bits/Components/CopyToClipboard.vue';
import DynamicIcon from '@/Bits/Components/Icons/DynamicIcon.vue';
import Empty from '@/Bits/Components/Table/Empty.vue';
import Fluctuation from '@/Bits/Components/Fluctuation.vue';
import ExportReport from '@/Modules/Reports/ExportReport.vue';
import ExportDialog from '@/Bits/Components/ExportDialog.vue';
import Heading from '@/Bits/Components/Layout/Heading.vue';
import IconButton from '@/Bits/Components/Buttons/IconButton.vue';
import LabelHint from '@/Bits/Components/LabelHint.vue';
import OrderUpDownIndicator from '@/Bits/Components/OrderUpDownIndicator.vue';
import PageHeading from '@/Bits/Components/Layout/PageHeading.vue';
import Pagination from '@/Bits/Components/Pagination.vue';
import ProductInfo from '@/Bits/Components/Card/ProductInfo.vue';
import ProductSalesChart from '@/Modules/Reports/Product/ProductSalesChart.vue';
import ReportSummary from '@/Bits/Components/ReportSummary.vue';
import StepIndicator from '@/Bits/Components/StepIndicator.vue';
import RouteCell from '@/Bits/Components/TableNew/RouteCell.vue';
import Screenshot from '@/Bits/Components/Screenshot.vue';
import SummaryLoader from '@/Modules/Reports/Components/SummaryLoader.vue';
import TableWrapper from '@/Bits/Components/TableNew/TableWrapper.vue';
import UserCan from '@/Bits/Components/Permission/UserCan.vue';
import WpEditor from '@/Bits/Components/Inputs/WpEditor.vue';
import Label from '@/Modules/Parts/Labels/Label.vue';
import OrderCustomerInformation from '@/Modules/Orders/OrderCustomerInformation.vue';
import UpgradePlan from '@/Modules/Products/UpgradePaths/UpgradePlan.vue';
import NotFound from '@/Pages/NotFound.vue';
// Layout components: dev-docs/admin-design-system/spec.md.
import DetailLayout from '@/Bits/Components/Layout/DetailLayout.vue';
import EmptyState from '@/Bits/Components/Layout/EmptyState.vue';
import CoreUpdateCard from '@/Bits/Components/CoreUpdateCard.vue';
import KeyValue from '@/Bits/Components/Layout/KeyValue.vue';
import KeyValueList from '@/Bits/Components/Layout/KeyValueList.vue';
import List from '@/Bits/Components/Layout/List.vue';
import ListItem from '@/Bits/Components/Layout/ListItem.vue';
import Page from '@/Bits/Components/Layout/Page.vue';
import Section from '@/Bits/Components/Layout/Section.vue';
import SettingRow from '@/Bits/Components/Layout/SettingRow.vue';
import SortableList from '@/Bits/Components/Layout/SortableList.vue';
import StatGrid from '@/Bits/Components/Layout/StatGrid.vue';
import StatTile from '@/Bits/Components/Layout/StatTile.vue';
import Title from '@/Bits/Components/Layout/Title.vue';

export const Card = {Container, Header, Body, Footer};
export const FluidTab = {Tab: FluidTabContainer, Item: FluidTabItem};

export {
    Alert,
    Animation,
    CoreUpdateCard,
    DetailLayout,
    EmptyState,
    KeyValue,
    KeyValueList,
    List,
    ListItem,
    Page,
    Section,
    SettingRow,
    SortableList,
    StatGrid,
    StatTile,
    Title,
    Badge,
    ChartTab,
    CopyToClipboard,
    DynamicIcon,
    Empty,
    ExportDialog,
    ExportReport,
    Fluctuation,
    Heading,
    IconButton,
    Label,
    LabelHint,
    NotFound,
    OrderCustomerInformation,
    OrderUpDownIndicator,
    PageHeading,
    Pagination,
    ProductInfo,
    ProductSalesChart,
    ReportSummary,
    RouteCell,
    Screenshot,
    StepIndicator,
    SummaryLoader,
    TableWrapper,
    UpgradePlan,
    UserCan,
    WpEditor,
};

export {default as translate, translateNumber, pluralizeTranslate} from '@/utils/translator/Translator';
export {default as Notify} from '@/utils/Notify';
export {default as Rest} from '@/utils/http/Rest';
export {default as AppConfig} from '@/utils/Config/AppConfig';
export {default as Storage} from '@/utils/Storage';
export {default as Theme} from '@/utils/Theme';
export {default as Clipboard} from '@/utils/Clipboard';
export {default as Arr} from '@/utils/support/Arr';
export {default as Str} from '@/utils/support/Str';
export {default as Asset} from '@/utils/support/Asset';
export {default as CurrencyFormatter} from '@/utils/support/CurrencyFormatter';
export {default as Model} from '@/utils/model/Model';
export {default as Table} from '@/utils/table-new/Table';
export {default as Permission} from '@/utils/permission/Permission';
export {useExport} from '@/utils/export/useExport';
export {resolveDateFormat, fluentDayjsLocale, chartAxisPointer, chartTooltipPosition, chartTooltipAmount, dateTimeI18} from '@/utils/Utils';
export {formatDate, isValidURL, handleError, handleSuccess, handleResponse, $confirm} from '@/Bits/common';
export {paymentMethodAdminTitle} from '@/utils/paymentMethodAdminTitle';
export {monthNames} from '@/Modules/Reports/Utils/monthNames';
export {formatNumber} from '@/Modules/Reports/Utils/formatNumber';
export {prepareLabel, axisDayLabel, storeTimeNote, tooltipSuffix, makeXAxisLabels, getEmphasisColor, getXAxisConfig} from '@/Modules/Reports/Utils/decorator';
export {default as ChartTypeFilter} from '@/Models/Reports/ChartTypeFilterModel';
export {exportCSVWithColumns, csvSafe} from '@/utils/Export';
export {completeRows, headlineRow, periodLabel} from '@/Modules/Reports/Subscription/Retention/retentionRows';
export {averageRate, expectedLifetimeMonths} from '@/Modules/Reports/Utils/retentionAverages';
export {default as dateShortcuts} from '@/Modules/Reports/Utils/dateShortCuts';
