import { CalendarDays, Clock3, MessageCircle, Send } from "lucide-react";
import { useEffect, useState } from "react";
import { toast } from "sonner";
import { api, type ClientChannels, type WhatsAppWebStatus } from "../api";
import { ConfirmAction } from "../components/ConfirmAction";
import { PageHeader } from "../components/PageHeader";
import { copy, type Locale } from "../i18n";

const defaults: ClientChannels = { telegram_enabled: true, whatsapp_enabled: true };

export function ClientChannelsPage({ locale, t }: { locale: Locale; t: (c: { ar: string; en: string }) => string }) {
  const [channels, setChannels] = useState<ClientChannels>(defaults);
  const [link, setLink] = useState<WhatsAppWebStatus | null>(null);
  const [adminLink, setAdminLink] = useState<WhatsAppWebStatus | null>(null);
  const [error, setError] = useState("");
  const [busy, setBusy] = useState<"telegram" | "whatsapp" | null>(null);
  const [hours, setHours] = useState("8");
  const [openAt, setOpenAt] = useState("21:00");
  const [closeAt, setCloseAt] = useState("09:00");
  const [teamOpen, setTeamOpen] = useState("09:00");
  const [teamClose, setTeamClose] = useState("21:00");
  const [photoLead, setPhotoLead] = useState("7");
  const [holidays, setHolidays] = useState<string[]>([]);
  const [unlinking, setUnlinking] = useState(false);
  const [unlinkingAdmin, setUnlinkingAdmin] = useState(false);

  useEffect(() => {
    api
      .opsSettings()
      .then((res) => {
        setChannels({
          telegram_enabled: res.data.telegram_enabled !== false,
          whatsapp_enabled: res.data.whatsapp_enabled !== false,
          whatsapp_locked: res.data.whatsapp_locked === true,
          whatsapp_transport: res.data.whatsapp_transport,
        });
      })
      .catch((err) => setError(err instanceof Error ? err.message : t(copy.saveFailed)));
    api
      .workCalendar()
      .then((res) => {
        setHours(String(res.data.hours_per_day));
        setOpenAt(res.data.whatsapp_open || "21:00");
        setCloseAt(res.data.whatsapp_close || "09:00");
        setTeamOpen(res.data.team_open || "09:00");
        setTeamClose(res.data.team_close || "21:00");
        setPhotoLead(String(res.data.photography_lead_days ?? 7));
        setHolidays(res.data.holidays);
      })
      .catch(() => undefined);
  }, [t]);

  useEffect(() => {
    if (channels.whatsapp_transport !== "web") {
      return;
    }
    let stop = false;
    const pull = () => {
      api
        .whatsappWebStatus()
        .then((res) => {
          if (!stop) {
            setLink(res.data);
          }
        })
        .catch(() => {
          if (!stop) {
            setLink(null);
          }
        });
    };
    const pullAdmin = () => {
      api
        .whatsappAdminStatus()
        .then((res) => {
          if (!stop) {
            setAdminLink(res.data);
          }
        })
        .catch(() => {
          if (!stop) {
            setAdminLink(null);
          }
        });
    };
    pull();
    pullAdmin();
    const timer = window.setInterval(() => {
      pull();
      pullAdmin();
    }, 4000);
    return () => {
      stop = true;
      window.clearInterval(timer);
    };
  }, [channels.whatsapp_transport]);

  async function saveCalendar() {
    setError("");
    try {
      const res = await api.saveWorkCalendar({
        hours_per_day: Number(hours) || 8,
        holidays,
        whatsapp_open: openAt,
        whatsapp_close: closeAt,
        team_open: teamOpen,
        team_close: teamClose,
        photography_lead_days: Math.max(0, Math.min(90, Number(photoLead) || 0)),
      });
      setHours(String(res.data.hours_per_day));
      setOpenAt(res.data.whatsapp_open);
      setCloseAt(res.data.whatsapp_close);
      setTeamOpen(res.data.team_open);
      setTeamClose(res.data.team_close);
      setPhotoLead(String(res.data.photography_lead_days));
      setHolidays(res.data.holidays);
      toast.success(t(copy.channelsSaved));
    } catch (err) {
      setError(err instanceof Error ? err.message : t(copy.saveFailed));
    }
  }

  function toggleHoliday(iso: string) {
    setHolidays((current) => (current.includes(iso) ? current.filter((day) => day !== iso) : [...current, iso].sort()));
  }

  async function changeNumber() {
    setUnlinking(true);
    setError("");
    try {
      const res = await api.unlinkWhatsappWeb();
      setLink(res.data);
      toast.success(t(copy.channelsSaved));
    } catch (err) {
      setError(err instanceof Error ? err.message : t(copy.saveFailed));
    } finally {
      setUnlinking(false);
    }
  }

  async function changeAdminNumber() {
    setUnlinkingAdmin(true);
    setError("");
    try {
      const res = await api.unlinkWhatsappAdmin();
      setAdminLink(res.data);
      toast.success(t(copy.channelsSaved));
    } catch (err) {
      setError(err instanceof Error ? err.message : t(copy.saveFailed));
    } finally {
      setUnlinkingAdmin(false);
    }
  }

  async function save(next: ClientChannels, which: "telegram" | "whatsapp") {
    setBusy(which);
    setError("");
    try {
      const res = await api.updateClientChannels(next);
      setChannels(res.data);
      toast.success(t(copy.channelsSaved));
    } catch (err) {
      setError(err instanceof Error ? err.message : t(copy.saveFailed));
    } finally {
      setBusy(null);
    }
  }

  return (
    <>
      <PageHeader title={t(copy.channelsTitle)} lede={t(copy.channelsLede)} />
      {error ? <p className="error">{error}</p> : null}
      <div className="channels-board">
        <div className="channel-studio">
          <ChannelCard
            kind="telegram"
            title={t(copy.channelsTelegram)}
            help={t(copy.channelsTelegramHelp)}
            running={channels.telegram_enabled}
            busy={busy === "telegram"}
            t={t}
            onPause={() => void save({ ...channels, telegram_enabled: false }, "telegram")}
            onResume={() => void save({ ...channels, telegram_enabled: true }, "telegram")}
          />
          <ChannelCard
            kind="whatsapp"
            title={t(copy.channelsWhatsapp)}
            help={t(channels.whatsapp_locked ? copy.channelsWhatsappLockedHelp : copy.channelsWhatsappHelp)}
            running={channels.whatsapp_enabled}
            locked={channels.whatsapp_locked === true}
            busy={busy === "whatsapp"}
            t={t}
            onPause={() => void save({ ...channels, whatsapp_enabled: false }, "whatsapp")}
            onResume={() => void save({ ...channels, whatsapp_enabled: true }, "whatsapp")}
          />
        </div>
        {channels.whatsapp_transport === "web" ? (
          <section className="card channels-link">
            {link?.connected ? (
              <div className="channels-link-row">
                <div>
                  <p className="channels-link-kicker">{t(copy.channelsWhatsappPhone)}</p>
                  {link.phone ? <p className="channels-phone" dir="ltr">+{link.phone}</p> : null}
                  <p className="muted">{t(copy.channelsWhatsappLinked)}</p>
                </div>
                <ConfirmAction
                  label={t(copy.channelsWhatsappChange)}
                  confirmLabel={t(copy.channelsWhatsappChangeConfirm)}
                  yesLabel={t(copy.channelsWhatsappChange)}
                  noLabel={t(copy.cancel)}
                  disabled={unlinking}
                  onConfirm={() => void changeNumber()}
                />
              </div>
            ) : link && !link.reachable ? (
              <p className="error">{t(copy.channelsWhatsappOffline)}</p>
            ) : link?.qr ? (
              <div className="channels-link-scan">
                <p>{t(copy.channelsWhatsappScan)}</p>
                <img className="wa-link-qr" alt="" src={link.qr} />
              </div>
            ) : (
              <p className="muted">{t(copy.channelsWhatsappScan)}</p>
            )}
          </section>
        ) : null}
        {channels.whatsapp_transport === "web" ? (
          <section className="card channels-link">
            <p className="channels-link-kicker">{t(copy.channelsAdminWhatsapp)}</p>
            {adminLink?.connected ? (
              <div className="channels-link-row">
                <div>
                  {adminLink.phone ? <p className="channels-phone" dir="ltr">+{adminLink.phone}</p> : null}
                  <p className="muted">{t(copy.channelsAdminWhatsappLinked)}</p>
                </div>
                <ConfirmAction
                  label={t(copy.channelsWhatsappChange)}
                  confirmLabel={t(copy.channelsWhatsappChangeConfirm)}
                  yesLabel={t(copy.channelsWhatsappChange)}
                  noLabel={t(copy.cancel)}
                  disabled={unlinkingAdmin}
                  onConfirm={() => void changeAdminNumber()}
                />
              </div>
            ) : adminLink && !adminLink.reachable ? (
              <p className="error">{t(copy.channelsWhatsappOffline)}</p>
            ) : adminLink?.qr ? (
              <div className="channels-link-scan">
                <p>{t(copy.channelsAdminWhatsappScan)}</p>
                <img className="wa-link-qr" alt="" src={adminLink.qr} />
              </div>
            ) : (
              <p className="muted">{t(copy.channelsAdminWhatsappScan)}</p>
            )}
          </section>
        ) : null}
        <form
          className="card channels-schedule"
          onSubmit={(event) => {
            event.preventDefault();
            void saveCalendar();
          }}
        >
          <div className="channels-metrics">
            <label className="field-label">
              {t(copy.channelsHours)}
              <input className="field" value={hours} onChange={(event) => setHours(event.target.value)} inputMode="numeric" />
            </label>
            <label className="field-label">
              {t(copy.channelsPhotoLead)}
              <input className="field" value={photoLead} onChange={(event) => setPhotoLead(event.target.value)} inputMode="numeric" min={0} max={90} />
              <span className="muted">{t(copy.channelsPhotoLeadHint)}</span>
            </label>
          </div>
          <div className="channels-windows">
            <fieldset className="channels-window">
              <legend>
                <Clock3 size={16} aria-hidden />
                {t(copy.channelsTeamHours)}
              </legend>
              <div className="channels-times">
                <label>
                  {t(copy.channelsWhatsappOpen)}
                  <input className="field" type="time" value={teamOpen} onChange={(event) => setTeamOpen(event.target.value)} />
                </label>
                <label>
                  {t(copy.channelsWhatsappClose)}
                  <input className="field" type="time" value={teamClose} onChange={(event) => setTeamClose(event.target.value)} />
                </label>
              </div>
            </fieldset>
            <fieldset className="channels-window">
              <legend>
                <Clock3 size={16} aria-hidden />
                {t(copy.channelsWhatsappHours)}
              </legend>
              <div className="channels-times">
                <label>
                  {t(copy.channelsWhatsappOpen)}
                  <input className="field" type="time" value={openAt} onChange={(event) => setOpenAt(event.target.value)} />
                </label>
                <label>
                  {t(copy.channelsWhatsappClose)}
                  <input className="field" type="time" value={closeAt} onChange={(event) => setCloseAt(event.target.value)} />
                </label>
              </div>
            </fieldset>
          </div>
          <fieldset className="channels-calendar">
            <legend>
              <CalendarDays size={16} aria-hidden />
              {t(copy.channelsHolidays)}
            </legend>
            <HolidayMonth locale={locale} dates={holidays} onToggle={toggleHoliday} t={t} />
          </fieldset>
          <div className="channels-save">
            <button className="btn btn-teal" type="submit">
              {t(copy.channelsSaveCalendar)}
            </button>
          </div>
        </form>
      </div>
    </>
  );
}

function HolidayMonth({
  locale,
  dates,
  onToggle,
  t,
}: {
  locale: Locale;
  dates: string[];
  onToggle: (iso: string) => void;
  t: (c: { ar: string; en: string }) => string;
}) {
  const [cursor, setCursor] = useState(() => new Date());
  const year = cursor.getFullYear();
  const month = cursor.getMonth();
  const selected = new Set(dates);
  const first = new Date(year, month, 1);
  const offset = (first.getDay() + 1) % 7;
  const count = new Date(year, month + 1, 0).getDate();
  const cells = [...Array<number | null>(offset).fill(null), ...Array.from({ length: count }, (_, index) => index + 1)];
  const language = locale === "ar" ? "ar" : "en";
  const title = first.toLocaleDateString(language, { month: "long", year: "numeric" });
  const weekdays = locale === "ar"
    ? ["س", "ح", "ن", "ث", "ر", "خ", "ج"]
    : ["Sa", "Su", "Mo", "Tu", "We", "Th", "Fr"];

  return (
    <div className="holiday-month">
      <div className="holiday-month-nav">
        <button type="button" className="btn btn-ghost" onClick={() => setCursor(new Date(year, month - 1, 1))}>
          {t(copy.channelsPrevMonth)}
        </button>
        <p className="channels-month-title">{title}</p>
        <button type="button" className="btn btn-ghost" onClick={() => setCursor(new Date(year, month + 1, 1))}>
          {t(copy.channelsNextMonth)}
        </button>
      </div>
      <div className="holiday-week" aria-hidden="true">
        {weekdays.map((day) => <span key={day}>{day}</span>)}
      </div>
      <div className="holiday-grid">
        {cells.map((day, index) => {
          if (day === null) {
            return <span key={`empty-${index}`} />;
          }
          const iso = `${year}-${String(month + 1).padStart(2, "0")}-${String(day).padStart(2, "0")}`;
          const friday = new Date(year, month, day).getDay() === 5;
          const on = selected.has(iso);
          return (
            <button
              key={iso}
              type="button"
              className={on ? "holiday-day is-off" : friday ? "holiday-day is-friday" : "holiday-day"}
              aria-pressed={on}
              onClick={() => onToggle(iso)}
            >
              {day}
            </button>
          );
        })}
      </div>
    </div>
  );
}

function ChannelCard({
  kind,
  title,
  help,
  running,
  locked = false,
  busy,
  t,
  onPause,
  onResume,
}: {
  kind: "telegram" | "whatsapp";
  title: string;
  help: string;
  running: boolean;
  locked?: boolean;
  busy: boolean;
  t: (c: { ar: string; en: string }) => string;
  onPause: () => void;
  onResume: () => void;
}) {
  const Icon = kind === "telegram" ? Send : MessageCircle;
  return (
    <section className={running ? `card channel-card is-${kind}` : `card channel-card is-${kind} is-paused`}>
      <div className="channel-card-head">
        <span className="channel-mark" aria-hidden>
          <Icon size={18} />
        </span>
        <div>
          <h2 className="form-title">{title}</h2>
          <span className={running ? "channel-status is-on" : "channel-status is-off"}>
            {locked ? t(copy.channelsLocked) : running ? t(copy.channelsRunning) : t(copy.channelsPaused)}
          </span>
        </div>
      </div>
      <p className="muted">{help}</p>
      {locked ? null : running ? (
        <ConfirmAction
          label={t(copy.channelsPause)}
          confirmLabel={t(copy.channelsPauseConfirm)}
          yesLabel={t(copy.channelsPause)}
          noLabel={t(copy.cancel)}
          disabled={busy}
          onConfirm={onPause}
        />
      ) : (
        <button type="button" className="btn" disabled={busy} onClick={onResume}>
          {t(copy.channelsResume)}
        </button>
      )}
    </section>
  );
}
