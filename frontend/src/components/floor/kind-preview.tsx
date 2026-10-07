import type { FloorKind } from "@/lib/floor";

/** Small top-down drawings for the materials palette. */
export default function KindPreview({ kind, className = "size-12" }: { kind: FloorKind; className?: string }) {
  const wood = "#b9834f";
  const chair = "#5b3d27";
  const content = (() => {
    switch (kind) {
      case "table_square":
        return (
          <>
            {[[19, 6], [19, 34], [6, 19], [34, 19]].map(([x, y], i) => <rect key={i} x={x} y={y} width="10" height="8" rx="2" fill={chair} />)}
            <rect x="13" y="13" width="22" height="22" rx="4" fill={wood} />
          </>
        );
      case "table_round":
        return (
          <>
            {[0, 1, 2, 3].map((i) => {
              const a = (i / 4) * Math.PI * 2 - Math.PI / 4;
              return <circle key={i} cx={24 + Math.cos(a) * 17} cy={24 + Math.sin(a) * 17} r="5" fill={chair} />;
            })}
            <circle cx="24" cy="24" r="12" fill={wood} />
          </>
        );
      case "table_long":
        return (
          <>
            {[8, 19, 30].map((x) => <rect key={`t${x}`} x={x} y="9" width="9" height="7" rx="2" fill={chair} />)}
            {[8, 19, 30].map((x) => <rect key={`b${x}`} x={x} y="32" width="9" height="7" rx="2" fill={chair} />)}
            <rect x="4" y="17" width="40" height="14" rx="3" fill={wood} />
          </>
        );
      case "booth":
        return (
          <>
            <rect x="6" y="6" width="36" height="9" rx="3" fill="#7b4b3a" />
            <rect x="6" y="33" width="36" height="9" rx="3" fill="#7b4b3a" />
            <rect x="13" y="18" width="22" height="12" rx="3" fill={wood} />
          </>
        );
      case "chair":
        return (
          <>
            <rect x="14" y="16" width="20" height="18" rx="4" fill="#3f6e5b" />
            <rect x="14" y="12" width="20" height="5" rx="2" fill="#2d5143" />
          </>
        );
      case "stool":
        return <circle cx="24" cy="24" r="10" fill="#2c3e50" stroke="#5d6d7e" strokeWidth="3" />;
      case "sofa":
        return (
          <>
            <rect x="6" y="14" width="36" height="20" rx="5" fill="#6d4c41" />
            <rect x="6" y="14" width="36" height="7" rx="3" fill="#4e342e" />
            <rect x="6" y="14" width="6" height="20" rx="3" fill="#5d4037" />
            <rect x="36" y="14" width="6" height="20" rx="3" fill="#5d4037" />
          </>
        );
      case "bar_counter":
        return (
          <>
            <rect x="4" y="12" width="40" height="13" rx="3" fill="#5e3b25" />
            {[12, 24, 36].map((x) => <circle key={x} cx={x} cy="33" r="4.5" fill="#2c3e50" />)}
          </>
        );
      case "cashier":
        return (
          <>
            <rect x="5" y="16" width="38" height="17" rx="3" fill="#34495e" />
            <rect x="28" y="19" width="11" height="8" rx="1.5" fill="#79c3e8" />
          </>
        );
      case "kitchen":
        return (
          <>
            <rect x="4" y="12" width="40" height="24" rx="3" fill="#aab4bb" />
            {[13, 24, 35].map((x) => <circle key={x} cx={x} cy="24" r="4.5" fill="#2b2b2b" stroke="#555" strokeWidth="1.5" />)}
          </>
        );
      case "wall":
        return <rect x="4" y="19" width="40" height="10" rx="1.5" fill="#c9b8a3" />;
      case "door":
        return (
          <>
            <rect x="8" y="34" width="32" height="6" rx="1.5" fill="#6d4c41" />
            <path d="M10 34 L10 12 A22 22 0 0 1 32 34" fill="none" stroke="#0d7a5a" strokeWidth="2" strokeDasharray="3 3" />
          </>
        );
      case "window":
        return (
          <>
            <rect x="4" y="20" width="40" height="8" rx="1" fill="#efe9df" stroke="#c9b8a3" />
            <rect x="6" y="22.5" width="36" height="3" fill="#9fd3ea" />
          </>
        );
      case "pillar":
        return <rect x="16" y="16" width="16" height="16" rx="2" fill="#bcb3a5" />;
      case "divider":
        return <rect x="4" y="20" width="40" height="8" rx="4" fill="#6a994e" />;
      case "stairs":
        return (
          <>
            {[0, 1, 2, 3, 4].map((i) => <rect key={i} x="12" y={6 + i * 7.5} width="24" height="6.5" rx="1" fill={i % 2 ? "#b8a58e" : "#c9b8a3"} />)}
          </>
        );
      case "restroom":
        return (
          <>
            <rect x="8" y="8" width="32" height="32" rx="5" fill="#cfd8dc" />
            <text x="24" y="29" textAnchor="middle" fontSize="12" fontWeight="800" fill="#546e7a">WC</text>
          </>
        );
      case "plant":
        return (
          <>
            {[0, 1, 2, 3, 4].map((i) => {
              const a = (i / 5) * Math.PI * 2;
              return <ellipse key={i} cx={24 + Math.cos(a) * 8} cy={24 + Math.sin(a) * 8} rx="8" ry="5" transform={`rotate(${(a * 180) / Math.PI} ${24 + Math.cos(a) * 8} ${24 + Math.sin(a) * 8})`} fill="#4c956c" />;
            })}
            <circle cx="24" cy="24" r="6" fill="#6fbf8b" />
          </>
        );
      case "rug":
        return <rect x="5" y="10" width="38" height="28" rx="4" fill="#c8a27a" stroke="#a5805a" strokeWidth="3" />;
      case "lamp":
        return (
          <>
            <circle cx="24" cy="24" r="18" fill="#f6c453" opacity=".25" />
            <circle cx="24" cy="24" r="9" fill="#f6c453" stroke="#e0a62f" strokeWidth="2" />
          </>
        );
    }
  })();

  return (
    <svg viewBox="0 0 48 48" className={className} aria-hidden>
      {content}
    </svg>
  );
}
