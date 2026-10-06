/** Prices arrive as whole minor units: USD cents or whole riel. */
export function formatMoney(minor: number, currency: "USD" | "KHR"): string {
  if (currency === "KHR") {
    return `${Math.round(minor).toLocaleString("en-US")}៛`;
  }
  return `$${(minor / 100).toFixed(2)}`;
}

/** Riel equivalent of a USD amount, rounded to the nearest 100៛ like Cambodian cash. */
export function toRiel(minorUsd: number, khrPerUsd: number): number {
  return Math.round(((minorUsd / 100) * khrPerUsd) / 100) * 100;
}

export function percentOf(minor: number, basisPoints: number): number {
  return Math.round((minor * basisPoints) / 10000);
}
