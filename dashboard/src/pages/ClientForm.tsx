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

type FieldName = "name" | "email" | "phone" | "companyName" | "companyActivity";

const FIELD_NAMES: Record<string, FieldName> = {
  name: "name",
  email: "email",
  phone: "phone",
  company_name: "companyName",
  company_activity: "companyActivity",
};

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
        companyActivity: z.string().trim().max(60),
      }),
    [t],
  );

  const {
    register,
    handleSubmit,
    reset,
    setError: setFieldError,
    formState: { errors },
  } = useZodForm(schema, {
    defaultValues: { name: "", email: "", phone: "", companyName: "", companyActivity: "" },
  });

  useEffect(() => {
    if (!editingId) {
      setLoading(false);
      return;
    }
    setLoading(true);
    api
      .clients(1, { id: editingId })
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
          companyActivity: item.company_activity ?? "",
        });
      })
      .catch(() => setError(t(copy.saveFailed)))
      .finally(() => setLoading(false));
  }, [editingId, reset, t]);

  async function onValid(values: { name: string; email: string; phone: string; companyName: string; companyActivity: string }) {
    setError("");
    setBusy(true);
    try {
      if (editingId) {
        await api.updateClient(editingId, {
          name: values.name,
          email: values.email || null,
          phone: values.phone || null,
          company_name: values.companyName || null,
          company_activity: values.companyActivity || null,
        });
      } else {
        await api.createClient({
          name: values.name,
          email: values.email || undefined,
          phone: values.phone || undefined,
          company_name: values.companyName || undefined,
          company_activity: values.companyActivity || undefined,
        });
      }
      toast.success(t(copy.saveSuccess));
      navigate("/clients");
    } catch (err) {
      const message = err instanceof Error ? err.message : t(copy.saveFailed);
      const raw = err && typeof err === "object" && "errors" in err ? (err as { errors?: Record<string, string[]> }).errors : undefined;
      const fields = raw ? Object.entries(raw) : [];
      for (const [key, lines] of fields) {
        const name = FIELD_NAMES[key];
        if (name && lines[0]) setFieldError(name, { type: "server", message: lines[0] });
      }
      if (!fields.some(([key]) => FIELD_NAMES[key])) setError(message);
      toast.error(message);
      setBusy(false);
    }
  }

  if (loading) return <LoadingLottie variant="page" label={t(copy.loading)} />;

  return (
    <FormPage
      ability={editingId ? "ops.clients.update" : "ops.clients.create"}
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
          <input className={errors.phone ? "field has-error" : "field"} type="tel" inputMode="tel" dir="ltr" {...register("phone")} />
          {errors.phone ? <p className="field-error">{errors.phone.message}</p> : null}
        </label>
        <label className="field-label">
          {t(copy.company)}
          <input className={errors.companyName ? "field has-error" : "field"} {...register("companyName")} />
          {errors.companyName ? <p className="field-error">{errors.companyName.message}</p> : null}
        </label>
        <label className="field-label">
          {t(copy.companyActivity)}
          <input className={errors.companyActivity ? "field has-error" : "field"} maxLength={60} {...register("companyActivity")} />
          {errors.companyActivity ? <p className="field-error">{errors.companyActivity.message}</p> : null}
        </label>
      </FormSection>
    </FormPage>
  );
}
