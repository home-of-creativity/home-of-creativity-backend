import { motion } from "framer-motion";
import type { ReactNode } from "react";

export function StatCard({
  icon,
  label,
  value,
  tone,
}: {
  icon?: ReactNode;
  label: string;
  value: ReactNode;
  tone?: "orange" | "teal" | "danger" | "default";
}) {
  return (
    <motion.article
      className="card card-accent stat-card"
      initial={{ opacity: 0, y: 10 }}
      animate={{ opacity: 1, y: 0 }}
      transition={{ duration: 0.25, ease: "easeOut" }}
    >
      <div className="card-head">
        {icon ? (
          <span className="card-icon">{icon}</span>
        ) : (
          <span className={`card-dot card-dot-${tone ?? "default"}`} aria-hidden="true" />
        )}
        <span className="muted">{label}</span>
      </div>
      <strong>{value}</strong>
    </motion.article>
  );
}
