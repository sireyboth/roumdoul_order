/** "12" => 12; missing, "NaN", "0" or anything else => null. Used by staff pages for ?branch=. */
export function branchParam(value: string | string[] | undefined): number | null {
  const id = Number(Array.isArray(value) ? value[0] : value);
  return Number.isInteger(id) && id > 0 ? id : null;
}
