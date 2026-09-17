import { AnimatePresence, motion } from "framer-motion";
import { TriangleAlert } from "lucide-react";
import { useEffect, useRef, useState } from "react";

export function ConfirmAction({
  label,
  confirmLabel,
  yesLabel,
  noLabel,
  onConfirm,
  className,
  disabled,
}: {
  label: string;
  confirmLabel?: string;
  yesLabel: string;
  noLabel: string;
  onConfirm: () => void;
  className?: string;
  disabled?: boolean;
}) {
  const [confirming, setConfirming] = useState(false);
  const timerRef = useRef<number | null>(null);

  useEffect(
    () => () => {
      if (timerRef.current) window.clearTimeout(timerRef.current);
    },
    [],
  );

  function cancel() {
    if (timerRef.current) window.clearTimeout(timerRef.current);
    setConfirming(false);
  }

  return (
    <AnimatePresence mode="wait" initial={false}>
      {confirming ? (
        <motion.span
          key="confirm"
          className="confirm-action is-active"
          initial={{ opacity: 0, scale: 0.92 }}
          animate={{ opacity: 1, scale: 1 }}
          exit={{ opacity: 0, scale: 0.92 }}
          transition={{ duration: 0.15, ease: "easeOut" }}
        >
          <TriangleAlert size={14} aria-hidden="true" className="confirm-action-icon" />
          {confirmLabel ? <span className="confirm-action-label">{confirmLabel}</span> : null}
          <button
            type="button"
            className="btn btn-ghost btn-confirm-yes"
            onClick={() => {
              cancel();
              onConfirm();
            }}
          >
            {yesLabel}
          </button>
          <button type="button" className="btn btn-ghost" onClick={cancel}>
            {noLabel}
          </button>
        </motion.span>
      ) : (
        <motion.button
          key="trigger"
          type="button"
          className={className ?? "btn btn-ghost btn-danger"}
          disabled={disabled}
          initial={{ opacity: 0 }}
          animate={{ opacity: 1 }}
          exit={{ opacity: 0 }}
          transition={{ duration: 0.15 }}
          onClick={() => {
            setConfirming(true);
            timerRef.current = window.setTimeout(() => setConfirming(false), 5000);
          }}
        >
          {label}
        </motion.button>
      )}
    </AnimatePresence>
  );
}
