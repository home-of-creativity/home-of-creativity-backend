import { useEffect, useMemo, useState } from "react";
import { useNavigate, useParams, useSearchParams } from "react-router-dom";
import { toast } from "sonner";
import { z } from "zod";
import { FormPage } from "../components/FormPage";
import { FormSection } from "../components/FormSection";
import { LoadingLottie } from "../components/LoadingLottie";
import { useZodForm } from "../lib/useZodForm";
import { api, type ContactChannel } from "../api";
import { copy, type Locale } from "../i18n";

type Tab = "numbers" | "social" | "locations";
const platforms = ["instagram", "facebook", "linkedin", "x", "tiktok", "youtube"] as const;

const platformNames: Record<string, { ar: string; en: string }> = {
  instagram: copy.instagram,
  facebook: copy.facebook,
  linkedin: copy.linkedin,
  x: copy.xTwitter,
  tiktok: copy.tiktok,
  youtube: copy.youtube,
};

function channelToTab(kind: ContactChannel["kind"]): Tab {
  if (kind === "social") return "social";
  if (kind === "location") return "locations";
  return "numbers";
}

export function ContactChannelForm({ t }: { locale: Locale; t: (c: { ar: string; en: string }) => string }) {
  const navigate = useNavigate();
  const params = useParams();
  const [searchParams] = useSearchParams();
  const editingId = params.id ? Number(params.id) : null;

  const [tab, setTab] = useState<Tab>((searchParams.get("tab") as Tab) ?? "numbers");
  const [loading, setLoading] = useState(Boolean(editingId));
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState("");

  const numberSchema = useMemo(
    () =>
      z.object({
        kind: z.enum(["mobile", "whatsapp"]),
        region: z.string(),
        value: z.string().trim().min(1, t(copy.fieldRequired)),
        digits: z.string().trim().min(1, t(copy.fieldRequired)),
        is_published: z.boolean(),
      }),
    [t],
  );
  const socialSchema = useMemo(
    () =>
      z.object({
        platform: z.string(),
        value: z.string().trim().min(1, t(copy.fieldRequired)),
        value_ar: z.string().trim(),
        url: z.string().trim().min(1, t(copy.fieldRequired)),
        is_published: z.boolean(),
      }),
    [t],
  );
  const locationSchema = useMemo(
    () =>
      z.object({
        region: z.string(),
        value: z.string().trim().min(1, t(copy.fieldRequired)),
        value_ar: z.string().trim().min(1, t(copy.fieldRequired)),
        is_published: z.boolean(),
      }),
    [t],
  );

  const numberFormApi = useZodForm(numberSchema, {
    defaultValues: { kind: "mobile", region: "SYR", value: "", digits: "", is_published: true },
  });
  const socialFormApi = useZodForm(socialSchema, {
    defaultValues: { platform: "instagram", value: "Instagram", value_ar: "إنستغرام", url: "", is_published: true },
  });
  const locationFormApi = useZodForm(locationSchema, {
    defaultValues: { region: "SYR", value: "", value_ar: "", is_published: true },
  });

  useEffect(() => {
    if (!editingId) {
      setLoading(false);
      return;
    }
    setLoading(true);
    api
      .contactChannels()
      .then((res) => res.data.find((row) => row.id === editingId))
      .then((item) => {
        if (!item) {
          setError(t(copy.savePortfolioFailed));
          return;
        }
        setTab(channelToTab(item.kind));
        if (item.kind === "social") {
          socialFormApi.reset({
            platform: item.platform ?? "instagram",
            value: item.value,
            value_ar: item.value_ar ?? "",
            url: item.url ?? "",
            is_published: item.is_published,
          });
        } else if (item.kind === "location") {
          locationFormApi.reset({
            region: item.region ?? "SYR",
            value: item.value,
            value_ar: item.value_ar ?? "",
            is_published: item.is_published,
          });
        } else {
          numberFormApi.reset({
            kind: item.kind === "whatsapp" ? "whatsapp" : "mobile",
            region: item.region ?? "SYR",
            value: item.value,
            digits: item.digits ?? "",
            is_published: item.is_published,
          });
        }
      })
      .catch(() => setError(t(copy.savePortfolioFailed)))
      .finally(() => setLoading(false));
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [editingId]);

  async function saveChannel(payload: Record<string, unknown>) {
    setError("");
    setBusy(true);
    try {
      if (editingId) await api.updateContactChannel(editingId, payload as never);
      else await api.createContactChannel(payload as never);
      toast.success(t(copy.saveSuccess));
      navigate(`/contact?tab=${tab}`);
    } catch (err) {
      const message = err instanceof Error ? err.message : t(copy.savePortfolioFailed);
      setError(message);
      toast.error(message);
      setBusy(false);
    }
  }

  const onSubmitNumbers = numberFormApi.handleSubmit((values) =>
    saveChannel({ ...values, digits: values.digits.replace(/\D+/g, "") }),
  );
  const onSubmitSocial = socialFormApi.handleSubmit((values) => saveChannel({ kind: "social", ...values, url: values.url.trim() }));
  const onSubmitLocation = locationFormApi.handleSubmit((values) => saveChannel({ kind: "location", ...values }));

  if (loading) return <LoadingLottie variant="page" label={t(copy.loading)} />;

  const title = editingId
    ? t(copy.edit)
    : tab === "social"
      ? t(copy.addContactSocial)
      : tab === "locations"
        ? t(copy.addContactLocation)
        : t(copy.addContactNumber);
  const submitLabel = tab === "social" ? t(copy.saveContactSocial) : tab === "locations" ? t(copy.saveContactLocation) : t(copy.saveContactNumber);
  const onSubmit = tab === "social" ? onSubmitSocial : tab === "locations" ? onSubmitLocation : onSubmitNumbers;

  return (
    <FormPage
      eyebrow={t(copy.contactTitle)}
      title={title}
      backTo={`/contact?tab=${tab}`}
      backLabel={t(copy.contactTitle)}
      onSubmit={onSubmit}
      submitLabel={submitLabel}
      cancelLabel={t(copy.cancel)}
      error={error}
      busy={busy}
    >
      {tab === "numbers" ? (
        <FormSection title={t(copy.contactTabNumbers)}>
          <label className="field-label">
            {t(copy.contactKind)}
            <select className="field" {...numberFormApi.register("kind")}>
              <option value="mobile">{t(copy.contactKindMobile)}</option>
              <option value="whatsapp">{t(copy.contactKindWhatsapp)}</option>
            </select>
          </label>
          <label className="field-label">
            {t(copy.contactRegion)}
            <select className="field" {...numberFormApi.register("region")}>
              <option value="SYR">{t(copy.regionSyr)}</option>
              <option value="KSA">{t(copy.regionKsa)}</option>
            </select>
          </label>
          <label className="field-label">
            {t(copy.contactDisplay)}
            <input
              className={numberFormApi.formState.errors.value ? "field has-error" : "field"}
              dir="ltr"
              {...numberFormApi.register("value")}
            />
            {numberFormApi.formState.errors.value ? <p className="field-error">{numberFormApi.formState.errors.value.message}</p> : null}
          </label>
          <label className="field-label">
            {t(copy.contactDigits)}
            <input
              className={numberFormApi.formState.errors.digits ? "field has-error" : "field"}
              dir="ltr"
              {...numberFormApi.register("digits")}
            />
            {numberFormApi.formState.errors.digits ? <p className="field-error">{numberFormApi.formState.errors.digits.message}</p> : null}
          </label>
          <label className="checkbox-row">
            <input type="checkbox" {...numberFormApi.register("is_published")} />
            {t(copy.published)}
          </label>
        </FormSection>
      ) : null}

      {tab === "social" ? (
        <FormSection title={t(copy.contactTabSocial)}>
          <label className="field-label">
            {t(copy.contactKind)}
            <select
              className="field"
              {...socialFormApi.register("platform", {
                onChange: (event) => {
                  const platform = event.target.value;
                  const names = platformNames[platform];
                  socialFormApi.setValue("value", names?.en ?? platform);
                  socialFormApi.setValue("value_ar", names?.ar ?? platform);
                },
              })}
            >
              {platforms.map((platform) => (
                <option key={platform} value={platform}>
                  {t(platformNames[platform] ?? { ar: platform, en: platform })}
                </option>
              ))}
            </select>
          </label>
          <label className="field-label">
            {t(copy.contactUrl)}
            <input
              className={socialFormApi.formState.errors.url ? "field has-error" : "field"}
              dir="ltr"
              type="url"
              {...socialFormApi.register("url")}
            />
            {socialFormApi.formState.errors.url ? <p className="field-error">{socialFormApi.formState.errors.url.message}</p> : null}
          </label>
          <label className="field-label">
            {t(copy.contactDisplayEn)}
            <input
              className={socialFormApi.formState.errors.value ? "field has-error" : "field"}
              {...socialFormApi.register("value")}
            />
            {socialFormApi.formState.errors.value ? <p className="field-error">{socialFormApi.formState.errors.value.message}</p> : null}
          </label>
          <label className="field-label">
            {t(copy.contactDisplayAr)}
            <input className="field" {...socialFormApi.register("value_ar")} />
          </label>
          <label className="checkbox-row">
            <input type="checkbox" {...socialFormApi.register("is_published")} />
            {t(copy.published)}
          </label>
        </FormSection>
      ) : null}

      {tab === "locations" ? (
        <FormSection title={t(copy.contactTabLocations)}>
          <label className="field-label">
            {t(copy.contactRegion)}
            <select className="field" {...locationFormApi.register("region")}>
              <option value="SYR">{t(copy.regionSyr)}</option>
              <option value="KSA">{t(copy.regionKsa)}</option>
            </select>
          </label>
          <label className="field-label">
            {t(copy.cityEn)}
            <input
              className={locationFormApi.formState.errors.value ? "field has-error" : "field"}
              {...locationFormApi.register("value")}
            />
            {locationFormApi.formState.errors.value ? <p className="field-error">{locationFormApi.formState.errors.value.message}</p> : null}
          </label>
          <label className="field-label">
            {t(copy.cityAr)}
            <input
              className={locationFormApi.formState.errors.value_ar ? "field has-error" : "field"}
              {...locationFormApi.register("value_ar")}
            />
            {locationFormApi.formState.errors.value_ar ? (
              <p className="field-error">{locationFormApi.formState.errors.value_ar.message}</p>
            ) : null}
          </label>
          <label className="checkbox-row">
            <input type="checkbox" {...locationFormApi.register("is_published")} />
            {t(copy.published)}
          </label>
        </FormSection>
      ) : null}
    </FormPage>
  );
}
