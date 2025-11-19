import countries from "@/Modules/Customers/countries.json";

export default function toCountryName(code) {
  return countries.find((country) => country.code2 === code)?.name || 'Uncategorized';
}
