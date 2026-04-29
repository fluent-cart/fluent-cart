import dayjs from "dayjs"
import Theme from "@/utils/Theme"
import { monthNames } from "./monthNames"

export function tooltipSuffix(dateRange) {
    const startDate = dayjs(dateRange[0]).format("MMMM D, YYYY")
    const endDate = dayjs(dateRange[1]).format("MMMM D, YYYY")
    const isSameDate = startDate === endDate

    return isSameDate ? `on ${startDate}.` : `between ${startDate} and ${endDate}.`
}

export function prepareLabel(item) {
    const parts = item.split("-")
    if (parts.length === 3) {
        const [year, month, day] = parts
        // return `${monthNames[parseInt(month) - 1]} ${day}, ${year}`;
        return `${monthNames[parseInt(month) - 1]} ${day}`
    } else if (parts.length === 2) {
        const [year, month] = parts
        // return `${monthNames[parseInt(month) - 1]} ${year}`;
        return `${monthNames[parseInt(month) - 1]} ${year}`
    } else if (parts.length === 1) {
        const [year] = parts
        return `${year}`
    }
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
