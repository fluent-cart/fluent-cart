// `@fluent-cart/ui` — what add-on bundles may use from core's admin app, as
// flat named exports so a missing name fails at module load, not at render.
// Only names a shipped add-on imports; removing or renaming one breaks it and
// must bump FLUENTCART_MODULE_API.
import {Body, Container, Footer, Header} from '@/Bits/Components/Card/Card.js';
import Badge from '@/Bits/Components/Badge.vue';
import ChartTab from '@/Bits/Components/ChartTab.vue';
import CopyToClipboard from '@/Bits/Components/CopyToClipboard.vue';
import DynamicIcon from '@/Bits/Components/Icons/DynamicIcon.vue';
import Empty from '@/Bits/Components/Table/Empty.vue';
import ExportDialog from '@/Bits/Components/ExportDialog.vue';
import Heading from '@/Bits/Components/Layout/Heading.vue';
import IconButton from '@/Bits/Components/Buttons/IconButton.vue';
import LabelHint from '@/Bits/Components/LabelHint.vue';
import OrderUpDownIndicator from '@/Bits/Components/OrderUpDownIndicator.vue';
import PageHeading from '@/Bits/Components/Layout/PageHeading.vue';
import Pagination from '@/Bits/Components/Pagination.vue';
import ProductInfo from '@/Bits/Components/Card/ProductInfo.vue';
import RouteCell from '@/Bits/Components/TableNew/RouteCell.vue';
import TableWrapper from '@/Bits/Components/TableNew/TableWrapper.vue';
import UserCan from '@/Bits/Components/Permission/UserCan.vue';
import WpEditor from '@/Bits/Components/Inputs/WpEditor.vue';
import Label from '@/Modules/Parts/Labels/Label.vue';
import OrderCustomerInformation from '@/Modules/Orders/OrderCustomerInformation.vue';
import UpgradePlan from '@/Modules/Products/UpgradePaths/UpgradePlan.vue';
import NotFound from '@/Pages/NotFound.vue';

export const Card = {Container, Header, Body, Footer};

export {
    Badge,
    ChartTab,
    CopyToClipboard,
    DynamicIcon,
    Empty,
    ExportDialog,
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
    RouteCell,
    TableWrapper,
    UpgradePlan,
    UserCan,
    WpEditor,
};

export {default as translate, translateNumber} from '@/utils/translator/Translator';
export {default as Rest} from '@/utils/http/Rest';
export {default as AppConfig} from '@/utils/Config/AppConfig';
export {default as Storage} from '@/utils/Storage';
export {default as Theme} from '@/utils/Theme';
export {default as Clipboard} from '@/utils/Clipboard';
export {default as Arr} from '@/utils/support/Arr';
export {default as Str} from '@/utils/support/Str';
export {default as Asset} from '@/utils/support/Asset';
export {default as Model} from '@/utils/model/Model';
export {default as Table} from '@/utils/table-new/Table';
export {default as Permission} from '@/utils/permission/Permission';
export {useExport} from '@/utils/export/useExport';
export {resolveDateFormat, fluentDayjsLocale} from '@/utils/Utils';
export {formatDate, isValidURL, handleError} from '@/Bits/common';
export {paymentMethodAdminTitle} from '@/utils/paymentMethodAdminTitle';
export {monthNames} from '@/Modules/Reports/Utils/monthNames';
export {default as dateShortcuts} from '@/Modules/Reports/Utils/dateShortCuts';
