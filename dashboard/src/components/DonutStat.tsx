import { useState } from "react";

export type DonutSlice = { key: string; value: number; color: string; label: string };

const RADIUS = 15.9155;
const CIRCUMFERENCE = 2 * Math.PI * RADIUS;
const GAP = 1.15;

function visibleSlices(slices: DonutSlice[], total: number) {
  if (total <= 0) return [];
  return slices.filter((slice) => slice.value > 0);
}

export function DonutStat({
  slices,
  total,
  centerLabel,
  emptyLabel,
}: {
  slices: DonutSlice[];
  total: number;
  centerLabel?: string;
  emptyLabel?: string;
}) {
  const data = visibleSlices(slices, total);
  const [tip, setTip] = useState<DonutSlice | null>(null);
  const gap = data.length > 1 ? GAP : 0;
  let offset = 0;

  return (
    <div className="donut-stat">
      <div className="donut-stat-chart" role="img" aria-label={centerLabel ?? ""}>
        {total > 0 ? (
          <svg viewBox="0 0 36 36" className="donut-stat-svg" aria-hidden="true">
            <circle cx="18" cy="18" r={RADIUS} fill="none" stroke="var(--brand-line)" strokeWidth="4" pointerEvents="none" />
            {data.map((slice) => {
              const length = Math.max((slice.value / total) * CIRCUMFERENCE - gap, 0.01);
              const dashOffset = offset;
              offset += (slice.value / total) * CIRCUMFERENCE;
              return (
                <circle
                  key={slice.key}
                  className="donut-stat-slice"
                  cx="18"
                  cy="18"
                  r={RADIUS}
                  fill="none"
                  stroke={slice.color}
                  strokeWidth="4"
                  strokeDasharray={`${length} ${CIRCUMFERENCE}`}
                  strokeDashoffset={-dashOffset}
                  transform="rotate(-90 18 18)"
                  onMouseEnter={() => setTip(slice)}
                  onMouseLeave={() => setTip(null)}
                >
                  <title>{`${slice.label} ${slice.value}`}</title>
                </circle>
              );
            })}
          </svg>
        ) : (
          <svg viewBox="0 0 36 36" className="donut-stat-svg" aria-hidden="true">
            <circle cx="18" cy="18" r={RADIUS} fill="none" stroke="var(--brand-line)" strokeWidth="4" />
          </svg>
        )}
        <span className="donut-stat-total" aria-hidden="true">
          {total}
        </span>
        {tip ? (
          <p className="donut-stat-tip" role="tooltip">
            <span>{tip.label}</span>
            <strong>{tip.value}</strong>
          </p>
        ) : null}
      </div>
      <div className="donut-stat-legend">
        {total === 0 && emptyLabel ? <p className="muted">{emptyLabel}</p> : null}
        {slices.map((slice) => (
          <div className="donut-stat-legend-item" key={slice.key}>
            <span className="donut-stat-swatch" style={{ background: slice.color }} aria-hidden="true" />
            <span>{slice.label}</span>
            <strong>{slice.value}</strong>
          </div>
        ))}
      </div>
    </div>
  );
}
