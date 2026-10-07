import FloorEditor from "@/components/floor/floor-editor";
import BranchGate from "@/components/staff/branch-gate";
import { branchParam } from "@/lib/params";

export const metadata = { title: "Floor plan · Roumdoul Order" };

export default async function FloorPage({ searchParams }: PageProps<"/staff/floor">) {
  const params = await searchParams;
  const branchId = branchParam(params.branch);

  return (
    <BranchGate branchId={branchId}>
      <FloorEditor key={branchId} branchId={branchId ?? 0} />
    </BranchGate>
  );
}
