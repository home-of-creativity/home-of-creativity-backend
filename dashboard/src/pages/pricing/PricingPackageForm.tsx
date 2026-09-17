import { useEffect, useMemo, useState } from "react";
import { useNavigate, useParams, useSearchParams } from "react-router-dom";
import { toast } from "sonner";
import { z } from "zod";
import { FormPage } from "../../components/FormPage";
import { FormSection } from "../../components/FormSection";
import { LoadingLottie } from "../../components/LoadingLottie";
import { useZodForm } from "../../lib/useZodForm";
import { api, type PricingSubcategory } from "../../api";
import { copy, type Locale } from "../../i18n";
import { calcPrices, featuresToText, subcategoryLabel, textToFeatures } from "./utils";

const slugPattern = /^[a-z0-9_-]+$/;

export function PricingPackageForm({ locale, t }: { locale: Locale; t: (c: { ar: string; en: string }) => string }) {
  const navigate = useNavigate();
  const params = useParams();
  const [searchParams] = useSearchParams();
  const editingId = params.id ? Number(params.id) : null;

  const [subcategories, setSubcategories] = useState<PricingSubcategory[]>([]);
  const [loading, setLoading] = useState(Boolean(editingId));
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState("");

  const schema = useMemo(
    () =>
      z
        .object({
          subcategory_id: z.string().trim().min(1, t(copy.fieldRequired)),
          slug: z.string().trim().min(1, t(copy.fieldRequired)).regex(slugPattern, t(copy.invalidUrl)),
          name_en: z.string().trim().min(1, t(copy.fieldRequired)),
          name_ar: z.string().trim().min(1, t(copy.fieldRequired)),
          subtitle_en: z.string().trim().min(1, t(copy.fieldRequired)),
          subtitle_ar: z.string().trim().min(1, t(copy.fieldRequired)),
          price_usd: z.string().trim(),
          monthly: z.string().trim(),
          quarterly: z.string().trim(),
          semiannual: z.string().trim(),
          yearly: z.string().trim(),
          features_en: z.string(),
          features_ar: z.string(),
          has_reach: z.boolean(),
          ad_budget_usd: z.string().trim(),
          ad_credit_usd: z.string().trim(),
          reach_en: z.string(),
          reach_ar: z.string(),
          goal_en: z.string(),
          goal_ar: z.string(),
          featured: z.boolean(),
          badge_en: z.string(),
          badge_ar: z.string(),
          is_published: z.boolean(),
          allows_partial_payment: z.string(),
          one_time: z.boolean(),
        })
        .superRefine((values, ctx) => {
          const numeric = (value: string) => value !== "" && !Number.isNaN(Number(value));
          if (values.one_time) {
            if (!numeric(values.price_usd)) {
              ctx.addIssue({ code: "custom", path: ["price_usd"], message: t(copy.invalidNumber) });
            }
          } else {
            for (const key of ["monthly", "quarterly", "semiannual", "yearly"] as const) {
              if (!numeric(values[key])) {
                ctx.addIssue({ code: "custom", path: [key], message: t(copy.invalidNumber) });
              }
            }
          }
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
      subcategory_id: "",
      slug: "",
      name_en: "",
      name_ar: "",
      subtitle_en: "",
      subtitle_ar: "",
      price_usd: "",
      monthly: "",
      quarterly: "",
      semiannual: "",
      yearly: "",
      features_en: "",
      features_ar: "",
      has_reach: false,
      ad_budget_usd: "",
      ad_credit_usd: "",
      reach_en: "",
      reach_ar: "",
      goal_en: "",
      goal_ar: "",
      featured: false,
      badge_en: "",
      badge_ar: "",
      is_published: true,
      allows_partial_payment: "",
      one_time: false,
    },
  });

  const subcategoryId = watch("subcategory_id");
  const hasReach = watch("has_reach");
  const monthly = watch("monthly");
  const oneTime = watch("one_time");

  const selectedSubcategory = useMemo(
    () => subcategories.find((item) => String(item.id) === subcategoryId),
    [subcategories, subcategoryId],
  );

  useEffect(() => {
    setValue("one_time", selectedSubcategory?.one_time ?? false);
  }, [selectedSubcategory, setValue]);

  useEffect(() => {
    api
      .pricingSubcategories()
      .then((res) => {
        setSubcategories(res.data);
        if (!editingId && !watch("subcategory_id")) {
          const preselect = searchParams.get("subcategory_id") || String(res.data[0]?.id ?? "");
          setValue("subcategory_id", preselect);
        }
      })
      .catch(() => setSubcategories([]));
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [editingId]);

  useEffect(() => {
    if (!editingId) {
      setLoading(false);
      return;
    }
    setLoading(true);
    api
      .pricingPackages()
      .then((res) => res.data.find((row) => row.id === editingId))
      .then((item) => {
        if (!item) {
          setError(t(copy.savePortfolioFailed));
          return;
        }
        reset({
          subcategory_id: String(item.subcategory_id),
          slug: item.slug,
          name_en: item.name_en,
          name_ar: item.name_ar,
          subtitle_en: item.subtitle_en,
          subtitle_ar: item.subtitle_ar,
          price_usd: item.price_usd != null ? String(item.price_usd) : "",
          monthly: item.prices?.monthly != null ? String(item.prices.monthly) : "",
          quarterly: item.prices?.quarterly != null ? String(item.prices.quarterly) : "",
          semiannual: item.prices?.semiannual != null ? String(item.prices.semiannual) : "",
          yearly: item.prices?.yearly != null ? String(item.prices.yearly) : "",
          features_en: featuresToText(item.features, "en"),
          features_ar: featuresToText(item.features, "ar"),
          has_reach: Boolean(item.reach),
          ad_budget_usd: item.reach?.adBudgetUsd != null ? String(item.reach.adBudgetUsd) : "",
          ad_credit_usd: item.reach?.adCreditUsd != null ? String(item.reach.adCreditUsd) : "",
          reach_en: item.reach?.estimatedReach?.en ?? "",
          reach_ar: item.reach?.estimatedReach?.ar ?? "",
          goal_en: item.reach?.goal?.en ?? "",
          goal_ar: item.reach?.goal?.ar ?? "",
          featured: item.featured,
          badge_en: item.badge_en ?? "",
          badge_ar: item.badge_ar ?? "",
          is_published: item.is_published,
          allows_partial_payment:
            item.allows_partial_payment === true ? "true" : item.allows_partial_payment === false ? "false" : "",
          one_time: false,
        });
      })
      .catch(() => setError(t(copy.savePortfolioFailed)))
      .finally(() => setLoading(false));
  }, [editingId, reset, t]);

  function applyAutoPrices() {
    const monthlyNum = Number(monthly);
    if (!monthlyNum || Number.isNaN(monthlyNum)) return;
    const prices = calcPrices(monthlyNum);
    setValue("quarterly", String(prices.quarterly));
    setValue("semiannual", String(prices.semiannual));
    setValue("yearly", String(prices.yearly));
  }

  async function onValid(values: {
    subcategory_id: string;
    slug: string;
    name_en: string;
    name_ar: string;
    subtitle_en: string;
    subtitle_ar: string;
    price_usd: string;
    monthly: string;
    quarterly: string;
    semiannual: string;
    yearly: string;
    features_en: string;
    features_ar: string;
    has_reach: boolean;
    ad_budget_usd: string;
    ad_credit_usd: string;
    reach_en: string;
    reach_ar: string;
    goal_en: string;
    goal_ar: string;
    featured: boolean;
    badge_en: string;
    badge_ar: string;
    is_published: boolean;
    allows_partial_payment: string;
    one_time: boolean;
  }) {
    setError("");
    setBusy(true);

    const payload: Record<string, unknown> = {
      subcategory_id: Number(values.subcategory_id),
      slug: values.slug.trim(),
      name_en: values.name_en.trim(),
      name_ar: values.name_ar.trim(),
      subtitle_en: values.subtitle_en.trim(),
      subtitle_ar: values.subtitle_ar.trim(),
      features: textToFeatures(values.features_en, values.features_ar),
      featured: values.featured,
      badge_en: values.badge_en.trim() || null,
      badge_ar: values.badge_ar.trim() || null,
      is_published: values.is_published,
      allows_partial_payment: values.allows_partial_payment === "" ? null : values.allows_partial_payment === "true",
    };

    if (values.one_time) {
      payload.price_usd = Number(values.price_usd);
      payload.prices = null;
    } else {
      payload.price_usd = null;
      payload.prices = {
        monthly: Number(values.monthly),
        quarterly: Number(values.quarterly),
        semiannual: Number(values.semiannual),
        yearly: Number(values.yearly),
      };
    }

    if (values.has_reach) {
      payload.reach = {
        adBudgetUsd: Number(values.ad_budget_usd),
        adCreditUsd: Number(values.ad_credit_usd),
        estimatedReach: { en: values.reach_en.trim(), ar: values.reach_ar.trim() },
        goal: { en: values.goal_en.trim(), ar: values.goal_ar.trim() },
      };
    } else {
      payload.reach = null;
    }

    try {
      if (editingId) await api.updatePricingPackage(editingId, payload);
      else await api.createPricingPackage(payload);
      toast.success(t(copy.saveSuccess));
      navigate("/pricing?tab=packages");
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
      title={editingId ? t(copy.edit) : t(copy.addPricingPackage)}
      backTo="/pricing?tab=packages"
      backLabel={t(copy.pricingTabPackages)}
      onSubmit={handleSubmit(onValid)}
      submitLabel={t(copy.savePricingPackage)}
      cancelLabel={t(copy.cancel)}
      error={error}
      busy={busy}
      wide
    >
      <FormSection title={t(copy.pricingTabPackages)}>
        <label className="field-label">
          {t(copy.pricingSubcategory)}
          <select className={errors.subcategory_id ? "field has-error" : "field"} {...register("subcategory_id")}>
            <option value="">{t(copy.chooseSubcategory)}</option>
            {subcategories.map((sub) => (
              <option key={sub.id} value={sub.id}>
                {subcategoryLabel(sub, locale)}
              </option>
            ))}
          </select>
          {errors.subcategory_id ? <p className="field-error">{errors.subcategory_id.message}</p> : null}
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
          {t(copy.pricingSubtitleEn)}
          <input className={errors.subtitle_en ? "field has-error" : "field"} {...register("subtitle_en")} />
          {errors.subtitle_en ? <p className="field-error">{errors.subtitle_en.message}</p> : null}
        </label>
        <label className="field-label">
          {t(copy.pricingSubtitleAr)}
          <input className={errors.subtitle_ar ? "field has-error" : "field"} {...register("subtitle_ar")} />
          {errors.subtitle_ar ? <p className="field-error">{errors.subtitle_ar.message}</p> : null}
        </label>
      </FormSection>

      <FormSection title={t(copy.pricingPrice)} span>
        {oneTime ? (
          <label className="field-label">
            {t(copy.pricingOneTimePrice)}
            <input className={errors.price_usd ? "field has-error" : "field"} type="number" min={0} {...register("price_usd")} />
            {errors.price_usd ? <p className="field-error">{errors.price_usd.message}</p> : null}
          </label>
        ) : (
          <>
            <label className="field-label">
              {t(copy.billingMonthly)}
              <input className={errors.monthly ? "field has-error" : "field"} type="number" min={0} {...register("monthly")} />
              {errors.monthly ? <p className="field-error">{errors.monthly.message}</p> : null}
            </label>
            <label className="field-label">
              {t(copy.billingQuarterly)}
              <input className={errors.quarterly ? "field has-error" : "field"} type="number" min={0} {...register("quarterly")} />
              {errors.quarterly ? <p className="field-error">{errors.quarterly.message}</p> : null}
            </label>
            <label className="field-label">
              {t(copy.billingSemiannual)}
              <input className={errors.semiannual ? "field has-error" : "field"} type="number" min={0} {...register("semiannual")} />
              {errors.semiannual ? <p className="field-error">{errors.semiannual.message}</p> : null}
            </label>
            <label className="field-label">
              {t(copy.billingYearly)}
              <input className={errors.yearly ? "field has-error" : "field"} type="number" min={0} {...register("yearly")} />
              {errors.yearly ? <p className="field-error">{errors.yearly.message}</p> : null}
            </label>
            <div className="toolbar field-span">
              <button type="button" className="btn btn-ghost" onClick={applyAutoPrices}>
                {t(copy.pricingAutoCalc)}
              </button>
            </div>
          </>
        )}
      </FormSection>

      <FormSection title={t(copy.pricingFeaturesEn)} span>
        <label className="field-label">
          {t(copy.pricingFeaturesEn)}
          <textarea className="field" rows={5} {...register("features_en")} />
        </label>
        <label className="field-label">
          {t(copy.pricingFeaturesAr)}
          <textarea className="field" rows={5} {...register("features_ar")} />
        </label>
      </FormSection>

      <FormSection title={t(copy.pricingHasReach)}>
        <label className="checkbox-row">
          <input type="checkbox" {...register("has_reach")} />
          {t(copy.pricingHasReach)}
        </label>
        {hasReach ? (
          <>
            <label className="field-label">
              {t(copy.pricingAdBudget)}
              <input className="field" type="number" min={0} {...register("ad_budget_usd")} />
            </label>
            <label className="field-label">
              {t(copy.pricingAdCredit)}
              <input className="field" type="number" min={0} {...register("ad_credit_usd")} />
            </label>
            <label className="field-label">
              {t(copy.pricingReachEn)}
              <input className="field" {...register("reach_en")} />
            </label>
            <label className="field-label">
              {t(copy.pricingReachAr)}
              <input className="field" {...register("reach_ar")} />
            </label>
            <label className="field-label">
              {t(copy.pricingGoalEn)}
              <textarea className="field" rows={2} {...register("goal_en")} />
            </label>
            <label className="field-label">
              {t(copy.pricingGoalAr)}
              <textarea className="field" rows={2} {...register("goal_ar")} />
            </label>
          </>
        ) : null}
      </FormSection>

      <FormSection title={t(copy.featured)}>
        <label className="checkbox-row">
          <input type="checkbox" {...register("featured")} />
          {t(copy.featured)}
        </label>
        <label className="field-label">
          {t(copy.pricingBadgeEn)}
          <input className="field" {...register("badge_en")} />
        </label>
        <label className="field-label">
          {t(copy.pricingBadgeAr)}
          <input className="field" {...register("badge_ar")} />
        </label>
        <label className="field-label">
          {t(copy.packagePartialPay)}
          <select className="field" {...register("allows_partial_payment")}>
            <option value="">{t(copy.inheritCategory)}</option>
            <option value="true">{t(copy.partialPayment)}</option>
            <option value="false">{t(copy.fullPayment)}</option>
          </select>
        </label>
        <label className="checkbox-row">
          <input type="checkbox" {...register("is_published")} />
          {t(copy.published)}
        </label>
      </FormSection>
    </FormPage>
  );
}
