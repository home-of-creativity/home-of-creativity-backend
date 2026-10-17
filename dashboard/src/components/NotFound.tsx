import { Link } from "react-router-dom";
import { copy, type Copy } from "../i18n";

export function NotFound({ t }: { t: (c: Copy) => string }) {
  return (
    <section className="empty-state not-found" aria-labelledby="not-found-title">
      <h1 id="not-found-title" className="form-title">{t(copy.notFoundTitle)}</h1>
      <p className="muted">{t(copy.notFoundLede)}</p>
      <Link className="btn btn-primary" to="/">{t(copy.notFoundBack)}</Link>
    </section>
  );
}
