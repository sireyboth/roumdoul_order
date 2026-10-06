import CashierBoard from "@/components/staff/cashier-board";

export const metadata = { title: "Cashier · Roumdoul Order" };

export default async function CashierPage({ searchParams }: PageProps<"/staff/cashier">) {
  const params = await searchParams;
  const branchId = Number(params.branch);

  return <CashierBoard key={branchId} branchId={branchId} />;
}
