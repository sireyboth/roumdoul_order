export type Lang = "km" | "en";

export type Names = { km: string | null; en: string | null; zh?: string | null };

export type MenuOption = {
  id: number;
  name: Names;
  price_delta: number;
  is_default: boolean;
};

export type OptionGroup = {
  id: number;
  name: Names;
  min: number;
  max: number;
  options: MenuOption[];
};

export type MenuItem = {
  id: number;
  name: Names;
  description: Names;
  image_url: string | null;
  price: number;
  sold_out_until: string | null;
  option_groups: OptionGroup[];
};

export type Category = {
  id: number;
  name: Names;
  image_url: string | null;
  items: MenuItem[];
};

export type TableMenu = {
  company: {
    name: string;
    logo_url: string | null;
    currency: "USD" | "KHR";
    khr_per_usd: number;
    vat_bp: number;
    service_charge_bp: number;
    prices_include_vat: boolean;
  };
  branch: { id: number; name: string; address: string | null; phone: string | null };
  table: { name: string; area: string | null };
  menu_version: string;
  categories: Category[];
};

export type CartLine = {
  key: string;
  itemId: number;
  name: Names;
  unitPrice: number;
  optionIds: number[];
  optionNames: Names[];
  quantity: number;
  note: string;
};

export type OrderStatus = "placed" | "accepted" | "preparing" | "ready" | "served" | "completed" | "cancelled";

export type PlacedOrderItem = {
  name: Names;
  quantity: number;
  unit_price: number;
  line_total: number;
  options: Names[];
  note: string | null;
};

export type PlacedOrder = {
  id: number;
  number: number;
  status: OrderStatus;
  currency: "USD" | "KHR";
  subtotal: number;
  note: string | null;
  placed_at: string;
  ready_at: string | null;
  items: PlacedOrderItem[];
};

export type TableBill = {
  number: number;
  total: number;
  total_khr: number;
  paid_total: number;
  discount_total: number;
  service_charge: number;
  vat: number;
};

export type TableSessionState = {
  status: "none" | "open" | "bill_requested" | "closed";
  subtotal: number;
  orders: PlacedOrder[];
  requests: { type: "waiter" | "bill"; created_at: string }[];
  /** Set once the cashier opens the bill: the real total with discounts, service charge and VAT. */
  bill: TableBill | null;
  /** When the table is free again: how the last visit ended (only "paid" is reported). */
  last_visit: { result: "paid"; closed_at: string } | null;
  /** Reverb channel for instant updates; null when live updates are off. */
  live_channel: string | null;
};
