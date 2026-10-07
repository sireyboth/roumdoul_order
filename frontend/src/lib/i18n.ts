import type { Lang, Names } from "./types";

export function pick(names: Names | null | undefined, lang: Lang): string {
  if (!names) return "";
  return (lang === "km" ? names.km || names.en : names.en || names.km) ?? "";
}

const text = {
  table: { km: "តុ", en: "Table" },
  add: { km: "បន្ថែម", en: "Add" },
  addToOrder: { km: "បន្ថែមទៅការកុម្ម៉ង់", en: "Add to order" },
  soldOut: { km: "អស់ហើយ", en: "Sold out" },
  required: { km: "ត្រូវជ្រើសរើស", en: "Required" },
  optional: { km: "ស្រេចចិត្ត", en: "Optional" },
  pickUpTo: { km: "ជ្រើសបានដល់", en: "Pick up to" },
  note: { km: "កំណត់ចំណាំ (ឧ. មិនដាក់ខ្ទឹម)", en: "Note (e.g. no onion)" },
  viewOrder: { km: "មើលការកុម្ម៉ង់", en: "View order" },
  yourOrder: { km: "ការកុម្ម៉ង់របស់អ្នក", en: "Your order" },
  subtotal: { km: "សរុបរង", en: "Subtotal" },
  vat: { km: "អាករ", en: "VAT" },
  service: { km: "សេវា", en: "Service charge" },
  total: { km: "សរុប", en: "Total" },
  send: { km: "ផ្ញើការកុម្ម៉ង់", en: "Send order" },
  sending: { km: "កំពុងផ្ញើ...", en: "Sending..." },
  sent: { km: "បានផ្ញើការកុម្ម៉ង់ទៅផ្ទះបាយហើយ", en: "Order sent to the kitchen" },
  orderNote: { km: "កំណត់ចំណាំសម្រាប់ការកុម្ម៉ង់ (ស្រេចចិត្ត)", en: "Note for the whole order (optional)" },
  myOrders: { km: "ការកុម្ម៉ង់របស់ខ្ញុំ", en: "My orders" },
  callWaiter: { km: "ហៅអ្នករត់តុ", en: "Call waiter" },
  waiterComing: { km: "អ្នករត់តុនឹងមកឆាប់ៗ", en: "A waiter is on the way" },
  requestBill: { km: "សុំវិក្កយបត្រ", en: "Request bill" },
  billComing: { km: "វិក្កយបត្រនឹងមកដល់ឆាប់ៗ", en: "Your bill is on the way" },
  orderNo: { km: "ការកុម្ម៉ង់", en: "Order" },
  st_placed: { km: "បានទទួល", en: "Received" },
  st_accepted: { km: "បានទទួល", en: "Received" },
  st_preparing: { km: "កំពុងរៀបចំ", en: "Preparing" },
  st_ready: { km: "រួចរាល់", en: "Ready" },
  st_served: { km: "បានបម្រើ", en: "Served" },
  st_completed: { km: "បានបញ្ចប់", en: "Completed" },
  st_cancelled: { km: "បានលុបចោល", en: "Cancelled" },
  visitTotal: { km: "សរុបការកុម្ម៉ង់ទាំងអស់", en: "Total so far" },
  tryAgain: { km: "មានបញ្ហា។ សូមព្យាយាមម្តងទៀត។", en: "Something went wrong. Please try again." },
  empty: { km: "មិនទាន់មានអ្វីនៅឡើយ", en: "Nothing added yet" },
  close: { km: "បិទ", en: "Close" },
  paidThanks: { km: "បានបង់ប្រាក់ហើយ សូមអរគុណ!", en: "Paid, thank you!" },
  seeYou: { km: "សង្ឃឹមថានឹងបានជួបលោកអ្នកម្តងទៀត។", en: "We hope to see you again soon." },
  discount: { km: "បញ្ចុះតម្លៃ", en: "Discount" },
  bill: { km: "វិក្កយបត្រ", en: "Bill" },
  paid: { km: "បានបង់", en: "Paid" },
  leftToPay: { km: "នៅសល់ត្រូវបង់", en: "Left to pay" },
  search: { km: "ស្វែងរកម្ហូប ភេសជ្ជៈ...", en: "Search food, drinks..." },
  noResults: { km: "រកមិនឃើញទេ", en: "Nothing matches your search" },
  menu: { km: "ម៉ឺនុយ", en: "Menu" },
  goesWellWith: { km: "ញ៉ាំជាមួយនេះក៏ឆ្ងាញ់ដែរ", en: "Goes well with" },
  addedToOrder: { km: "បានបន្ថែមទៅការកុម្ម៉ង់", en: "Added to your order" },
  noThanks: { km: "ទេ អរគុណ", en: "No thanks" },
  youMightLike: { km: "អ្នកប្រហែលជាចូលចិត្ត", en: "You might also like" },
  orderSent: { km: "បានផ្ញើការកុម្ម៉ង់!", en: "Order sent!" },
  orderSentText: { km: "ផ្ទះបាយបានទទួលហើយ។ តាមដានការកុម្ម៉ង់របស់អ្នកនៅខាងក្រោម។", en: "The kitchen has it. Follow your order below." },
  locTitle: { km: "សូមបើកទីតាំង", en: "Share your location" },
  locTitleFar: { km: "អ្នកនៅក្រៅហាង", en: "Outside the shop" },
  locTitleProblem: { km: "រកទីតាំងមិនបាន", en: "Location not available" },
  locWhy: {
    km: "ហាងនេះទទួលការកុម្ម៉ង់តែពីភ្ញៀវនៅក្នុងហាងប៉ុណ្ណោះ។ ទូរស័ព្ទរបស់អ្នកនឹងផ្ញើទីតាំងម្តង ដើម្បីបញ្ជាក់ថាអ្នកនៅទីនេះ។",
    en: "This shop only takes orders from guests inside. Your phone shares its location once to confirm you are here.",
  },
  locAllow: { km: "អនុញ្ញាត និងបន្ត", en: "Allow and continue" },
  locChecking: { km: "កំពុងពិនិត្យទីតាំង...", en: "Checking your location..." },
  locDenied: {
    km: "ទីតាំងត្រូវបានបិទ។ សូមបើកការអនុញ្ញាតទីតាំងសម្រាប់គេហទំព័រនេះ (ចុចរូបសោរនៅលើរបារអាសយដ្ឋាន) ហើយព្យាយាមម្តងទៀត។",
    en: "Location is blocked. Allow location for this page (tap the lock icon next to the address), then try again.",
  },
  locUnavailable: {
    km: "រកទីតាំងមិនឃើញទេ។ សូមបើក GPS / Location នៅលើទូរស័ព្ទ ហើយព្យាយាមម្តងទៀត។",
    en: "Your location could not be found. Turn on Location (GPS) on your phone and try again.",
  },
  locTooFar: {
    km: "អ្នកហាក់ដូចជានៅក្រៅហាង។ ការកុម្ម៉ង់អាចផ្ញើបានតែពីក្នុងហាងប៉ុណ្ណោះ។ ប្រសិនបើអ្នកនៅទីនេះ សូមព្យាយាមម្តងទៀត ឬសួរបុគ្គលិក។",
    en: "You seem to be outside the shop. Orders can only be sent from inside. If you are here, try again or ask a staff member.",
  },
  locInsecure: {
    km: "ទំព័រនេះមិនអាចសួរទីតាំងបានទេ។ សូមសួរបុគ្គលិក។",
    en: "This page cannot ask for your location. Please ask a staff member.",
  },
  tryAgainBtn: { km: "ព្យាយាមម្តងទៀត", en: "Try again" },
  askStaff: { km: "ឬហៅបុគ្គលិកដោយផ្ទាល់ — គេអាចកុម្ម៉ង់ឱ្យអ្នកបាន។", en: "Or wave to a staff member: they can order for you." },
  cartEmptyText: { km: "ជ្រើសម្ហូបពីម៉ឺនុយ ដើម្បីចាប់ផ្តើម", en: "Pick something from the menu to start" },
} satisfies Record<string, Record<Lang, string>>;

export function t(key: keyof typeof text, lang: Lang): string {
  return text[key][lang];
}
