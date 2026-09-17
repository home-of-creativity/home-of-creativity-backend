import { useEffect, useMemo, useState } from "react";
import { useNavigate, useParams } from "react-router-dom";
import { toast } from "sonner";
import { z } from "zod";
import { FormPage } from "../../components/FormPage";
import { FormSection } from "../../components/FormSection";
import { LoadingLottie } from "../../components/LoadingLottie";
import { useZodForm } from "../../lib/useZodForm";
import { api, type PricingCategory } from "../../api";
import { copy, type Locale } from "../../i18n";
import { categoryLabel } from "./utils";

const slugPattern = /^[a-z0-9_-]+$/;

export function PricingSubcategoryForm({ locale, t }: { locale: Locale; t: (c: { ar: string; en: string }) => string }) {
  const navigate = useNavigate();
  const params = useParams();
  const editingId = params.id ? Number(params.id) : null;

  const [categories, setCategories] = useState<PricingCategory[]>([]);
  const [loading, setLoading] = useState(Boolean(editingId));
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState("");

  const schema = useMemo(
    () =>
      z.object({
        category_id: z.string().trim().min(1, t(copy.fieldRequired)),
        slug: z.string().trim().min(1, t(copy.fieldRequired)).regex(slugPattern, t(copy.invalidUrl)),
        name_en: z.string().trim().min(1, t(copy.fieldRequired)),
        name_ar: z.string().trim().min(1, t(copy.fieldRequired)),
        lead_en: z.string().trim(),
        lead_ar: z.string().trim(),
        one_time: z.boolean(),
        lead_in_box: z.boolean(),
        lead_note_en: z.string().trim(),
        lead_note_ar: z.string().trim(),
        is_published: z.boolean(),
      }),
    [t],
  );

  const {
    register,
    handleSubmit,
    reset,
    setValue,
    watch,
    formState: { errors },
  } = useZodForm(schema, {
    defaultValues: {
      category_id: "",
      slug: "",
      name_en: "",
      name_ar: "",
      lead_en: "",
      lead_ar: "",
      one_time: false,
      lead_in_box: false,
      lead_note_en: "",
      lead_note_ar: "",
      is_published: true,
    },
  });

  useEffect(() => {
    api
      .pricingCategories()
      .then((res) => {
        setCategories(res.data);
        if (!editingId && !watch("category_id")) setValue("category_id", String(res.data[0]?.id ?? ""));
      })
      .catch(() => setCategories([]));
  }, [editingId, setValue, watch]);

  useEffect(() => {
    if (!editingId) {
      setLoading(false);
      return;
    }
    setLoading(true);
    api
      .pricingSubcategories()
      .then((res) => res.data.find((row) => row.id === editingId))
      .then((item) => {
        if (!item) {
          setError(t(copy.savePortfolioFailed));
          return;
        }
        reset({
          category_id: String(item.category_id),
          slug: item.slug,
          name_en: item.name_en,
          name_ar: item.name_ar,
          lead_en: item.lead_en ?? "",
          lead_ar: item.lead_ar ?? "",
          one_time: item.one_time,
          lead_in_box: item.lead_in_box,
          lead_note_en: item.lead_note_en ?? "",
          lead_note_ar: item.lead_note_ar ?? "",
          is_published: item.is_published,
        });
      })
      .catch(() => setError(t(copy.savePortfolioFailed)))
      .finally(() => setLoading(false));
  }, [editingId, reset, t]);

  async function onValid(values: {
    category_id: string;
    slug: string;
    name_en: string;
    name_ar: string;
    lead_en: string;
    lead_ar: string;
    one_time: boolean;
    lead_in_box: boolean;
    lead_note_en: string;
    lead_note_ar: string;
    is_published: boolean;
  }) {
    setError("");
    setBusy(true);
    const payload = {
      category_id: Number(values.category_id),
      slug: values.slug.trim(),
      name_en: values.name_en.trim(),
      name_ar: values.name_ar.trim(),
      lead_en: values.lead_en.trim() || null,
      lead_ar: values.lead_ar.trim() || null,
      one_time: values.one_time,
      lead_in_box: values.lead_in_box,
      lead_note_en: values.lead_note_en.trim() || null,
      lead_note_ar: values.lead_note_ar.trim() || null,
      is_published: values.is_published,
    };
    try {
      if (editingId) await api.updatePricingSubcategory(editingId, payload);
      else await api.createPricingSubcategory(payload);
      toast.success(t(copy.saveSuccess));
      navigate("/pricing?tab=subcategories");
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
      eyebrow={t(copy.pricingTitle)}
      title={editingId ? t(copy.edit) : t(copy.addPricingSubcategory)}
      backTo="/pricing?tab=subcategories"
      backLabel={t(copy.pricingTabSubcategories)}
      onSubmit={handleSubmit(onValid)}
      submitLabel={t(copy.savePricingSubcategory)}
      cancelLabel={t(copy.cancel)}
      error={error}
      busy={busy}
    >
      <FormSection title={t(copy.pricingTabSubcategories)}>
        <label className="field-label">
          {t(copy.category)}
          <select className={errors.category_id ? "field has-error" : "field"} {...register("category_id")}>
            <option value="">{t(copy.chooseCategory)}</option>
            {categories.map((cat) => (
              <option key={cat.id} value={cat.id}>
                {categoryLabel(cat, locale)}
              </option>
            ))}
          </select>
          {errors.category_id ? <p className="field-error">{errors.category_id.message}</p> : null}
        </label>
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
        <label className="field-label">
          {t(copy.leadEn)}
          <textarea className="field" rows={2} {...register("lead_en")} />
        </label>
        <label className="field-label">
          {t(copy.leadAr)}
          <textarea className="field" rows={2} {...register("lead_ar")} />
        </label>
      </FormSection>
      <FormSection title={t(copy.pricingBillingType)}>
        <label className="checkbox-row">
          <input type="checkbox" {...register("one_time")} />
          {t(copy.pricingOneTime)}
        </label>
        <label className="checkbox-row">
          <input type="checkbox" {...register("lead_in_box")} />
          {t(copy.pricingLeadInBox)}
        </label>
        <label className="field-label">
          {t(copy.pricingLeadNoteEn)}
          <input className="field" {...register("lead_note_en")} />
        </label>
        <label className="field-label">
          {t(copy.pricingLeadNoteAr)}
          <input className="field" {...register("lead_note_ar")} />
        </label>
        <label className="checkbox-row">
          <input type="checkbox" {...register("is_published")} />
          {t(copy.published)}
        </label>
      </FormSection>
    </FormPage>
  );
}
