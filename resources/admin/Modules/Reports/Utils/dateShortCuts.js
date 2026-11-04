import { siteToday } from "@/Modules/Reports/Utils/datePresets";
import translate from "@/utils/translator/Translator";
import Storage from "@/utils/Storage";
import dayjs from 'dayjs';
import quarterOfYear from 'dayjs/plugin/quarterOfYear';
import AppConfig from "@/utils/Config/AppConfig";

dayjs.extend(quarterOfYear);

const proShortcuts = [
  {
    text: translate("Today"),
    value: () => {
      const start = siteToday().startOf('day').toDate();
      const end = siteToday().endOf('day').toDate();
      return [start, end];
    },
  },
  {
    text: translate("This Week"),
    value: () => {
      const start = siteToday().startOf('week').toDate();
      const end = siteToday().endOf('week').toDate();
      return [start, end];
    },
  },
  {
    text: translate("Last Week"),
    value: () => {
      const start = siteToday().subtract(1, 'week').startOf('week').toDate();
      const end = siteToday().subtract(1, 'week').endOf('week').toDate();
      return [start, end];
    },
  },
  {
    text: translate("Last Month"),
    value: () => {
      const start = siteToday().subtract(1, 'month').startOf('month').toDate();
      const end = siteToday().subtract(1, 'month').endOf('month').toDate();
      return [start, end];
    },
  },
  {
    text: translate("Last 6 Months"),
    value: () => {
      const start = siteToday().subtract(5, 'month').startOf('month').toDate();
      const end = siteToday().endOf('day').toDate();
      return [start, end];
    },
  },
  {
    text: translate('This Quarter'),
    value: () => {
      const start = siteToday().startOf('quarter').toDate();
      const end = siteToday().endOf('quarter').toDate();
      return [start, end];
    }
  },
  {
    text: translate('Last Quarter'),
    value: () => {
      const start = siteToday().subtract(1, 'quarter').startOf('quarter').toDate();
      const end = siteToday().subtract(1, 'quarter').endOf('quarter').toDate();
      return [start, end];
    }
  },
  {
    text: translate("This Year"),
    value: () => {
      const start = siteToday().startOf('year').toDate();
      const end = siteToday().endOf('year').toDate();
      return [start, end];
    },
  },
  {
    text: translate("Last Year"),
    value: () => {
      const start = siteToday().subtract(1, 'year').startOf('year').toDate();
      const end = siteToday().subtract(1, 'year').endOf('year').toDate();
      return [start, end];
    },
  },
  {
    text: translate("All Time"),
    value: () => {
      const storedDate = Storage.get("report-filter").firstOrderDate;
      const start = dayjs(storedDate).startOf('day').toDate();
      const end = siteToday().endOf('day').toDate();
      return [start, end];
    },
  },
];

const freeDateShortcuts = [
    {
        text: translate("This Month"),
        value: () => {
            const start = siteToday().startOf('month').toDate();
            const end = siteToday().endOf('month').toDate();
            return [start, end];
        },
    },
];

const isPro = AppConfig.get('app_config.isProActive');

const dateShortcuts = isPro
  ? [...proShortcuts.slice(0, 3), ...freeDateShortcuts, ...proShortcuts.slice(3)]
  : freeDateShortcuts;

export default dateShortcuts;
