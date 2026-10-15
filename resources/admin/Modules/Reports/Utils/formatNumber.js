export const formatNumber = (number) => {
  // If it's a valid number, format it
  if (number !== null && number !== undefined && !isNaN(Number(number))) {
    return new Intl.NumberFormat(undefined, {
      maximumFractionDigits: 2,
    }).format(Number(number));
  }
  
  // If not a valid number, return the original value
  return number;
};
// Compact form for values that would otherwise overflow a table cell: 203,822,241 reads 203.8M
export const formatCompactNumber = (number) => {
  if (number === null || number === undefined || isNaN(Number(number))) {
    return number;
  }
  const value = Number(number);
  if (Math.abs(value) < 10000) {
    return formatNumber(value);
  }
  return new Intl.NumberFormat(undefined, {
    notation: "compact",
    maximumFractionDigits: 1,
  }).format(value);
};
