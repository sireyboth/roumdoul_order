import WaiterOrder from "@/components/staff/waiter-order";
import BranchGate from "@/components/staff/branch-gate";
import { branchParam } from "@/lib/params";

export const metadata = { title: "New order · Roumdoul Order" };

export default async function WaiterOrderPage({ searchParams }: PageProps<"/staff/waiter/order">) {
  const params = await searchParams;
  const branchId = branchParam(params.branch);

  return (
    <BranchGate branchId={branchId}>
      <WaiterOrder key={branchId} branchId={branchId ?? 0} />
    </BranchGate>
  );
}
