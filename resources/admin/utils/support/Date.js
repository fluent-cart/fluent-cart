import dayjs from "dayjs";
import timezone from "dayjs/plugin/timezone"; // Add timezone plugin
import utc from "dayjs/plugin/utc";

dayjs.extend(utc);
dayjs.extend(timezone); // Enable timezone handling

export default class Date {
  /**
   * Formats the given date with local timezone information
   * @param {string|Date|dayjs.Dayjs} inputDate
   * @returns {string} ISO format with timezone offset
   */
  static withTimezone(inputDate) {
    const parsed = dayjs(inputDate);
    if (!parsed.isValid()) {
      return null;
    }
    
    // Format with timezone offset (e.g., 2025-05-12T10:30:00-04:00)
    return parsed.format("YYYY-MM-DDTHH:mm:ssZ");
  }
}