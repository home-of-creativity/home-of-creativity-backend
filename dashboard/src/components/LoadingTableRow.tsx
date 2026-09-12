import { LoadingLottie } from "./LoadingLottie";

export function LoadingTableRow({ colSpan, label }: { colSpan: number; label?: string }) {
  return (
    <tr>
      <td colSpan={colSpan} className="loading-cell">
        <LoadingLottie label={label} />
      </td>
    </tr>
  );
}
