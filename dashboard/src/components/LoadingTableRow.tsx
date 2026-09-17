import Skeleton from "react-loading-skeleton";
import "react-loading-skeleton/dist/skeleton.css";

export function LoadingTableRow({ colSpan, label, rows = 4 }: { colSpan: number; label?: string; rows?: number }) {
  return (
    <>
      {Array.from({ length: rows }).map((_, index) => (
        <tr key={index} className="skeleton-row" aria-hidden="true">
          <td colSpan={colSpan}>
            <Skeleton height={16} borderRadius={6} />
          </td>
        </tr>
      ))}
      {label ? (
        <tr className="sr-only-row">
          <td colSpan={colSpan} className="sr-only">
            {label}
          </td>
        </tr>
      ) : null}
    </>
  );
}
