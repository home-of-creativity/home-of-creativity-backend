import { useEffect, useMemo, useState } from "react";
import { Controller } from "react-hook-form";
import { useNavigate, useParams } from "react-router-dom";
import { toast } from "sonner";
import { z } from "zod";
import { FormPage } from "../components/FormPage";
import { FormSection } from "../components/FormSection";
import { HtmlEditorField } from "../components/HtmlEditorField";
import { LoadingLottie } from "../components/LoadingLottie";
import { useZodForm } from "../lib/useZodForm";
import { api, type ArticlePayload } from "../api";
import { copy, type Locale } from "../i18n";

export function ArticleForm({ locale, t }: { locale: Locale; t: (c: { ar: string; en: string }) => string }) {
  const navigate = useNavigate();
  const params = useParams();
  const editingId = params.id ? Number(params.id) : null;

  const [loading, setLoading] = useState(Boolean(editingId));
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState("");

  const schema = useMemo(
    () =>
      z.object({
        slug: z.string().trim(),
        title_en: z.string().trim().min(1, t(copy.fieldRequired)),
        title_ar: z.string().trim().min(1, t(copy.fieldRequired)),
        excerpt_en: z.string().trim(),
        excerpt_ar: z.string().trim(),
        body_en: z.string().trim().min(1, t(copy.fieldRequired)),
        body_ar: z.string().trim().min(1, t(copy.fieldRequired)),
        published_at: z.string().trim(),
        is_published: z.boolean(),
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
      slug: "",
      title_en: "",
      title_ar: "",
      excerpt_en: "",
      excerpt_ar: "",
      body_en: "",
      body_ar: "",
      published_at: "",
      is_published: true,
    },
  });

  useEffect(() => {
    if (!editingId) {
      setLoading(false);
      return;
    }
    setLoading(true);
    api
      .article(editingId)
      .then((res) => {
        const item = res.data;
        reset({
          slug: item.slug,
          title_en: item.title_en,
          title_ar: item.title_ar,
          excerpt_en: item.excerpt_en ?? "",
          excerpt_ar: item.excerpt_ar ?? "",
          body_en: item.body_en,
          body_ar: item.body_ar,
          published_at: item.published_at ? item.published_at.slice(0, 10) : "",
          is_published: item.is_published,
        });
      })
      .catch(() => setError(t(copy.savePortfolioFailed)))
      .finally(() => setLoading(false));
  }, [editingId, reset, t]);

  const previewBody = watch(locale === "ar" ? "body_ar" : "body_en");

  async function onValid(values: z.infer<typeof schema>) {
    setError("");
    setBusy(true);
    const payload: ArticlePayload = {
      slug: values.slug.trim() || undefined,
      title_en: values.title_en.trim(),
      title_ar: values.title_ar.trim(),
      excerpt_en: values.excerpt_en.trim() || null,
      excerpt_ar: values.excerpt_ar.trim() || null,
      body_en: values.body_en.trim(),
      body_ar: values.body_ar.trim(),
      published_at: values.published_at.trim() || null,
      is_published: values.is_published,
    };
    try {
      if (editingId) await api.updateArticle(editingId, payload);
      else await api.createArticle(payload);
      toast.success(t(copy.saveSuccess));
      navigate("/articles");
    } catch (err) {
      const message = err instanceof Error ? err.message : t(copy.savePortfolioFailed);
      setError(message);
      toast.error(message);
      setBusy(false);
    }
  }

  if (loading) return <LoadingLottie variant="page" label={t(copy.loading)} />;

  return (
    <FormPage
      eyebrow={t(copy.articlesTitle)}
      title={editingId ? t(copy.edit) : t(copy.addArticle)}
      backTo="/articles"
      backLabel={t(copy.articlesTitle)}
      onSubmit={handleSubmit(onValid)}
      submitLabel={t(copy.saveArticle)}
      cancelLabel={t(copy.cancel)}
      error={error}
      busy={busy}
      wide
    >
      <FormSection title={t(copy.articleContent)}>
        <label className="field-label">
          {t(copy.titleEn)}
          <input className={errors.title_en ? "field has-error" : "field"} {...register("title_en")} />
          {errors.title_en ? <p className="field-error">{errors.title_en.message}</p> : null}
        </label>
        <label className="field-label">
          {t(copy.titleAr)}
          <input className={errors.title_ar ? "field has-error" : "field"} {...register("title_ar")} />
          {errors.title_ar ? <p className="field-error">{errors.title_ar.message}</p> : null}
        </label>
        <label className="field-label">
          {t(copy.slug)}
          <input className="field" placeholder="advantages-of-social-media" {...register("slug")} />
          <small className="muted">{t(copy.articleSlugHint)}</small>
        </label>
        <label className="field-label">
          {t(copy.articleExcerptEn)}
          <textarea className="field" rows={2} {...register("excerpt_en")} />
        </label>
        <label className="field-label">
          {t(copy.articleExcerptAr)}
          <textarea className="field" rows={2} {...register("excerpt_ar")} />
        </label>
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
              placeholder="<h2>Advantages of social media</h2>"
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
              placeholder="<h2>فوائد وسائل التواصل الاجتماعي</h2>"
              hint={t(copy.articleBodyHint)}
              error={errors.body_ar?.message}
              toolbarLabel={t(copy.htmlEditorToolbar)}
            />
          )}
        />
      </FormSection>

      <FormSection title={t(copy.articlePreview)}>
        <div
          className="article-preview"
          dir={locale === "ar" ? "rtl" : "ltr"}
          dangerouslySetInnerHTML={{ __html: previewBody || "" }}
        />
      </FormSection>

      <FormSection title={t(copy.published)}>
        <label className="field-label">
          {t(copy.articlePublishedAt)}
          <input type="date" className="field" {...register("published_at")} />
        </label>
        <label className="checkbox-row">
          <input type="checkbox" {...register("is_published")} />
          {t(copy.published)}
        </label>
      </FormSection>
    </FormPage>
  );
}

export default ArticleForm;
