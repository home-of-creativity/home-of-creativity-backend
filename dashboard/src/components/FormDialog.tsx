import { useEffect, useId, useRef, type FormEvent, type ReactNode } from "react";
import { createPortal } from "react-dom";
import { IconClose } from "./icons";

type FormDialogProps = {
  title: string;
  eyebrow?: string;
  onClose: () => void;
  onSubmit: (event: FormEvent<HTMLFormElement>) => void;
  submitLabel: string;
  cancelLabel: string;
  closeLabel: string;
  error?: string;
  size?: "md" | "lg";
  busy?: boolean;
  children: ReactNode;
};

export function FormDialog({
  title,
  eyebrow,
  onClose,
  onSubmit,
  submitLabel,
  cancelLabel,
  closeLabel,
  error,
  size = "md",
  busy = false,
  children,
}: FormDialogProps) {
  const titleId = useId();
  const panelRef = useRef<HTMLDivElement>(null);
  const lastFocus = useRef<HTMLElement | null>(null);
  const onCloseRef = useRef(onClose);
  onCloseRef.current = onClose;

  useEffect(() => {
    lastFocus.current = document.activeElement instanceof HTMLElement ? document.activeElement : null;
    const previousOverflow = document.body.style.overflow;
    document.body.style.overflow = "hidden";

    const onKey = (event: KeyboardEvent) => {
      if (event.key === "Escape") onCloseRef.current();
    };
    document.addEventListener("keydown", onKey);

    const firstField = panelRef.current?.querySelector<HTMLElement>("input:not([type=hidden]), select, textarea");
    (firstField ?? panelRef.current?.querySelector<HTMLElement>(".form-dialog-close"))?.focus();

    return () => {
      document.body.style.overflow = previousOverflow;
      document.removeEventListener("keydown", onKey);
      lastFocus.current?.focus();
    };
  }, []);

  return createPortal(
    <div className="form-dialog">
      <div className="form-dialog-backdrop" onClick={onClose} />
      <div
        ref={panelRef}
        role="dialog"
        aria-modal="true"
        aria-labelledby={titleId}
        className={size === "lg" ? "form-dialog-panel is-lg" : "form-dialog-panel"}
      >
        <header className="form-dialog-head">
          <div>
            {eyebrow ? <p className="eyebrow">{eyebrow}</p> : null}
            <h2 id={titleId} className="form-dialog-title">
              {title}
            </h2>
          </div>
          <button type="button" className="form-dialog-close" onClick={onClose} aria-label={closeLabel}>
            <IconClose aria-hidden />
          </button>
        </header>
        <form className="form-dialog-form" onSubmit={onSubmit}>
          <div className="form-dialog-body">{children}</div>
          {error ? (
            <p className="error" role="alert">
              {error}
            </p>
          ) : null}
          <div className="form-dialog-actions">
            <button className="btn btn-primary" type="submit" disabled={busy}>
              {submitLabel}
            </button>
            <button className="btn btn-ghost" type="button" onClick={onClose} disabled={busy}>
              {cancelLabel}
            </button>
          </div>
        </form>
      </div>
    </div>,
    document.body,
  );
}
