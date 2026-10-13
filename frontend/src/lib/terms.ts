/*
 * Terms and conditions for restaurants using Roumdoul Order (shown at /terms).
 * When you change anything here, also change TERMS_VERSION in backend/.env (or config/tok.php) to the
 * same date: owners are then asked to agree again at their next sign-up or plan change.
 * The numbers (trial days, 3 days) must match backend config/tok.php and TRIAL_DAYS.
 * Draft in plain language: have it checked by someone who knows Cambodian law before relying on it.
 */

export const TERMS_VERSION = "2026-10-13";
export const TERMS_EFFECTIVE = "13 October 2026";

export type TermsSection = { id: string; title: string; km: string; points: string[] };

export const TERMS: TermsSection[] = [
  {
    id: "service",
    title: "The service",
    km: "សេវាកម្ម",
    points: [
      "Roumdoul Order (\"the service\") is QR ordering software for cafés and restaurants, provided by Roumdoul (\"we\", \"us\"). Guests order from their phone; your staff use the kitchen, waiter and cashier screens; you manage everything in the back office.",
      "These terms are an agreement between us and the business that registers a restaurant (\"you\"). The person who registers confirms they may agree for that business.",
      "You agree to these terms when you register, and again each time you choose or change a plan.",
    ],
  },
  {
    id: "account",
    title: "Your account and free trial",
    km: "គណនី និងការសាកល្បងឥតគិតថ្លៃ",
    points: [
      "New restaurants get a free trial (14 days unless we agree otherwise). No payment is needed during the trial.",
      "Give us a working phone number (and Telegram if you have one) so we can reach you about setup, your plan and payments. Keep them up to date in Restaurant settings.",
      "You are responsible for everyone you give a login to, and for keeping passwords and the manager PIN private. Tell us at once if you think an account is misused.",
    ],
  },
  {
    id: "plans",
    title: "Plans and prices",
    km: "គម្រោង និងតម្លៃ",
    points: [
      "Each plan has a price (monthly or yearly), limits (branches, tables, staff) and features. The current list is on our website.",
      "The price you agree to when your plan starts stays the same for that plan. If we change our prices, it applies to new plans and plan changes, and we tell you before it affects you.",
      "Features your plan does not include are locked in your back office and hidden from staff and guests. Nothing you set up is deleted: it comes back when you upgrade.",
      "If you have more branches, tables or staff than a smaller plan allows, nothing is removed, but you cannot add more until you are within the limit.",
      "Features marked \"Coming soon\" are planned but not ready; we do not promise a date.",
    ],
  },
  {
    id: "payment",
    title: "Paying for your plan",
    km: "ការទូទាត់",
    points: [
      "You pay in advance for each period (month or year). We make your invoice about 3 days before the period ends; it is due on the day the period ends.",
      "You pay by ABA or KHQR transfer to the account shown on your \"Your plan\" page, then send us the screenshot with \"I have paid\". Your payment counts once we have confirmed it.",
      "Pay-per-order plans are billed after each period for the orders of that period.",
      "Prices are in US dollars unless your invoice says otherwise. Bank fees on your side are yours to pay.",
    ],
  },
  {
    id: "changes",
    title: "Upgrading and downgrading",
    km: "ការប្ដូរគម្រោង",
    points: [
      "Upgrade (a plan that costs more): you pay only the difference for the days left in your current period: (new price − old price) × days left ÷ days in the period. The new plan starts as soon as we confirm the payment. Your renewal date does not change.",
      "Switching from monthly to yearly starts a new year from that day; what is left of your current month is taken off the price.",
      "Downgrade (a plan that costs less): it starts at your next renewal. You keep your current plan until then. There is no refund for the rest of the period. You can cancel a downgrade before it starts.",
      "During the free trial you can change plan at any time without paying.",
    ],
  },
  {
    id: "late",
    title: "Late payment",
    km: "ការបង់ប្រាក់យឺត",
    points: [
      "If an invoice is not paid 3 days after its due date, your back office becomes read-only: you can see everything and pay, but not change anything. Guests can still order and your staff screens keep working, so your customers are not affected.",
      "Everything unlocks as soon as we confirm your payment.",
      "If you still do not pay after we have contacted you, we may suspend the restaurant, which stops QR ordering and staff screens until the account is settled.",
    ],
  },
  {
    id: "refunds",
    title: "Refunds",
    km: "ការសងប្រាក់វិញ",
    points: [
      "Payments for a period that has started are not refunded, including after a downgrade or if you stop using the service.",
      "If you paid twice, or paid because of our mistake, we pay it back or count it towards your next invoice.",
    ],
  },
  {
    id: "your-data",
    title: "Your data and your guests' data",
    km: "ទិន្នន័យ",
    points: [
      "Your menu, photos, orders, sales and reports belong to you. We use them only to run the service for you, keep it working and safe, and help you when you ask.",
      "We do not sell your data or your guests' data, and we do not show your data to other restaurants.",
      "Guests' orders, and the location their phone shares when you turn on \"only accept orders from inside the shop\", are used only to take and check the order.",
      "When you leave, you can export your sales as CSV first. We may keep records we must keep by law, and delete the rest after a reasonable time.",
    ],
  },
  {
    id: "your-part",
    title: "Your part",
    km: "ការទទួលខុសត្រូវរបស់អ្នក",
    points: [
      "You are responsible for your menu, prices, taxes (such as VAT), receipts and how you run your business. The service calculates totals from the prices and rates you set.",
      "Use the service lawfully. Do not try to break it, get into other restaurants' data, or resell it without our written agreement.",
      "You need your own internet connection and devices (phones, tablets, printers).",
    ],
  },
  {
    id: "availability",
    title: "Availability, support and liability",
    km: "សេវាកម្ម និងការទទួលខុសត្រូវ",
    points: [
      "We work to keep the service running all day, every day, but it can be interrupted (internet problems, updates, things outside our control). Staff can always take orders by hand.",
      "Our team helps you by phone and Telegram during working hours.",
      "As far as the law allows, we are not responsible for lost profit or indirect losses, and our total responsibility is limited to what you paid us in the 3 months before the problem.",
    ],
  },
  {
    id: "ending",
    title: "Stopping",
    km: "ការបញ្ចប់",
    points: [
      "You can stop at any time: tell us, and your plan ends at the end of the period you paid for.",
      "We may suspend or close an account that does not pay, breaks these terms, or puts the service or other restaurants at risk. Where possible we contact you first.",
    ],
  },
  {
    id: "updates",
    title: "Changes to these terms",
    km: "ការកែប្រែលក្ខខណ្ឌ",
    points: [
      "We may update these terms. The date at the top shows the current version. If a change affects you, we tell you, and you are asked to agree again at your next sign-up or plan change.",
      "These terms follow the laws of the Kingdom of Cambodia.",
    ],
  },
];
