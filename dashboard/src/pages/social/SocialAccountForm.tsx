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
import { platformLabel, socialPlatforms } from "./helpers";
import { useSocialWorkspace } from "./SocialWorkspace";

export function SocialAccountForm({ t }: { locale: Locale; t: (c: { ar: string; en: string }) => string }) {
  const navigate = useNavigate();
  const params = useParams();
  const editingId = params.id ? Number(params.id) : null;

  const { accounts, refreshAccounts } = useSocialWorkspace();
  const [loading, setLoading] = useState(Boolean(editingId));
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState("");

  const schema = useMemo(
    () =>
      z.object({
        platform: z.string(),
        name: z.string().trim().min(1, t(copy.fieldRequired)),
        handle: z.string().trim(),
        pageId: z.string().trim(),
        accessToken: z.string(),
        isActive: z.boolean(),
      }),
    [t],
  );

  const {
    register,
    handleSubmit,
    reset,
    watch,
    formState: { errors },
  } = useZodForm(schema, {
    defaultValues: { platform: "facebook", name: "", handle: "", pageId: "", accessToken: "", isActive: true },
  });
  const isActive = watch("isActive");

  useEffect(() => {
    if (!editingId) {
      setLoading(false);
      return;
    }
    const item = accounts.find((row) => row.id === editingId);
    if (!item) {
      if (accounts.length === 0) return;
      setError(t(copy.saveFailed));
      setLoading(false);
      return;
    }
    reset({
      platform: item.platform,
      name: item.name,
      handle: item.handle ?? "",
      pageId: item.page_id ?? "",
      accessToken: "",
      isActive: item.is_active,
    });
    setLoading(false);
  }, [editingId, accounts, reset, t]);

  async function onValid(values: {
    platform: string;
    name: string;
    handle: string;
    pageId: string;
    accessToken: string;
    isActive: boolean;
  }) {
    setError("");
    setBusy(true);
    try {
      const payload = {
        platform: values.platform,
        name: values.name,
        handle: values.handle || null,
        page_id: values.pageId || null,
        is_active: values.isActive,
        ...(values.accessToken ? { access_token: values.accessToken } : {}),
      };
      if (editingId) await api.updateSocialAccount(editingId, payload);
      else await api.createSocialAccount(payload);
      await refreshAccounts();
      toast.success(t(copy.saveSuccess));
      navigate("/social/accounts");
    } catch (err) {
      const message = err instanceof Error ? err.message : t(copy.saveFailed);
      setError(message);
      toast.error(message);
      setBusy(false);
    }
  }

  if (loading) return <LoadingLottie variant="page" label={t(copy.loading)} />;

  return (
    <FormPage
      eyebrow={t(copy.socialAccounts)}
      title={editingId ? t(copy.edit) : t(copy.socialAddAccount)}
      backTo="/social/accounts"
      backLabel={t(copy.socialAccounts)}
      onSubmit={handleSubmit(onValid)}
      submitLabel={t(copy.socialSaveAccount)}
      cancelLabel={t(copy.cancel)}
      error={error}
      busy={busy}
    >
      <FormSection title={t(copy.socialAddAccount)}>
        <label className="field-label">
          <span>{t(copy.socialPlatform)}</span>
          <select className="field" {...register("platform")}>
            {socialPlatforms.map((item) => (
              <option key={item} value={item}>
                {platformLabel(item, t)}
              </option>
            ))}
          </select>
        </label>
        <label className="field-label">
          <span>{t(copy.employeeName)}</span>
          <input className={errors.name ? "field has-error" : "field"} {...register("name")} />
          {errors.name ? <p className="field-error">{errors.name.message}</p> : null}
        </label>
        <label className="field-label">
          <span>{t(copy.socialHandle)}</span>
          <input className="field" dir="ltr" {...register("handle")} />
        </label>
        <label className="field-label">
          <span>{t(copy.socialPageId)}</span>
          <input className="field" dir="ltr" {...register("pageId")} />
        </label>
        <label className="field-label field-span">
          <span>{t(copy.socialToken)}</span>
          <input className="field" type="password" dir="ltr" autoComplete="off" {...register("accessToken")} />
          <small className="muted">{t(copy.socialTokenHint)}</small>
        </label>
        <label className="check-row field-span">
          <input type="checkbox" {...register("isActive")} />
          <span>{isActive ? t(copy.socialToggleOn) : t(copy.socialToggleOff)}</span>
        </label>
      </FormSection>
    </FormPage>
  );
}
