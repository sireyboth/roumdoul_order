import CashierBoard from "@/components/staff/cashier-board";
import BranchGate from "@/components/staff/branch-gate";
import { branchParam } from "@/lib/params";

export const metadata = { title: "Cashier · Roumdoul Order" };

export default async function CashierPage({ searchParams }: PageProps<"/staff/cashier">) {
  const params = await searchParams;
  const branchId = branchParam(params.branch);

  return (
    <BranchGate branchId={branchId}>
      <CashierBoard key={branchId} branchId={branchId ?? 0} />
    </BranchGate>
  );
}
