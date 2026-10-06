import WaiterBoard from "@/components/staff/waiter-board";
import BranchGate from "@/components/staff/branch-gate";
import { branchParam } from "@/lib/params";

export const metadata = { title: "Waiter · Roumdoul Order" };

export default async function WaiterPage({ searchParams }: PageProps<"/staff/waiter">) {
  const params = await searchParams;
  const branchId = branchParam(params.branch);

  return (
    <BranchGate branchId={branchId}>
      <WaiterBoard branchId={branchId ?? 0} />
    </BranchGate>
  );
}
