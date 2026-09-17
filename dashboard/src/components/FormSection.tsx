import type { ReactNode } from "react";

export function FormSection({
  title,
  description,
  children,
  span,
}: {
  title: string;
  description?: string;
  children: ReactNode;
  span?: boolean;
}) {
  return (
    <section className={span ? "form-section form-section-span" : "form-section"}>
      <div className="form-section-head">
        <h2 className="form-section-title">{title}</h2>
        {description ? <p className="form-section-desc">{description}</p> : null}
      </div>
      <div className="form-section-body">{children}</div>
    </section>
  );
}
