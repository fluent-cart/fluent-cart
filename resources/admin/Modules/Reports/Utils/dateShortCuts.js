import translate from "@/utils/translator/Translator";
import Storage from "@/utils/Storage";
import dayjs from 'dayjs';
import quarterOfYear from 'dayjs/plugin/quarterOfYear';
import AppConfig from "@/utils/Config/AppConfig";

dayjs.extend(quarterOfYear);

const proShortcuts = [
  {
    text: "Today",
    value: () => {
      const start = dayjs().startOf('day').toDate();
      const end = dayjs().endOf('day').toDate();
      return [start, end];
    },
  },
  {
    text: translate("This Week"),
    value: () => {
      const start = dayjs().startOf('week').toDate();
      const end = dayjs().endOf('week').toDate();
      return [start, end];
    },
  },
  {
    text: translate("Last Week"),
    value: () => {
      const start = dayjs().subtract(1, 'week').startOf('week').toDate();
      const end = dayjs().subtract(1, 'week').endOf('week').toDate();
      return [start, end];
    },
  },
  {
    text: translate("Last Month"),
    value: () => {
      const start = dayjs().subtract(1, 'month').startOf('month').toDate();
      const end = dayjs().subtract(1, 'month').endOf('month').toDate();
      return [start, end];
    },
  },
  {
    text: translate("Last 6 Months"),
    value: () => {
      const start = dayjs().subtract(5, 'month').startOf('month').toDate();
      const end = dayjs().endOf('day').toDate();
      return [start, end];
    },
  },
  {
    text: translate('This Quarter'),
    value: () => {
      const start = dayjs().startOf('quarter').toDate();
      const end = dayjs().endOf('quarter').toDate();
      return [start, end];
    }
  },
  {
    text: translate('Last Quarter'),
    value: () => {
      const start = dayjs().subtract(1, 'quarter').startOf('quarter').toDate();
      const end = dayjs().subtract(1, 'quarter').endOf('quarter').toDate();
      return [start, end];
    }
  },
  {
    text: translate("This Year"),
    value: () => {
      const start = dayjs().startOf('year').toDate();
      const end = dayjs().endOf('year').toDate();
      return [start, end];
    },
  },
  {
    text: translate("Last Year"),
    value: () => {
      const start = dayjs().subtract(1, 'year').startOf('year').toDate();
      const end = dayjs().subtract(1, 'year').endOf('year').toDate();
      return [start, end];
    },
  },
  {
    text: translate("All Time"),
    value: () => {
      const storedDate = Storage.get("report-filter").firstOrderDate;
      const start = dayjs(storedDate).startOf('day').toDate();
      const end = dayjs().endOf('day').toDate();
      return [start, end];
    },
  },
];

const freeDateShortcuts = [
    {
        text: translate("This Month"),
        value: () => {
            const start = dayjs().startOf('month').toDate();
            const end = dayjs().endOf('month').toDate();
            return [start, end];
        },
    },
];

const isPro = AppConfig.get('app_config.isProActive');

const dateShortcuts = isPro
  ? [...proShortcuts.slice(0, 3), ...freeDateShortcuts, ...proShortcuts.slice(3)]
  : freeDateShortcuts;

export default dateShortcuts;
