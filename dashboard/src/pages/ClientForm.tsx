import { useEffect, useMemo, useState } from "react";
import { useNavigate, useParams } from "react-router-dom";
import { toast } from "sonner";
import { z } from "zod";
import { FormPage } from "../components/FormPage";
import { FormSection } from "../components/FormSection";
import { LoadingLottie } from "../components/LoadingLottie";
import { useZodForm } from "../lib/useZodForm";
import { api } from "../api";
import { copy, type Locale } from "../i18n";

export function ClientForm({ t }: { locale: Locale; t: (c: { ar: string; en: string }) => string }) {
  const navigate = useNavigate();
  const params = useParams();
  const editingId = params.id ? Number(params.id) : null;

  const [loading, setLoading] = useState(Boolean(editingId));
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState("");

  const schema = useMemo(
    () =>
      z.object({
        name: z.string().trim().min(1, t(copy.fieldRequired)),
        email: z.union([z.literal(""), z.string().trim().email(t(copy.invalidEmail))]),
        phone: z.string().trim(),
        companyName: z.string().trim(),
      }),
    [t],
  );

  const {
    register,
    handleSubmit,
    reset,
    formState: { errors },
  } = useZodForm(schema, {
    defaultValues: { name: "", email: "", phone: "", companyName: "" },
  });

  useEffect(() => {
    if (!editingId) {
      setLoading(false);
      return;
    }
    setLoading(true);
    api
      .clients(1)
      .then((res) => res.data.find((row) => row.id === editingId))
      .then((item) => {
        if (!item) {
          setError(t(copy.saveFailed));
          return;
        }
        reset({
          name: item.name,
          email: item.email ?? "",
          phone: item.phone ?? "",
          companyName: item.company_name ?? "",
        });
      })
      .catch(() => setError(t(copy.saveFailed)))
      .finally(() => setLoading(false));
  }, [editingId, reset, t]);

  async function onValid(values: { name: string; email: string; phone: string; companyName: string }) {
    setError("");
    setBusy(true);
    try {
      const payload = {
        name: values.name,
        email: values.email || undefined,
        phone: values.phone || undefined,
        company_name: values.companyName || undefined,
      };
      if (editingId) await api.updateClient(editingId, payload);
      else await api.createClient(payload);
      toast.success(t(copy.saveSuccess));
      navigate("/clients");
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
      eyebrow={t(copy.clients)}
      title={editingId ? t(copy.editClient) : t(copy.addClient)}
      backTo="/clients"
      backLabel={t(copy.clients)}
      onSubmit={handleSubmit(onValid)}
      submitLabel={t(copy.saveClient)}
      cancelLabel={t(copy.cancel)}
      error={error}
      busy={busy}
    >
      <FormSection title={t(copy.client)}>
        <label className="field-label field-span">
          {t(copy.client)}
          <input className={errors.name ? "field has-error" : "field"} {...register("name")} />
          {errors.name ? <p className="field-error">{errors.name.message}</p> : null}
        </label>
        <label className="field-label">
          {t(copy.email)}
          <input className={errors.email ? "field has-error" : "field"} type="email" dir="ltr" {...register("email")} />
          {errors.email ? <p className="field-error">{errors.email.message}</p> : null}
        </label>
        <label className="field-label">
          {t(copy.phone)}
          <input className="field" dir="ltr" {...register("phone")} />
        </label>
        <label className="field-label field-span">
          {t(copy.company)}
          <input className="field" {...register("companyName")} />
        </label>
      </FormSection>
    </FormPage>
  );
}
