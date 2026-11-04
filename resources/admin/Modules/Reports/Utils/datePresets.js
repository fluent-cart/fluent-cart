import dayjs from "dayjs";
import utc from "dayjs/plugin/utc.js";
import timezone from "dayjs/plugin/timezone.js";
import translate from "@/utils/translator/Translator";
import AppConfig from "@/utils/Config/AppConfig";
import Storage from "@/utils/Storage";

dayjs.extend(utc);
dayjs.extend(timezone);

// A preset counts from today in the WordPress site timezone, the clock every report uses. The server resolves
// the same keys (ReportHelper::presetRange), so a range saved yesterday still reads today's days.
const PRESETS = [
    {key: "today", label: translate("Today"), range: (today) => [today, today]},
    {key: "last_7", label: translate("Last 7 days"), range: (today) => [today.subtract(6, "day"), today]},
    {key: "last_30", label: translate("Last 30 days"), range: (today) => [today.subtract(29, "day"), today]},
    {key: "this_month", label: translate("This month"), range: (today) => [today.startOf("month"), today.endOf("month")]},
    {key: "last_month", label: translate("Last month"), range: (today) => {
        const month = today.subtract(1, "month");
        return [month.startOf("month"), month.endOf("month")];
    }},
    {key: "last_12_months", label: translate("Last 12 months"), range: (today) => [today.subtract(11, "month").startOf("month"), today]},
    {key: "year_to_date", label: translate("Year to date"), range: (today) => [today.startOf("year"), today]},
    {key: "all_time", label: translate("All time"), range: (today) => {
        const first = (Storage.get("report-filter") || {}).firstOrderDate;
        return [first ? dayjs(first) : today.startOf("year"), today];
    }},
];

// A local dayjs at midnight of the site's date today: the server reads only the calendar date
export const siteToday = () => {
    const zone = ((AppConfig.get("datei18") || {}).timezone || {}).site || "UTC";
    let now;
    try {
        now = /^[+-]\d{2}:\d{2}$/.test(zone) ? dayjs.utc().utcOffset(zone) : dayjs().tz(zone);
    } catch (e) {
        now = dayjs.utc();
    }

    return dayjs(now.format("YYYY-MM-DD"));
};

export function reportPresets() {
    return PRESETS;
}

export function findPreset(key) {
    return PRESETS.find(preset => preset.key === key) || null;
}

/**
 * [start, end] of a preset for today, or null for a custom range.
 */
export function presetRange(key) {
    const preset = findPreset(key);
    if (!preset) {
        return null;
    }
    const [start, end] = preset.range(siteToday());

    return [start.startOf("day"), end.endOf("day")];
}
