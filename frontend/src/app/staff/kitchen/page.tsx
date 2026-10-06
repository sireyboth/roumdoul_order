import KitchenBoard from "@/components/staff/kitchen-board";
import BranchGate from "@/components/staff/branch-gate";
import { branchParam } from "@/lib/params";

export const metadata = { title: "Kitchen · Roumdoul Order" };

export default async function KitchenPage({ searchParams }: PageProps<"/staff/kitchen">) {
  const params = await searchParams;
  const branchId = branchParam(params.branch);
  const station = params.station === "kitchen" || params.station === "bar" ? params.station : null;

  return (
    <BranchGate branchId={branchId}>
      <KitchenBoard key={`${branchId}-${station}`} branchId={branchId ?? 0} station={station} />
    </BranchGate>
  );
}
