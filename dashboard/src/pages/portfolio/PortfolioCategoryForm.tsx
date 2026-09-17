import { useEffect, useMemo, useState } from "react";
import { useNavigate, useParams } from "react-router-dom";
import { toast } from "sonner";
import { z } from "zod";
import { FormPage } from "../../components/FormPage";
import { FormSection } from "../../components/FormSection";
import { LoadingLottie } from "../../components/LoadingLottie";
import { useZodForm } from "../../lib/useZodForm";
import { api } from "../../api";
import { copy, type Locale } from "../../i18n";

const slugPattern = /^[a-z0-9_-]+$/;

export function PortfolioCategoryForm({ t }: { locale: Locale; t: (c: { ar: string; en: string }) => string }) {
  const navigate = useNavigate();
  const params = useParams();
  const editingId = params.id ? Number(params.id) : null;

  const [loading, setLoading] = useState(Boolean(editingId));
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState("");

  const schema = useMemo(
    () =>
      z.object({
        slug: z.string().trim().min(1, t(copy.fieldRequired)).regex(slugPattern, t(copy.invalidUrl)),
        name_en: z.string().trim().min(1, t(copy.fieldRequired)),
        name_ar: z.string().trim().min(1, t(copy.fieldRequired)),
        is_published: z.boolean(),
      }),
    [t],
  );

  const {
    register,
    handleSubmit,
    reset,
    formState: { errors },
  } = useZodForm(schema, {
    defaultValues: { slug: "", name_en: "", name_ar: "", is_published: true },
  });

  useEffect(() => {
    if (!editingId) {
      setLoading(false);
      return;
    }
    setLoading(true);
    api
      .portfolioCategories()
      .then((res) => res.data.find((row) => row.id === editingId))
      .then((item) => {
        if (!item) {
          setError(t(copy.savePortfolioFailed));
          return;
        }
        reset({ slug: item.slug, name_en: item.name_en, name_ar: item.name_ar, is_published: item.is_published });
      })
      .catch(() => setError(t(copy.savePortfolioFailed)))
      .finally(() => setLoading(false));
  }, [editingId, reset, t]);

  async function onValid(values: { slug: string; name_en: string; name_ar: string; is_published: boolean }) {
    setError("");
    setBusy(true);
    const payload = { slug: values.slug.trim(), name_en: values.name_en.trim(), name_ar: values.name_ar.trim(), is_published: values.is_published };
    try {
      if (editingId) await api.updatePortfolioCategory(editingId, payload);
      else await api.createPortfolioCategory(payload);
      toast.success(t(copy.saveSuccess));
      navigate("/categories");
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
      eyebrow={t(copy.portfolioTabCategories)}
      title={editingId ? t(copy.edit) : t(copy.addPortfolioCategory)}
      backTo="/categories"
      backLabel={t(copy.portfolioTabCategories)}
      onSubmit={handleSubmit(onValid)}
      submitLabel={t(copy.savePortfolioCategory)}
      cancelLabel={t(copy.cancel)}
      error={error}
      busy={busy}
    >
      <FormSection title={t(copy.portfolioTabCategories)}>
        <label className="field-label">
          {t(copy.slug)}
          <input className={errors.slug ? "field has-error" : "field"} {...register("slug")} />
          {errors.slug ? <p className="field-error">{errors.slug.message}</p> : null}
        </label>
        <label className="field-label">
          {t(copy.titleEn)}
          <input className={errors.name_en ? "field has-error" : "field"} {...register("name_en")} />
          {errors.name_en ? <p className="field-error">{errors.name_en.message}</p> : null}
        </label>
        <label className="field-label">
          {t(copy.titleAr)}
          <input className={errors.name_ar ? "field has-error" : "field"} {...register("name_ar")} />
          {errors.name_ar ? <p className="field-error">{errors.name_ar.message}</p> : null}
        </label>
        <label className="checkbox-row">
          <input type="checkbox" {...register("is_published")} />
          {t(copy.published)}
        </label>
      </FormSection>
    </FormPage>
  );
}
