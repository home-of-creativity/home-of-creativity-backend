import { useEffect, useMemo, useState } from "react";
import { useNavigate, useParams } from "react-router-dom";
import { toast } from "sonner";
import { z } from "zod";
import { FileDropzone } from "../../components/FileDropzone";
import { FormPage } from "../../components/FormPage";
import { FormSection } from "../../components/FormSection";
import { LoadingLottie } from "../../components/LoadingLottie";
import { useZodForm } from "../../lib/useZodForm";
import { api } from "../../api";
import { copy, type Locale } from "../../i18n";

export function ClientLogoForm({ t }: { locale: Locale; t: (c: { ar: string; en: string }) => string }) {
  const navigate = useNavigate();
  const params = useParams();
  const editingId = params.id ? Number(params.id) : null;

  const [logo, setLogo] = useState<File | null>(null);
  const [currentLogoUrl, setCurrentLogoUrl] = useState<string | null>(null);
  const [logoPreview, setLogoPreview] = useState<string | null>(null);
  const [loading, setLoading] = useState(Boolean(editingId));
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState("");

  const schema = useMemo(
    () =>
      z.object({
        name: z.string().trim().min(1, t(copy.fieldRequired)),
        websiteUrl: z.union([z.literal(""), z.string().trim().url(t(copy.invalidUrl))]),
        sortOrder: z.string().trim(),
        isPublished: z.boolean(),
      }),
    [t],
  );

  const {
    register,
    handleSubmit,
    reset,
    formState: { errors },
  } = useZodForm(schema, {
    defaultValues: { name: "", websiteUrl: "", sortOrder: "", isPublished: true },
  });

  useEffect(() => {
    if (!editingId) {
      setLoading(false);
      return;
    }
    setLoading(true);
    api
      .showcaseClients(1)
      .then((res) => res.data.find((row) => row.id === editingId))
      .then((item) => {
        if (!item) {
          setError(t(copy.saveFailed));
          return;
        }
        reset({
          name: item.name,
          websiteUrl: item.website_url ?? "",
          sortOrder: String(item.sort_order),
          isPublished: item.is_published,
        });
        setCurrentLogoUrl(item.logo_url);
      })
      .catch(() => setError(t(copy.savePortfolioFailed)))
      .finally(() => setLoading(false));
  }, [editingId, reset, t]);

  async function onValid(values: { name: string; websiteUrl: string; sortOrder: string; isPublished: boolean }) {
    setError("");
    setBusy(true);
    const payload = new FormData();
    payload.set("name", values.name.trim());
    payload.set("website_url", values.websiteUrl.trim());
    payload.set("sort_order", values.sortOrder.trim() || "0");
    payload.set("is_published", values.isPublished ? "1" : "0");
    if (logo) payload.set("logo", logo);

    try {
      if (editingId) await api.updateShowcaseClient(editingId, payload);
      else await api.createShowcaseClient(payload);
      toast.success(t(copy.saveSuccess));
      navigate("/clients?tab=logos");
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
      eyebrow={t(copy.portfolioTabClients)}
      title={editingId ? t(copy.edit) : t(copy.addShowcaseClient)}
      backTo="/clients?tab=logos"
      backLabel={t(copy.portfolioTabClients)}
      onSubmit={handleSubmit(onValid)}
      submitLabel={t(copy.saveShowcaseClient)}
      cancelLabel={t(copy.cancel)}
      error={error}
      busy={busy}
      wide
    >
      <FormSection title={t(copy.client)}>
        <label className="field-label field-span">
          {t(copy.client)}
          <input className={errors.name ? "field has-error" : "field"} {...register("name")} />
          {errors.name ? <p className="field-error">{errors.name.message}</p> : null}
        </label>
        <label className="field-label">
          {t(copy.website)}
          <input className={errors.websiteUrl ? "field has-error" : "field"} type="url" placeholder="https://" {...register("websiteUrl")} />
          {errors.websiteUrl ? <p className="field-error">{errors.websiteUrl.message}</p> : null}
        </label>
        <label className="field-label">
          {t(copy.sortOrder)}
          <input className="field" type="number" min={0} {...register("sortOrder")} />
        </label>
        <label className="checkbox-row">
          <input type="checkbox" {...register("isPublished")} />
          {t(copy.published)}
        </label>
      </FormSection>
      <div className="form-page-media">
        <FormSection title={t(copy.logoFile)}>
          <label className="field-label field-span">
            {t(copy.logoFile)}
            <FileDropzone
              accept={{ "image/png": [".png"], "image/jpeg": [".jpg", ".jpeg"], "image/webp": [".webp"], "image/svg+xml": [".svg"] }}
              hint={t(copy.dropzoneHint)}
              activeHint={t(copy.dropzoneActive)}
              onFiles={(files) => {
                const file = files[0] ?? null;
                setLogo(file);
                setLogoPreview(file ? URL.createObjectURL(file) : null);
              }}
            />
          </label>
          {(logoPreview ?? currentLogoUrl) ? (
            <div className="form-media-preview">
              <img src={logoPreview ?? currentLogoUrl ?? ""} alt="" className="form-media-preview-img" />
              <p className="muted">{logoPreview ? t(copy.replaceLogo) : t(copy.currentLogo)}</p>
            </div>
          ) : null}
        </FormSection>
      </div>
    </FormPage>
  );
}
