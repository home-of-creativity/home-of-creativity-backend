import { ArrowLeft } from "lucide-react";
import type { FormEvent, ReactNode } from "react";
import { Link, useNavigate } from "react-router-dom";

export function FormPage({
  eyebrow,
  title,
  backTo,
  backLabel,
  onSubmit,
  submitLabel,
  cancelLabel,
  error,
  notice,
  busy,
  children,
  wide,
  extraActions,
}: {
  eyebrow?: string;
  title: string;
  backTo: string;
  backLabel: string;
  onSubmit: (event: FormEvent<HTMLFormElement>) => void;
  submitLabel: string;
  cancelLabel: string;
  error?: string;
  notice?: string;
  busy?: boolean;
  children: ReactNode;
  wide?: boolean;
  extraActions?: ReactNode;
}) {
  const navigate = useNavigate();

  return (
    <form className={wide ? "form-page is-wide" : "form-page"} onSubmit={onSubmit}>
      <header className="form-page-head">
        <div>
          <Link className="back-link" to={backTo}>
            <span aria-hidden="true">
              <ArrowLeft size={16} />
            </span>{" "}
            {backLabel}
          </Link>
          {eyebrow ? <p className="eyebrow">{eyebrow}</p> : null}
          <h1 className="page-title">{title}</h1>
        </div>
        <div className="form-page-actions">
          {extraActions}
          <button type="button" className="btn btn-ghost" disabled={busy} onClick={() => navigate(backTo)}>
            {cancelLabel}
          </button>
          <button type="submit" className="btn btn-primary" disabled={busy}>
            {submitLabel}
          </button>
        </div>
      </header>
      {notice ? <p className="notice">{notice}</p> : null}
      {error ? (
        <p className="error" role="alert">
          {error}
        </p>
      ) : null}
      <div className="form-page-body">{children}</div>
    </form>
  );
}
