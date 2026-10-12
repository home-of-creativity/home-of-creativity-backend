import { ArrowLeft } from "lucide-react";
import type { FormEvent, ReactNode } from "react";
import { Link, useNavigate } from "react-router-dom";
import { useCan } from "../lib/useCan";

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
  ability,
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
  /** The operation this form saves with; any one of a list is enough. Without it the form is read only. */
  ability?: string | string[];
}) {
  const navigate = useNavigate();
  const can = useCan();
  const allowed = ability === undefined || (Array.isArray(ability) ? ability : [ability]).some((item) => can(item));

  return (
    <form className={wide ? "form-page is-wide" : "form-page"} onSubmit={(event) => (allowed ? onSubmit(event) : event.preventDefault())}>
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
          {allowed ? extraActions : null}
          <button type="button" className="btn btn-ghost" disabled={busy} onClick={() => navigate(backTo)}>
            {cancelLabel}
          </button>
          {allowed ? (
            <button type="submit" className="btn btn-primary" disabled={busy}>
              {submitLabel}
            </button>
          ) : null}
        </div>
      </header>
      {!allowed ? <p className="notice" role="status">عرض فقط: لا تملك صلاحية الحفظ هنا. / View only: you cannot save here.</p> : null}
      {notice ? <p className="notice">{notice}</p> : null}
      {error ? (
        <p className="error" role="alert">
          {error}
        </p>
      ) : null}
      <fieldset className="form-page-body form-page-fieldset" disabled={!allowed}>{children}</fieldset>
    </form>
  );
}
