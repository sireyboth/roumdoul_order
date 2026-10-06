import PrintReceipt from "@/components/staff/print-receipt";

export const metadata = { title: "Receipt · Roumdoul Order" };

export default async function ReceiptPage({ searchParams }: PageProps<"/staff/print/receipt">) {
  const params = await searchParams;

  return <PrintReceipt billId={Number(params.bill)} auto={params.auto === "1"} />;
}
