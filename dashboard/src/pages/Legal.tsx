import { useEffect, useRef, useState, type FormEvent } from "react";
import { toast } from "sonner";
import { FormSection } from "../components/FormSection";
import { LoadingLottie } from "../components/LoadingLottie";
import { PageHeader } from "../components/PageHeader";
import { api, type LegalPage, type LegalSection } from "../api";
import { copy, type Locale } from "../i18n";

const HTML_TAGS: Array<{ label: string; open: string; close: string }> = [
  { label: "section", open: "<section>", close: "</section>" },
  { label: "h2", open: "<h2>", close: "</h2>" },
  { label: "h3", open: "<h3>", close: "</h3>" },
  { label: "p", open: "<p>", close: "</p>" },
  { label: "ul", open: "<ul>\n", close: "\n</ul>" },
  { label: "li", open: "<li>", close: "</li>" },
  { label: "a", open: '<a href="https://">', close: "</a>" },
  { label: "strong", open: "<strong>", close: "</strong>" },
  { label: "em", open: "<em>", close: "</em>" },
  { label: "br", open: "<br>", close: "" },
];

function emptySection(): LegalSection {
  return {
    heading_ar: "",
    heading_en: "",
    html_ar: "<p></p>",
    html_en: "<p></p>",
  };
}

function insertAroundSelection(el: HTMLTextAreaElement, open: string, close: string) {
  const start = el.selectionStart;
  const end = el.selectionEnd;
  const selected = el.value.slice(start, end) || (close ? "…" : "");
  const next = `${el.value.slice(0, start)}${open}${selected}${close}${el.value.slice(end)}`;
  const caret = start + open.length + selected.length + close.length;
  return { next, caret };
}

function HtmlField({
  label,
  value,
  onChange,
  dir,
  insertLabel,
  previewLabel,
}: {
  label: string;
  value: string;
  onChange: (next: string) => void;
  dir: "rtl" | "ltr";
  insertLabel: string;
  previewLabel: string;
}) {
  const ref = useRef<HTMLTextAreaElement>(null);

  function insert(open: string, close: string) {
    const el = ref.current;
    if (!el) {
      onChange(`${open}${close ? "…" : ""}${close}`);
      return;
    }
    const { next, caret } = insertAroundSelection(el, open, close);
    onChange(next);
    requestAnimationFrame(() => {
      el.focus();
      el.setSelectionRange(caret, caret);
    });
  }

  return (
    <label className="field-label field-span legal-html-field">
      <span>{label}</span>
      <div className="legal-tag-bar" role="toolbar" aria-label={insertLabel}>
        {HTML_TAGS.map((tag) => (
          <button key={tag.label} type="button" className="legal-tag" onClick={() => insert(tag.open, tag.close)}>
            {tag.label}
          </button>
        ))}
      </div>
      <textarea
        ref={ref}
        className="field legal-html-input"
        dir={dir}
        rows={10}
        spellCheck={false}
        value={value}
        onChange={(event) => onChange(event.target.value)}
      />
      <span className="legal-preview-label">{previewLabel}</span>
      <div className="legal-html-preview" dir={dir} dangerouslySetInnerHTML={{ __html: value }} />
    </label>
  );
}

export function Legal({
  locale,
  t,
  slug,
}: {
  locale: Locale;
  t: (c: { ar: string; en: string }) => string;
  slug: "privacy" | "terms";
}) {
  const isTerms = slug === "terms";
  const title = t(isTerms ? copy.legalTermsTitle : copy.legalPrivacyTitle);
  const lede = t(isTerms ? copy.legalTermsLede : copy.legalPrivacyLede);
  const [page, setPage] = useState<LegalPage | null>(null);
  const [loading, setLoading] = useState(true);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState("");

  function patch(next: Partial<LegalPage>) {
    setPage((prev) => (prev ? { ...prev, ...next } : prev));
  }

  function patchSection(index: number, next: Partial<LegalSection>) {
    setPage((prev) => {
      if (!prev) return prev;
      return { ...prev, sections: prev.sections.map((section, i) => (i === index ? { ...section, ...next } : section)) };
    });
  }

  function moveSection(index: number, direction: -1 | 1) {
    setPage((prev) => {
      if (!prev) return prev;
      const target = index + direction;
      if (target < 0 || target >= prev.sections.length) return prev;
      const sections = [...prev.sections];
      const [row] = sections.splice(index, 1);
      sections.splice(target, 0, row);
      return { ...prev, sections };
    });
  }

  function removeSection(index: number) {
    setPage((prev) => {
      if (!prev || prev.sections.length < 2) return prev;
      return { ...prev, sections: prev.sections.filter((_, i) => i !== index) };
    });
  }

  useEffect(() => {
    let active = true;
    setLoading(true);
    setError("");
    api
      .legalPage(slug)
      .then((res) => {
        if (active) setPage(res.data);
      })
      .catch(() => {
        if (active) setPage(null);
      })
      .finally(() => {
        if (active) setLoading(false);
      });
    return () => {
      active = false;
    };
  }, [slug]);

  async function onSubmit(event: FormEvent) {
    event.preventDefault();
    if (!page) return;
    setBusy(true);
    setError("");
    try {
      const res = await api.updateLegalPage(slug, {
        title_ar: page.title_ar,
        title_en: page.title_en,
        sections: page.sections.map((section) => ({
          heading_ar: section.heading_ar,
          heading_en: section.heading_en,
          html_ar: section.html_ar,
          html_en: section.html_en,
        })),
      });
      setPage(res.data);
      toast.success(t(copy.saveSuccess));
    } catch (err) {
      const message = err instanceof Error ? err.message : t(copy.savePortfolioFailed);
      setError(message);
      toast.error(message);
    } finally {
      setBusy(false);
    }
  }

  return (
    <>
      <PageHeader eyebrow={t(copy.brandMark)} title={title} lede={lede} />

      {loading ? <LoadingLottie variant="page" label={t(copy.loading)} /> : null}
      {!loading && !page ? <p className="notice">{t(copy.legalEmpty)}</p> : null}

      {page ? (
        <form className="form-page is-wide legal-page" onSubmit={onSubmit}>
          {error ? (
            <p className="error" role="alert">
              {error}
            </p>
          ) : null}
          <div className="form-page-actions legal-page-actions">
            <button type="submit" className="btn btn-primary" disabled={busy}>
              {t(copy.legalSave)}
            </button>
          </div>

          <FormSection title={locale === "ar" ? page.title_ar : page.title_en} span>
            <label className="field-label">
              {t(copy.legalTitleAr)}
              <input className="field" dir="rtl" value={page.title_ar} onChange={(event) => patch({ title_ar: event.target.value })} />
            </label>
            <label className="field-label">
              {t(copy.legalTitleEn)}
              <input className="field" dir="ltr" value={page.title_en} onChange={(event) => patch({ title_en: event.target.value })} />
            </label>
            <p className="form-section-desc field-span">{t(copy.legalTagsHint)}</p>
          </FormSection>

          {page.sections.map((section, index) => (
            <FormSection
              key={`${section.heading_en}-${index}`}
              title={`${index + 1}. ${locale === "ar" ? section.heading_ar || section.heading_en : section.heading_en || section.heading_ar}`}
              span
            >
              <div className="legal-section-toolbar field-span">
                <button type="button" className="btn btn-ghost" disabled={index === 0} onClick={() => moveSection(index, -1)}>
                  {t(copy.moveUp)}
                </button>
                <button
                  type="button"
                  className="btn btn-ghost"
                  disabled={index === page.sections.length - 1}
                  onClick={() => moveSection(index, 1)}
                >
                  {t(copy.moveDown)}
                </button>
                <button type="button" className="btn btn-ghost" disabled={page.sections.length < 2} onClick={() => removeSection(index)}>
                  {t(copy.delete)}
                </button>
              </div>
              <label className="field-label">
                {t(copy.legalHeadingAr)}
                <input className="field" dir="rtl" value={section.heading_ar} onChange={(event) => patchSection(index, { heading_ar: event.target.value })} />
              </label>
              <label className="field-label">
                {t(copy.legalHeadingEn)}
                <input className="field" dir="ltr" value={section.heading_en} onChange={(event) => patchSection(index, { heading_en: event.target.value })} />
              </label>
              <HtmlField
                label={t(copy.legalHtmlAr)}
                value={section.html_ar}
                dir="rtl"
                insertLabel={t(copy.legalInsertTag)}
                previewLabel={t(copy.legalPreview)}
                onChange={(html_ar) => patchSection(index, { html_ar })}
              />
              <HtmlField
                label={t(copy.legalHtmlEn)}
                value={section.html_en}
                dir="ltr"
                insertLabel={t(copy.legalInsertTag)}
                previewLabel={t(copy.legalPreview)}
                onChange={(html_en) => patchSection(index, { html_en })}
              />
            </FormSection>
          ))}

          <div className="legal-page-actions">
            <button type="button" className="btn btn-ghost" onClick={() => patch({ sections: [...page.sections, emptySection()] })}>
              {t(copy.legalAddSection)}
            </button>
            <button type="submit" className="btn btn-primary" disabled={busy}>
              {t(copy.legalSave)}
            </button>
          </div>
        </form>
      ) : null}
    </>
  );
}
