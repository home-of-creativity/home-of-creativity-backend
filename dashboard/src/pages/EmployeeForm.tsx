import { useEffect, useMemo, useState } from "react";
import { useNavigate, useParams } from "react-router-dom";
import { toast } from "sonner";
import { z } from "zod";
import { FormPage } from "../components/FormPage";
import { FormSection } from "../components/FormSection";
import { LoadingLottie } from "../components/LoadingLottie";
import { useZodForm } from "../lib/useZodForm";
import { api, type ClickUpMember } from "../api";
import { copy, professions, type Locale } from "../i18n";

export function EmployeeForm({ t }: { locale: Locale; t: (c: { ar: string; en: string }) => string }) {
  const navigate = useNavigate();
  const params = useParams();
  const isApprove = window.location.pathname.endsWith("/approve");
  const editingId = params.id ? Number(params.id) : null;

  const [members, setMembers] = useState<ClickUpMember[]>([]);
  const [loading, setLoading] = useState(Boolean(editingId));
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState("");

  const schema = useMemo(
    () =>
      z.object({
        name: z.string().trim().min(1, t(copy.fieldRequired)),
        code: z.string().trim().optional(),
        phone: z.string().trim(),
        clickup_user_id: z.string().trim(),
        profession: z.string(),
        notes: z.string().trim(),
        telegram_user_id: z.string().trim(),
        telegram_username: z.string().trim(),
        is_active: z.boolean(),
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
    defaultValues: {
      name: "",
      code: "",
      phone: "",
      clickup_user_id: "",
      profession: "sales",
      notes: "",
      telegram_user_id: "",
      telegram_username: "",
      is_active: true,
    },
  });

  useEffect(() => {
    api
      .clickupMembers()
      .then((res) => setMembers(res.data))
      .catch(() => setMembers([]));
  }, []);

  useEffect(() => {
    if (!editingId) {
      setLoading(false);
      return;
    }
    setLoading(true);
    api
      .employee(editingId)
      .then((res) => {
        const item = res.data;
        if (isApprove) {
          reset({
            name: item.name,
            code: "",
            phone: "",
            clickup_user_id: item.clickup_user_id ?? "",
            profession: item.profession || "sales",
            notes: "",
            telegram_user_id: "",
            telegram_username: "",
            is_active: true,
          });
        } else {
          reset({
            name: item.name,
            code: item.code,
            phone: item.phone ?? "",
            clickup_user_id: item.clickup_user_id ?? "",
            profession: item.profession,
            notes: item.notes ?? "",
            telegram_user_id: item.telegram_user_id ?? "",
            telegram_username: item.telegram_username ?? "",
            is_active: item.is_active,
          });
        }
      })
      .catch(() => setError(t(copy.saveFailed)))
      .finally(() => setLoading(false));
  }, [editingId, isApprove, reset, t]);

  async function onValid(values: {
    name: string;
    code?: string;
    phone: string;
    clickup_user_id: string;
    profession: string;
    notes: string;
    telegram_user_id: string;
    telegram_username: string;
    is_active: boolean;
  }) {
    setError("");
    setBusy(true);
    const payload = {
      name: values.name.trim(),
      phone: values.phone.trim() || null,
      email: members.find((member) => member.id === values.clickup_user_id)?.email ?? null,
      clickup_user_id: values.clickup_user_id.trim() || null,
      profession: values.profession,
      notes: values.notes.trim() || null,
      telegram_user_id: values.telegram_user_id.trim() || null,
      telegram_username: values.telegram_username.trim().replace(/^@/, "") || null,
      is_active: values.is_active,
    };
    try {
      let odooError: string | null | undefined;
      if (isApprove && editingId) {
        await api.approveEmployee(editingId, {
          profession: payload.profession,
          clickup_user_id: payload.clickup_user_id,
        });
      } else if (editingId) {
        odooError = (await api.updateEmployee(editingId, payload)).odoo_error;
      } else {
        odooError = (await api.createEmployee(payload)).odoo_error;
      }
      if (odooError) {
        toast.error(`${t(copy.employeeSavedOdooFailed)} ${odooError}`);
      } else {
        toast.success(t(copy.saveSuccess));
      }
      navigate("/employees");
    } catch (err) {
      const message = err instanceof Error ? err.message : t(copy.saveFailed);
      setError(message);
      toast.error(message);
      setBusy(false);
    }
  }

  if (loading) return <LoadingLottie variant="page" label={t(copy.loading)} />;

  const title = isApprove ? t(copy.approveEmployee) : editingId ? t(copy.editEmployee) : t(copy.addEmployee);
  const submitLabel = isApprove ? t(copy.approveEmployee) : editingId ? t(copy.saveEmployee) : t(copy.addEmployee);

  return (
    <FormPage
      eyebrow={t(copy.employees)}
      title={title}
      backTo="/employees"
      backLabel={t(copy.employees)}
      onSubmit={handleSubmit(onValid)}
      submitLabel={submitLabel}
      cancelLabel={t(copy.cancel)}
      error={error}
      busy={busy}
    >
      <FormSection title={t(copy.employeeName)}>
        <label className="field-label field-span">
          {t(copy.employeeName)}
          <input className={errors.name ? "field has-error" : "field"} disabled={isApprove} {...register("name")} />
          {errors.name ? <p className="field-error">{errors.name.message}</p> : null}
        </label>
        {!isApprove && editingId ? (
          <label className="field-label">
            {t(copy.employeeCode)}
            <input className="field" dir="ltr" readOnly value={watch("code") ?? ""} />
          </label>
        ) : null}
        {!isApprove ? (
          <>
            <label className="field-label">
              {t(copy.phone)}
              <input className="field" dir="ltr" {...register("phone")} />
            </label>
            <label className="field-label">
              {t(copy.telegramUsername)}
              <input className="field" dir="ltr" placeholder="@name" {...register("telegram_username")} />
            </label>
            <label className="field-label">
              {t(copy.telegramUserId)}
              <input className="field" dir="ltr" inputMode="numeric" {...register("telegram_user_id")} />
            </label>
            <p className="muted field-span">
              {editingId
                ? t(copy.linkTelegramHint).replace("{code}", watch("code") || "—")
                : t(copy.linkTelegramLater)}
            </p>
          </>
        ) : null}
      </FormSection>

      <FormSection title={t(copy.profession)} description={t(copy.joinHint)}>
        <label className="field-label">
          {t(copy.profession)}
          <select className="field" {...register("profession")}>
            {Object.entries(professions).map(([key, label]) => (
              <option key={key} value={key}>
                {t(label)}
              </option>
            ))}
          </select>
        </label>
        <label className="field-label">
          {t(copy.clickupMember)}
          <select className="field" {...register("clickup_user_id")}>
            <option value="">{t(copy.clickupUnlinked)}</option>
            {members.map((member) => (
              <option key={member.id} value={member.id}>
                {member.email ? `${member.name} (${member.email})` : member.name}
              </option>
            ))}
          </select>
        </label>
        <p className="field-label">
          {t(copy.email)}
          <span dir="ltr">{members.find((member) => member.id === watch("clickup_user_id"))?.email || "—"}</span>
        </p>
        {!isApprove ? (
          <>
            <label className="checkbox-row">
              <input type="checkbox" {...register("is_active")} />
              {t(copy.active)}
            </label>
            <label className="field-label field-span">
              {t(copy.notes)}
              <textarea className="field field-area" rows={3} {...register("notes")} />
            </label>
          </>
        ) : null}
      </FormSection>
    </FormPage>
  );
}
