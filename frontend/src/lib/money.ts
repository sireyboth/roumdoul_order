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

/** Nearest 100៛, halves up (same as the server's Money::roundRiel). */
export function roundRiel(khr: number): number {
  return Math.floor(khr / 100 + 0.5) * 100;
}

/** "20" dollars => 2000 cents, "20000" riel => 20000. Null when not a positive number. */
export function parseAmount(typed: string, currency: "USD" | "KHR"): number | null {
  const value = Number(typed.replace(/[,\s$៛]/g, ""));
  if (!Number.isFinite(value) || value <= 0) return null;
  return currency === "KHR" ? Math.round(value) : Math.round(value * 100);
}

/**
 * What a cash payment will do, worked out exactly like the server (BillService::cash)
 * so the screen can show the change before the cashier taps Pay.
 */
export function cashPreview(
  due: number,
  billCurrency: "USD" | "KHR",
  rate: number,
  tendered: number,
  tenderedCurrency: "USD" | "KHR",
  changeCurrency: "USD" | "KHR",
): { applied: number; change: number; dueInTendered: number } {
  const dueInTendered =
    tenderedCurrency === "KHR"
      ? billCurrency === "KHR"
        ? roundRiel(due)
        : roundRiel((due * rate) / 100)
      : billCurrency === "USD"
        ? due
        : Math.ceil((due * 100) / rate);

  if (tendered >= dueInTendered) {
    const excess = tendered - dueInTendered;
    const change =
      excess === 0 || changeCurrency === tenderedCurrency
        ? excess
        : changeCurrency === "KHR"
          ? roundRiel((excess * rate) / 100)
          : Math.floor((excess * 100) / rate);
    return { applied: due, change, dueInTendered };
  }

  const applied =
    tenderedCurrency === billCurrency
      ? tendered
      : tenderedCurrency === "KHR"
        ? Math.floor((tendered * 100) / rate)
        : Math.floor((tendered * rate) / 100);
  return { applied, change: 0, dueInTendered };
}
