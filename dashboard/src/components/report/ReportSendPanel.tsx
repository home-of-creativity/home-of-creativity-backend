import { useEffect, useState, type FormEvent } from "react";
import { CheckCircle2, Mail, MessageCircle, Send, XCircle } from "lucide-react";
import { toast } from "sonner";
import { api, type Client, type ReportSend } from "../../api";
import { copy, type Locale } from "../../i18n";

/**
 * The «إرسال للعميل» block of the report tab: the PDF goes by email, WhatsApp, or both,
 * prefilled with the client's contact and editable.
 */
export function ReportSendPanel({
  reportId,
  client,
  locale,
  t,
  beforeSend,
  onContactSaved,
}: {
  reportId: number | null;
  client: Client | null;
  locale: Locale;
  t: (c: { ar: string; en: string }) => string;
  /** Saves pending edits; false stops the send. */
  beforeSend: () => Promise<boolean>;
  onContactSaved?: () => void;
}) {
  const [email, setEmail] = useState(client?.email ?? "");
  const [phone, setPhone] = useState(client?.phone ?? "");
  const [message, setMessage] = useState("");
  const [saveContact, setSaveContact] = useState(false);
  const [busy, setBusy] = useState(false);
  const [log, setLog] = useState<ReportSend[]>([]);

  useEffect(() => {
    setEmail(client?.email ?? "");
    setPhone(client?.phone ?? "");
  }, [client?.id, client?.email, client?.phone]);

  useEffect(() => {
    if (!reportId) return;
    let cancelled = false;
    api.reportSends(reportId)
      .then((res) => {
        if (!cancelled) setLog(res.data);
      })
      .catch(() => undefined);
    return () => {
      cancelled = true;
    };
  }, [reportId]);

  const newContact = (email.trim() !== "" && email.trim().toLowerCase() !== (client?.email ?? "").toLowerCase())
    || (phone.trim() !== "" && phone.trim() !== (client?.phone ?? ""));

  async function submit(event: FormEvent) {
    event.preventDefault();
    if (busy) return;
    if (!email.trim() && !phone.trim()) {
      toast.error(t(copy.reportSendNeedOne));
      return;
    }
    if (phone.trim()) {
      const who = client?.company_name || client?.name || "";
      const question = t(copy.reportSendConfirm).replace("{name}", who).replace("{phone}", phone.trim());
      if (!window.confirm(question)) return;
    }
    setBusy(true);
    try {
      const saved = await beforeSend();
      if (!saved || !reportId) {
        if (!reportId) toast.error(t(copy.reportSendNeedSave));
        return;
      }
      const res = await api.sendReport(reportId, {
        email: email.trim() || null,
        phone: phone.trim() || null,
        message: message.trim() || null,
        save_contact: saveContact && newContact,
      });
      setLog((current) => [...res.data, ...current]);
      if (res.data.some((row) => !row.delivered)) toast.warning(res.message ?? "");
      else toast.success(res.message ?? "");
      setMessage("");
      if (saveContact && newContact) onContactSaved?.();
    } catch (err) {
      toast.error(err instanceof Error ? err.message : t(copy.saveFailed));
      void api.reportSends(reportId ?? 0).then((res) => setLog(res.data)).catch(() => undefined);
    } finally {
      setBusy(false);
    }
  }

  const dateFormat = new Intl.DateTimeFormat(locale === "ar" ? "ar-SA-u-nu-latn" : "en-GB", { dateStyle: "medium", timeStyle: "short" });

  return (
    <section className="report-send" aria-label={t(copy.reportSendTitle)}>
      <h3>{t(copy.reportSendTitle)}</h3>
      <p className="muted">{t(copy.reportSendHint)}</p>
      <form className="report-send-form" onSubmit={(event) => void submit(event)}>
        <label className="field-label">
          {t(copy.reportSendEmail)}
          <input className="field" type="email" dir="ltr" value={email} maxLength={190} autoComplete="off" onChange={(event) => setEmail(event.target.value)} />
        </label>
        <label className="field-label">
          {t(copy.reportSendPhone)}
          <input className="field" type="tel" dir="ltr" value={phone} maxLength={40} placeholder="+963…" autoComplete="off" onChange={(event) => setPhone(event.target.value)} />
        </label>
        <label className="field-label">
          {t(copy.reportSendMessage)}
          <textarea className="field" rows={3} value={message} maxLength={2000} placeholder={t(copy.reportSendMessagePlaceholder)} onChange={(event) => setMessage(event.target.value)} />
        </label>
        {newContact && client ? (
          <label className="checkbox-row">
            <input type="checkbox" checked={saveContact} onChange={(event) => setSaveContact(event.target.checked)} />
            {t(copy.reportSendSaveContact)}
          </label>
        ) : null}
        <p className="muted report-send-note">{t(copy.reportSendDeliveredNote)}</p>
        <button type="submit" className="btn btn-primary btn-sm" disabled={busy || !reportId} aria-busy={busy}>
          <Send size={15} aria-hidden="true" />{busy ? t(copy.reportSending) : t(copy.reportSendButton)}
        </button>
        {!reportId ? <p className="muted">{t(copy.reportSendNeedSave)}</p> : null}
      </form>
      <h4>{t(copy.reportSendLog)}</h4>
      {log.length === 0 ? (
        <p className="muted">{t(copy.reportSendLogEmpty)}</p>
      ) : (
        <ul className="report-send-log">
          {log.map((row) => (
            <li key={row.id} className={row.delivered ? "is-ok" : "is-failed"}>
              {row.channel === "email" ? <Mail size={14} aria-hidden="true" /> : <MessageCircle size={14} aria-hidden="true" />}
              <span className="report-send-to">
                <strong dir="ltr">{row.recipient}</strong>
                <small>
                  {row.channel === "email" ? t(copy.reportSendViaEmail) : t(copy.reportSendViaWhatsApp)}
                  {row.user ? ` · ${row.user.name}` : ""}
                  {row.created_at ? ` · ${dateFormat.format(new Date(row.created_at))}` : ""}
                </small>
              </span>
              <span className="report-send-state">
                {row.delivered ? <CheckCircle2 size={14} aria-hidden="true" /> : <XCircle size={14} aria-hidden="true" />}
                {row.delivered ? t(copy.reportSendOk) : t(copy.reportSendFailed)}
              </span>
            </li>
          ))}
        </ul>
      )}
    </section>
  );
}
