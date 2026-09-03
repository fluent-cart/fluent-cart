import { computed } from "vue";
import translate from "@/utils/translator/Translator";

/**
 * Shared options for the report line-chart granularity selector
 * (Auto / Daily / Monthly / Yearly).
 *
 * While Auto is the active selection, its label shows the granularity the
 * server's auto heuristic (ReportHelper::defineGroupKey) picks for the
 * current date range — <= 91 days: Daily, <= 365: Monthly, otherwise Yearly.
 * While an explicit granularity is selected the label stays a plain "Auto",
 * so it never echoes the manual selection.
 *
 * @param {Object} reportFilter ReportFilterModel instance (uses data.dateRange)
 * @param {import('vue').Ref} selectedGroupKey the selector's v-model ref
 */
export default function useGroupKeyOptions(reportFilter, selectedGroupKey) {
    const autoLabel = computed(() => {
        if (selectedGroupKey.value && selectedGroupKey.value !== "default") {
            return translate("Auto");
        }

        const [start, end] = reportFilter.data.dateRange || [];
        if (!start || !end) {
            return translate("Auto");
        }

        // floor, not ceil: the range ends at 23:59:59, and PHP's
        // DateInterval->days truncates — ceil would disagree with the server
        // by one day at each boundary.
        const days = Math.floor(
            Math.abs(new Date(end) - new Date(start)) / (1000 * 60 * 60 * 24)
        );

        let granularity;
        if (days <= 91) {
            granularity = translate("Daily");
        } else if (days <= 365) {
            granularity = translate("Monthly");
        } else {
            granularity = translate("Yearly");
        }

        /* translators: %1$s: the granularity Auto resolves to (Daily/Monthly/Yearly) */
        return translate("Auto (%1$s)", granularity);
    });

    const groupKeys = computed(() => [
        { value: "default", label: autoLabel.value },
        { value: "daily", label: translate("Daily") },
        { value: "monthly", label: translate("Monthly") },
        { value: "yearly", label: translate("Yearly") },
    ]);

    return { groupKeys };
}
