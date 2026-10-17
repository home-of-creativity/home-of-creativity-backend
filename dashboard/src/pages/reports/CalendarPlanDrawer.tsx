import { useEffect, useState, type FormEvent } from "react";
import { X } from "lucide-react";
import { toast } from "sonner";
import { api, type CommentSubject, type ReportSlot } from "../../api";
import { CommentsPanel } from "../../components/CommentsPanel";
import { copy, type Locale } from "../../i18n";
import { ClientPicker } from "./DeadlineDialog";

const KINDS = ["image", "post", "reel"] as const;

type ClientPick = { id: number; name: string; company_name: string | null };
type Thread = { key: string; label: string; subject: CommentSubject };

function pad(value: number) {
  return String(value).padStart(2, "0");
}

function localValue(iso: string | null) {
  if (!iso) return "";
  if (!iso.includes("T") || iso.length === 16) return iso.slice(0, 16);
  const date = new Date(iso);
  if (Number.isNaN(date.getTime())) return "";
  return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}T${pad(date.getHours())}:${pad(date.getMinutes())}`;
}

function kindLabel(value: ReportSlot["kind"], locale: Locale) {
  if (value === "image") return copy.reportScheduleImage[locale];
  if (value === "reel") return copy.reportScheduleReel[locale];
  return copy.reportSchedulePost[locale];
}

function PlanImage({ slotId }: { slotId: number }) {
  const [url, setUrl] = useState<string | null>(null);
  useEffect(() => {
    let gone = false;
    let objectUrl = "";
    void api.reportSlotImage(slotId).then((blob) => {
      objectUrl = URL.createObjectURL(blob);
      if (!gone) setUrl(objectUrl);
    }).catch(() => undefined);
    return () => {
      gone = true;
      if (objectUrl) URL.revokeObjectURL(objectUrl);
    };
  }, [slotId]);
  if (!url) return null;
  return <img className="report-slot-thumb" src={url} alt="" />;
}

/**
 * Side panel on the reports calendar: plan a picture, post, or reel, then talk about it.
 * Saving never publishes.
 */
export function CalendarPlanDrawer({
  locale,
  slot,
  at,
  fixedClient,
  onClose,
  onSaved,
  onDeleted,
}: {
  locale: Locale;
  slot: ReportSlot | null;
  at: string;
  fixedClient: ClientPick | null;
  onClose: () => void;
  onSaved: (slot: ReportSlot) => void;
  onDeleted: () => void;
}) {
  const t = (item: { ar: string; en: string }) => item[locale];
  const [client, setClient] = useState<ClientPick | null>(fixedClient ?? (slot ? { id: slot.client_id, name: slot.client_name, company_name: null } : null));
  const [kind, setKind] = useState<(typeof KINDS)[number]>(slot?.kind ?? "post");
  const [when, setWhen] = useState(localValue(slot?.scheduled_at ?? at));
  const [caption, setCaption] = useState(slot?.caption ?? "");
  const [image, setImage] = useState<File | null>(null);
  const [busy, setBusy] = useState(false);
  const [thread, setThread] = useState(slot ? `slot:${slot.id}` : "");
  const [threads, setThreads] = useState<Thread[]>([]);

  useEffect(() => {
    const onKey = (event: KeyboardEvent) => {
      if (event.key === "Escape") onClose();
    };
    window.addEventListener("keydown", onKey);
    return () => window.removeEventListener("keydown", onKey);
  }, [onClose]);

  useEffect(() => {
    if (!client) {
      setThreads([]);
      return;
    }
    let gone = false;
    void api.clientReports(client.id).then((list) => {
      if (gone) return;
      const next: Thread[] = [];
      if (slot) {
        next.push({
          key: `slot:${slot.id}`,
          label: `${kindLabel(slot.kind, locale)} · ${slot.client_name}`,
          subject: { type: "report_slot", id: String(slot.id) },
        });
      }
      for (const row of list.data) {
        next.push({
          key: `report:${row.id}`,
          label: row.title || `#${row.id}`,
          subject: { type: "report", id: String(row.id) },
        });
      }
      setThreads(next);
      setThread((current) => (next.some((item) => item.key === current) ? current : next[0]?.key ?? ""));
    }).catch(() => undefined);
    return () => {
      gone = true;
    };
  }, [client, slot, locale]);

  async function save(event: FormEvent) {
    event.preventDefault();
    if (!client || busy) {
      if (!client) toast.error(locale === "ar" ? "اختر الزبون." : "Choose the client.");
      return;
    }
    const form = new FormData();
    form.set("client_id", String(client.id));
    form.set("kind", kind);
    form.set("scheduled_at", when);
    form.set("caption", caption);
    if (image) form.set("image", image);
    setBusy(true);
    try {
      const saved = await api.saveReportSlot(form, slot?.id);
      toast.success(t(copy.reportScheduleSaved));
      onSaved(saved.data);
    } catch (err) {
      toast.error(err instanceof Error ? err.message : t(copy.savePortfolioFailed));
    } finally {
      setBusy(false);
    }
  }

  async function remove() {
    if (!slot) return;
    setBusy(true);
    try {
      await api.deleteReportSlot(slot.id);
      onDeleted();
    } catch (err) {
      toast.error(err instanceof Error ? err.message : t(copy.savePortfolioFailed));
      setBusy(false);
    }
  }

  const current = threads.find((item) => item.key === thread) ?? null;

  return (
    <>
      <button type="button" className="report-thread-backdrop" aria-label={t(copy.close)} onClick={onClose} />
      <aside className="report-thread-drawer" role="dialog" aria-label={t(copy.reportSchedule)}>
        <header className="report-thread-head">
          <strong>{t(copy.reportSchedule)}</strong>
          <button type="button" className="btn btn-ghost btn-icon" aria-label={t(copy.close)} onClick={onClose}>
            <X size={16} aria-hidden="true" />
          </button>
        </header>
        <p className="report-schedule-hint">{t(copy.reportScheduleHint)}</p>
        <form className="report-schedule-form" onSubmit={(event) => void save(event)}>
          {fixedClient ? <strong>{fixedClient.company_name || fixedClient.name}</strong> : (
            <ClientPicker locale={locale} value={client} onChange={setClient} />
          )}
          <label className="field-label">
            {t(copy.reportScheduleKind)}
            <select className="field" value={kind} onChange={(event) => setKind(event.target.value as (typeof KINDS)[number])}>
              {KINDS.map((item) => <option key={item} value={item}>{kindLabel(item, locale)}</option>)}
            </select>
          </label>
          <label className="field-label">
            {t(copy.reportScheduleWhen)}
            <input className="field" type="datetime-local" required value={when} onChange={(event) => setWhen(event.target.value)} />
          </label>
          <label className="field-label">
            {t(copy.reportScheduleCaption)}
            <textarea className="field" rows={2} maxLength={2000} value={caption} onChange={(event) => setCaption(event.target.value)} />
          </label>
          <label className="field-label">
            {t(copy.reportSchedulePicture)}
            <input className="field" type="file" accept="image/*" onChange={(event) => setImage(event.target.files?.[0] ?? null)} />
          </label>
          {slot?.has_image ? <PlanImage slotId={slot.id} /> : null}
          <div className="stack-actions">
            <button type="submit" className="btn btn-primary btn-sm" disabled={busy}>
              {slot ? t(copy.reportScheduleSave) : t(copy.reportScheduleAdd)}
            </button>
            {slot ? <button type="button" className="btn btn-ghost btn-sm" disabled={busy} onClick={() => void remove()}>{t(copy.delete)}</button> : null}
          </div>
        </form>
        {current ? (
          <>
            <label className="field-label">
              {t(copy.reportThreadWhich)}
              <select className="field" value={thread} onChange={(event) => setThread(event.target.value)}>
                {threads.map((item) => <option key={item.key} value={item.key}>{item.label}</option>)}
              </select>
            </label>
            <CommentsPanel key={current.key} subject={current.subject} locale={locale} t={t} compact />
          </>
        ) : (
          <p className="report-schedule-hint">{slot ? t(copy.reportScheduleEmpty) : t(copy.reportSendNeedSave)}</p>
        )}
      </aside>
    </>
  );
}
