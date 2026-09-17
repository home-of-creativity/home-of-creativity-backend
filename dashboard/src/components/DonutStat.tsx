import { Cell, Pie, PieChart, Tooltip } from "recharts";

export type DonutSlice = { key: string; value: number; color: string; label: string };

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
  const data = slices.filter((slice) => slice.value > 0);

  return (
    <div className="donut-stat">
      <div className="donut-stat-chart" role="img" aria-label={centerLabel ?? ""}>
        {total > 0 ? (
          <PieChart width={168} height={168}>
            <Pie
              data={data}
              dataKey="value"
              nameKey="label"
              cx="50%"
              cy="50%"
              innerRadius={54}
              outerRadius={78}
              paddingAngle={data.length > 1 ? 3 : 0}
              stroke="none"
              isAnimationActive
              animationDuration={600}
            >
              {data.map((slice) => (
                <Cell key={slice.key} fill={slice.color} />
              ))}
            </Pie>
            <Tooltip
              formatter={(value, _name, item) => [value ?? 0, item?.payload?.label ?? ""]}
              contentStyle={{
                borderRadius: 12,
                border: "1px solid var(--brand-line)",
                fontSize: 13,
                boxShadow: "0 8px 24px rgba(20, 12, 40, 0.12)",
              }}
            />
          </PieChart>
        ) : (
          <svg viewBox="0 0 36 36" className="donut-stat-svg" aria-hidden="true">
            <circle cx="18" cy="18" r="15.9155" fill="none" stroke="var(--brand-line)" strokeWidth="4" />
          </svg>
        )}
        <span className="donut-stat-total" aria-hidden="true">
          {total}
        </span>
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
