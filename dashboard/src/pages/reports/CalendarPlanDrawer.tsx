import { useEffect, useState, type FormEvent } from "react";
import { X } from "lucide-react";
import { toast } from "sonner";
import { api, type ReportCalendar, type ReportSlot } from "../../api";
import { CommentsPanel } from "../../components/CommentsPanel";
import { copy, type Locale } from "../../i18n";
import { GOOGLE_COLORS } from "../photography/model";
import { ClientPicker } from "./DeadlineDialog";

const KINDS = ["carousel", "post", "reel"] as const;

type ClientPick = { id: number; name: string; company_name: string | null; logo_url?: string | null };

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
  if (value === "carousel") return copy.reportScheduleImage[locale];
  if (value === "reel") return copy.reportScheduleReel[locale];
  return copy.reportSchedulePost[locale];
}

function PlanImage({ slotId, imageId }: { slotId: number; imageId: number }) {
  const [url, setUrl] = useState<string | null>(null);
  useEffect(() => {
    let gone = false;
    let objectUrl = "";
    void api.reportSlotImage(slotId, imageId).then((blob) => {
      objectUrl = URL.createObjectURL(blob);
      if (!gone) setUrl(objectUrl);
    }).catch(() => undefined);
    return () => {
      gone = true;
      if (objectUrl) URL.revokeObjectURL(objectUrl);
    };
  }, [slotId, imageId]);
  if (!url) return null;
  return <img className="report-slot-thumb" src={url} alt="" />;
}

/**
 * Side panel on the reports calendar: plan a picture, post, or reel on one calendar.
 * Saving never publishes.
 */
export function CalendarPlanDrawer({
  locale,
  slot,
  at,
  fixedClient,
  calendars,
  mainVisible,
  onClose,
  onSaved,
  onDeleted,
}: {
  locale: Locale;
  slot: ReportSlot | null;
  at: string;
  fixedClient: ClientPick | null;
  calendars: ReportCalendar[];
  mainVisible: boolean;
  onClose: () => void;
  onSaved: (slot: ReportSlot) => void;
  onDeleted: () => void;
}) {
  const t = (item: { ar: string; en: string }) => item[locale];
  const firstCalendar = mainVisible ? null : calendars[0]?.id ?? null;
  const [client, setClient] = useState<ClientPick | null>(fixedClient ?? (slot ? { id: slot.client_id, name: slot.client_name, company_name: null, logo_url: slot.logo_url } : null));
  const [calendarId, setCalendarId] = useState<number | null>(slot?.calendar_id ?? firstCalendar);
  const [kind, setKind] = useState<(typeof KINDS)[number]>(slot?.kind ?? "carousel");
  const [when, setWhen] = useState(localValue(slot?.scheduled_at ?? at));
  const [caption, setCaption] = useState(slot?.caption ?? "");
  const [color, setColor] = useState(slot?.color ?? calendars.find((item) => item.id === (slot?.calendar_id ?? firstCalendar))?.color ?? GOOGLE_COLORS[0].hex);
  const [pictures, setPictures] = useState<File[]>([]);
  const [removed, setRemoved] = useState<number[]>([]);
  const [logo, setLogo] = useState<File | null>(null);
  const [logoPreview, setLogoPreview] = useState<string | null>(slot?.logo_url ?? null);
  const [busy, setBusy] = useState(false);

  useEffect(() => {
    const onKey = (event: KeyboardEvent) => {
      if (event.key === "Escape") onClose();
    };
    window.addEventListener("keydown", onKey);
    return () => window.removeEventListener("keydown", onKey);
  }, [onClose]);

  useEffect(() => {
    if (logo) {
      const url = URL.createObjectURL(logo);
      setLogoPreview(url);
      return () => URL.revokeObjectURL(url);
    }
    if (client?.logo_url) {
      setLogoPreview(client.logo_url);
      return;
    }
    if (!client) {
      setLogoPreview(null);
      return;
    }
    let gone = false;
    void api.clients(1, { id: client.id, per_page: 1 }).then((res) => {
      if (!gone) setLogoPreview(res.data[0]?.logo_url ?? null);
    }).catch(() => undefined);
    return () => {
      gone = true;
    };
  }, [logo, client]);

  function pickCalendar(id: number | null) {
    setCalendarId(id);
    const next = id == null ? color : calendars.find((item) => item.id === id)?.color;
    if (next) setColor(next);
  }

  async function save(event: FormEvent) {
    event.preventDefault();
    if (!client || busy) {
      if (!client) toast.error(locale === "ar" ? "اختر الزبون." : "Choose the client.");
      return;
    }
    const form = new FormData();
    form.set("client_id", String(client.id));
    if (calendarId != null) form.set("calendar_id", String(calendarId));
    form.set("kind", kind);
    form.set("color", color);
    form.set("scheduled_at", when);
    form.set("caption", caption);
    for (const file of pictures) form.append("images[]", file);
    for (const id of removed) form.append("remove_image_ids[]", String(id));
    if (logo) form.set("logo", logo);
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

  const kept = (slot?.images ?? []).filter((image) => !removed.includes(image.id));

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
            <ClientPicker locale={locale} value={client} onChange={(next) => { setLogo(null); setClient(next); }} />
          )}
          <label className="field-label">
            {t(copy.reportScheduleCalendar)}
            <select
              className="field"
              value={calendarId ?? ""}
              onChange={(event) => pickCalendar(event.target.value ? Number(event.target.value) : null)}
            >
              {mainVisible ? <option value="">{t(copy.reportScheduleMain)}</option> : null}
              {calendars.map((item) => <option key={item.id} value={item.id}>{item.name}</option>)}
            </select>
          </label>
          <div className="field-label">
            {t(copy.reportScheduleColor)}
            <div className="gcal-swatches" role="radiogroup" aria-label={t(copy.reportScheduleColor)}>
              {GOOGLE_COLORS.map((item) => (
                <button
                  key={item.id}
                  type="button"
                  role="radio"
                  aria-checked={color === item.hex}
                  className={color === item.hex ? "is-on" : ""}
                  style={{ background: item.hex }}
                  title={item.name[locale]}
                  onClick={() => setColor(item.hex)}
                />
              ))}
            </div>
          </div>
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
            <input
              className="field"
              type="file"
              accept="image/*"
              multiple
              onChange={(event) => setPictures(Array.from(event.target.files ?? []))}
            />
          </label>
          {kept.length > 0 ? (
            <div className="report-slot-photos">
              {kept.map((image) => (
                <figure key={image.id} className="report-slot-photo">
                  {slot ? <PlanImage slotId={slot.id} imageId={image.id} /> : null}
                  <button type="button" className="btn btn-ghost btn-sm" onClick={() => setRemoved((ids) => [...ids, image.id])}>
                    {t(copy.delete)}
                  </button>
                </figure>
              ))}
            </div>
          ) : null}
          <label className="field-label">
            {t(copy.reportScheduleLogo)}
            <input className="field" type="file" accept="image/*" onChange={(event) => setLogo(event.target.files?.[0] ?? null)} />
          </label>
          {logoPreview ? <img className="report-slot-logo" src={logoPreview} alt="" /> : null}
          <div className="stack-actions">
            <button type="submit" className="btn btn-primary btn-sm" disabled={busy}>
              {slot ? t(copy.reportScheduleSave) : t(copy.reportScheduleAdd)}
            </button>
            {slot ? <button type="button" className="btn btn-ghost btn-sm" disabled={busy} onClick={() => void remove()}>{t(copy.delete)}</button> : null}
          </div>
        </form>
        {slot ? (
          <CommentsPanel subject={{ type: "report_slot", id: String(slot.id) }} locale={locale} t={t} compact />
        ) : (
          <p className="report-schedule-hint">{t(copy.reportSendNeedSave)}</p>
        )}
      </aside>
    </>
  );
}
