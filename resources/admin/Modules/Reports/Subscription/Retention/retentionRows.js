import { prepareLabel } from "@/Modules/Reports/Utils/decorator";
import translate from "@/utils/translator/Translator";

// A period still running is shown, but never compared with closed ones
export const completeRows = (rows) => (rows || []).filter((row) => row.is_complete !== false);

export const headlineRow = (rows) => {
  const complete = completeRows(rows);

  return complete.length ? complete[complete.length - 1] : null;
};

export const periodLabel = (row) => {
  const label = prepareLabel(String(row.group));

  if (row.is_running) {
    /* translators: %1$s - a month or a year that has not ended yet */
    return translate("%1$s (so far)", label);
  }

  /* translators: %1$s - a month or a year the date range starts inside of */
  return row.is_complete === false ? translate("%1$s (partial)", label) : label;
};

export const renewalsDue = (row) => (Number(row.renewals_paid) || 0) + (Number(row.renewals_lost) || 0);
