import * as Switch from "@radix-ui/react-switch";
import { motion } from "framer-motion";
import type { ReactNode } from "react";
import { EligibilityBadge } from "./EligibilityBadge";

export function PlacementTile({
  icon,
  iconTone,
  title,
  subtitle,
  on,
  disabled,
  disabledReason,
  eligible,
  eligibleLabel,
  focused,
  onToggle,
  onHoverChange,
}: {
  icon: ReactNode;
  iconTone: "facebook" | "instagram" | "threads" | "linkedin" | "default";
  title: string;
  subtitle?: string;
  on: boolean;
  disabled?: boolean;
  disabledReason?: string;
  eligible?: boolean;
  eligibleLabel?: string;
  focused?: boolean;
  onToggle: () => void;
  onHoverChange?: (hovering: boolean) => void;
}) {
  const classes = ["placement-tile"];
  if (on) classes.push("is-on");
  if (focused) classes.push("is-focused");
  if (disabled) classes.push("is-disabled");

  return (
    <motion.div
      className={classes.join(" ")}
      onMouseEnter={() => onHoverChange?.(true)}
      onMouseLeave={() => onHoverChange?.(false)}
      onFocus={() => onHoverChange?.(true)}
      onBlur={() => onHoverChange?.(false)}
      initial={{ opacity: 0, y: 6 }}
      animate={{ opacity: 1, y: 0, scale: on ? 1 : 1 }}
      whileTap={disabled ? undefined : { scale: 0.98 }}
      transition={{ duration: 0.18, ease: "easeOut" }}
    >
      <span className={`placement-tile-icon is-${iconTone}`} aria-hidden="true">
        {icon}
      </span>
      <div className="placement-tile-body">
        <p className="placement-tile-title">{title}</p>
        {subtitle ? <p className="placement-tile-sub">{subtitle}</p> : null}
        {disabled && disabledReason ? <EligibilityBadge ok={false} label={disabledReason} /> : null}
        {!disabled && eligible === false && eligibleLabel ? <EligibilityBadge ok={false} label={eligibleLabel} /> : null}
      </div>
      <Switch.Root
        className={on ? "placement-tile-toggle is-on" : "placement-tile-toggle"}
        checked={on}
        disabled={disabled}
        aria-label={title}
        onCheckedChange={() => onToggle()}
      >
        <Switch.Thumb className="placement-tile-toggle-thumb" />
      </Switch.Root>
    </motion.div>
  );
}
