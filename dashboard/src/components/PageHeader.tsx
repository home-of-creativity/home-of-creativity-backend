import { ChevronRight } from "lucide-react";
import type { ReactNode } from "react";
import { Link } from "react-router-dom";

export type Crumb = { label: string; to?: string };

export function PageHeader({
  eyebrow,
  title,
  lede,
  breadcrumbs,
  actions,
}: {
  eyebrow?: string;
  title: string;
  lede?: string;
  breadcrumbs?: Crumb[];
  actions?: ReactNode;
}) {
  return (
    <header className="page-head">
      <div>
        {breadcrumbs && breadcrumbs.length > 0 ? (
          <nav className="breadcrumb" aria-label={title}>
            {breadcrumbs.map((crumb, index) => (
              <span className="breadcrumb-item" key={`${crumb.label}-${index}`}>
                {crumb.to ? <Link to={crumb.to}>{crumb.label}</Link> : <span>{crumb.label}</span>}
                {index < breadcrumbs.length - 1 ? (
                  <span className="breadcrumb-sep" aria-hidden="true">
                    <ChevronRight size={14} />
                  </span>
                ) : null}
              </span>
            ))}
          </nav>
        ) : eyebrow ? (
          <p className="eyebrow">{eyebrow}</p>
        ) : null}
        <h1 className="page-title">{title}</h1>
        {lede ? <p className="page-lede">{lede}</p> : null}
      </div>
      {actions ? <div className="toolbar">{actions}</div> : null}
    </header>
  );
}
