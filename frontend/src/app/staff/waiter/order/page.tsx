import WaiterOrder from "@/components/staff/waiter-order";

export const metadata = { title: "New order · Roumdoul Order" };

export default async function WaiterOrderPage({ searchParams }: PageProps<"/staff/waiter/order">) {
  const params = await searchParams;
  const branchId = Number(params.branch);

  return <WaiterOrder key={branchId} branchId={branchId} />;
}
