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