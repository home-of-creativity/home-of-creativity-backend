import { useEffect, useMemo, useState } from "react";
import { Controller } from "react-hook-form";
import { toast } from "sonner";
import { z } from "zod";
import { FormPage } from "../components/FormPage";
import { FormSection } from "../components/FormSection";
import { HtmlEditorField } from "../components/HtmlEditorField";
import { LoadingLottie } from "../components/LoadingLottie";
import { useZodForm } from "../lib/useZodForm";
import { emptyLegalPage, expandLegalSections, flattenLegalBody } from "../lib/legalForm";
import { api } from "../api";
import { copy, type Locale } from "../i18n";

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
  const pageTitle = t(isTerms ? copy.legalTermsTitle : copy.legalPrivacyTitle);
  const [loading, setLoading] = useState(true);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState("");

  const schema = useMemo(
    () =>
      z.object({
        title_en: z.string().trim().min(1, t(copy.fieldRequired)),
        title_ar: z.string().trim().min(1, t(copy.fieldRequired)),
        body_en: z.string().trim().min(1, t(copy.fieldRequired)),
        body_ar: z.string().trim().min(1, t(copy.fieldRequired)),
      }),
    [t],
  );

  const {
    register,
    handleSubmit,
    reset,
    watch,
    control,
    formState: { errors },
  } = useZodForm(schema, {
    defaultValues: {
      title_en: emptyLegalPage(slug).title_en,
      title_ar: emptyLegalPage(slug).title_ar,
      body_en: "",
      body_ar: "",
    },
  });

  useEffect(() => {
    let active = true;
    setLoading(true);
    setError("");
    api
      .legalPage(slug)
      .then((res) => {
        if (!active) return;
        const page = res.data;
        reset({
          title_en: page.title_en,
          title_ar: page.title_ar,
          body_en: flattenLegalBody(page.sections, "en"),
          body_ar: flattenLegalBody(page.sections, "ar"),
        });
      })
      .catch(() => {
        if (active) setError(t(copy.savePortfolioFailed));
      })
      .finally(() => {
        if (active) setLoading(false);
      });
    return () => {
      active = false;
    };
  }, [reset, slug, t]);

  const previewBody = watch(locale === "ar" ? "body_ar" : "body_en");

  async function onValid(values: z.infer<typeof schema>) {
    setError("");
    setBusy(true);
    try {
      await api.updateLegalPage(slug, {
        title_en: values.title_en.trim(),
        title_ar: values.title_ar.trim(),
        sections: expandLegalSections(values.body_en, values.body_ar),
      });
      toast.success(t(copy.saveSuccess));
    } catch (err) {
      const message = err instanceof Error ? err.message : t(copy.savePortfolioFailed);
      setError(message);
      toast.error(message);
    } finally {
      setBusy(false);
    }
  }

  if (loading) return <LoadingLottie variant="page" label={t(copy.loading)} />;

  return (
    <FormPage
      eyebrow={t(copy.brandMark)}
      title={pageTitle}
      backTo="/"
      backLabel={t(copy.overview)}
      onSubmit={handleSubmit(onValid)}
      submitLabel={t(copy.legalSave)}
      cancelLabel={t(copy.cancel)}
      error={error}
      busy={busy}
      wide
    >
      <FormSection title={t(copy.articleContent)}>
        <label className="field-label">
          {t(copy.titleEn)}
          <input className={errors.title_en ? "field has-error" : "field"} dir="ltr" {...register("title_en")} />
          {errors.title_en ? <p className="field-error">{errors.title_en.message}</p> : null}
        </label>
        <label className="field-label">
          {t(copy.titleAr)}
          <input className={errors.title_ar ? "field has-error" : "field"} dir="rtl" {...register("title_ar")} />
          {errors.title_ar ? <p className="field-error">{errors.title_ar.message}</p> : null}
        </label>
        <p className="form-section-desc field-span">{t(copy.legalTagsHint)}</p>
      </FormSection>

      <FormSection title={t(copy.articleBodyEn)} span>
        <Controller
          name="body_en"
          control={control}
          render={({ field }) => (
            <HtmlEditorField
              label={t(copy.articleBodyEn)}
              value={field.value}
              onChange={field.onChange}
              dir="ltr"
              placeholder="<h2>Privacy policy</h2>"
              hint={t(copy.articleBodyHint)}
              error={errors.body_en?.message}
              toolbarLabel={t(copy.htmlEditorToolbar)}
            />
          )}
        />
        <Controller
          name="body_ar"
          control={control}
          render={({ field }) => (
            <HtmlEditorField
              label={t(copy.articleBodyAr)}
              value={field.value}
              onChange={field.onChange}
              dir="rtl"
              placeholder="<h2>سياسة الخصوصية</h2>"
              hint={t(copy.articleBodyHint)}
              error={errors.body_ar?.message}
              toolbarLabel={t(copy.htmlEditorToolbar)}
            />
          )}
        />
      </FormSection>

      <FormSection title={t(copy.articlePreview)}>
        <div
          className="article-preview field-span"
          dir={locale === "ar" ? "rtl" : "ltr"}
          dangerouslySetInnerHTML={{ __html: previewBody || "" }}
        />
      </FormSection>
    </FormPage>
  );
}
