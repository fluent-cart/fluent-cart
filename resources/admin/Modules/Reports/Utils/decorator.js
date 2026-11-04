import dayjs from "dayjs"
import Theme from "@/utils/Theme"
import {resolveDateFormat, fluentDayjsLocale} from "@/utils/Utils"
import { monthNames } from "./monthNames"
import AppConfig from "@/utils/Config/AppConfig"
import translate from "@/utils/translator/Translator"

// Reports read every day and hour in the WordPress site timezone; this names it for the store owner
export function storeTimeNote() {
    const zone = ((AppConfig.get('datei18') || {}).timezone || {}).site || 'UTC'
    const label = zone === '+00:00' ? 'UTC' : (/^[+-]\d{2}:\d{2}$/.test(zone) ? 'UTC' + zone : zone)

    /* translators: %1$s - the site timezone, e.g. America/New_York or UTC+06:00 */
    return translate('In store time: %1$s', label)
}

export function tooltipSuffix(dateRange) {
    const startDate = dayjs(dateRange[0]).locale(fluentDayjsLocale()).format(resolveDateFormat('date'))
    const endDate = dayjs(dateRange[1]).locale(fluentDayjsLocale()).format(resolveDateFormat('date'))
    const isSameDate = startDate === endDate

    return (isSameDate ? `on ${startDate}.` : `between ${startDate} and ${endDate}.`) + ' ' + storeTimeNote()
}

export function prepareLabel(item) {
    const parts = item.split("-")
    if (parts.length === 3) {
        const [year, month, day] = parts
        // The year stays so a tooltip on a range across years names the day exactly
        return `${monthNames[parseInt(month) - 1]} ${day}, ${year}`
    } else if (parts.length === 2) {
        const [year, month] = parts
        // return `${monthNames[parseInt(month) - 1]} ${year}`;
        return `${monthNames[parseInt(month) - 1]} ${year}`
    } else if (parts.length === 1) {
        const [year] = parts
        return `${year}`
    }
}

// Axis ticks drop the day label's year to stay short; the tooltip keeps the full label
export function axisDayLabel(value) {
    return String(value).replace(/, \d{4}$/, '')
}

export function makeXAxisLabels(dataSource) {
    return dataSource.map((item) => {
        return prepareLabel(item.group)
    })
}

const reportColors = Theme.colors.report

const colorMap = {
    [reportColors.light_gray]: reportColors.cyan_blue,
    [reportColors.light_gray_cyan_blue]: reportColors.cyan_blue_dark,
    [reportColors.blue]: reportColors.royal_blue,
    [reportColors.royal_blue]: reportColors.dark_blue,
    [reportColors.medium_slate_blue]: reportColors.dark_purple,
    [reportColors.dark_cyan_blue_16]: reportColors.dark_cyan_blue_40,
    [reportColors.dark_cyan_blue_24]: reportColors.dark_cyan_blue_40,

    [reportColors.blue]: reportColors.blue_dark_mode,
    [reportColors.purple]: reportColors.purple_dark_mode,
}

export function getEmphasisColor(color) {
    return colorMap[color]
}

export function getXAxisConfig(dataLength) {
    const splitNumber = dataLength <= 31 ? 6 : 7
    const interval = Math.floor(dataLength / splitNumber)

    return {
        splitNumber: splitNumber,
        interval: interval,
    }
}
