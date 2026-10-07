import { useEffect, useState } from "react";
import { toast } from "sonner";
import { api, type ClientChannels, type WhatsAppWebStatus } from "../api";
import { ConfirmAction } from "../components/ConfirmAction";
import { PageHeader } from "../components/PageHeader";
import { copy, type Locale } from "../i18n";

const defaults: ClientChannels = { telegram_enabled: true, whatsapp_enabled: true };

export function ClientChannelsPage({ t }: { locale: Locale; t: (c: { ar: string; en: string }) => string }) {
  const [channels, setChannels] = useState<ClientChannels>(defaults);
  const [link, setLink] = useState<WhatsAppWebStatus | null>(null);
  const [error, setError] = useState("");
  const [busy, setBusy] = useState<"telegram" | "whatsapp" | null>(null);
  const [hours, setHours] = useState("8");
  const [holidays, setHolidays] = useState("");

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
        setHolidays(res.data.holidays.join("\n"));
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
    pull();
    const timer = window.setInterval(pull, 4000);
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
        holidays: holidays
          .split(/\n/)
          .map((row) => row.trim())
          .filter(Boolean),
      });
      setHours(String(res.data.hours_per_day));
      setHolidays(res.data.holidays.join("\n"));
      toast.success(t(copy.channelsSaved));
    } catch (err) {
      setError(err instanceof Error ? err.message : t(copy.saveFailed));
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
      <div className="channel-studio">
        <ChannelCard
          title={t(copy.channelsTelegram)}
          help={t(copy.channelsTelegramHelp)}
          running={channels.telegram_enabled}
          busy={busy === "telegram"}
          t={t}
          onPause={() => void save({ ...channels, telegram_enabled: false }, "telegram")}
          onResume={() => void save({ ...channels, telegram_enabled: true }, "telegram")}
        />
        <ChannelCard
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
        <section className="card stack">
          {link?.connected ? (
            <p>{t(copy.channelsWhatsappLinked)}</p>
          ) : link && !link.reachable ? (
            <p className="error">{t(copy.channelsWhatsappOffline)}</p>
          ) : link?.qr ? (
            <>
              <p>{t(copy.channelsWhatsappScan)}</p>
              <img className="wa-link-qr" alt="" src={link.qr} />
            </>
          ) : (
            <p className="muted">{t(copy.channelsWhatsappScan)}</p>
          )}
        </section>
      ) : null}
      <form
        className="form-grid"
        onSubmit={(event) => {
          event.preventDefault();
          void saveCalendar();
        }}
      >
        <label className="field-label">
          ساعات يوم العمل
          <input className="field" value={hours} onChange={(event) => setHours(event.target.value)} />
        </label>
        <label className="field-label">
          أيام العطل (YYYY-MM-DD)
          <textarea className="field" rows={4} value={holidays} onChange={(event) => setHolidays(event.target.value)} />
        </label>
        <button className="btn btn-teal" type="submit">
          حفظ التقويم
        </button>
      </form>
    </>
  );
}

function ChannelCard({
  title,
  help,
  running,
  locked = false,
  busy,
  t,
  onPause,
  onResume,
}: {
  title: string;
  help: string;
  running: boolean;
  locked?: boolean;
  busy: boolean;
  t: (c: { ar: string; en: string }) => string;
  onPause: () => void;
  onResume: () => void;
}) {
  return (
    <section className={running ? "card stack channel-card" : "card stack channel-card is-paused"}>
      <div className="channel-card-head">
        <h2 className="form-title">{title}</h2>
        <span className={running ? "channel-status is-on" : "channel-status is-off"}>
          {locked ? t(copy.channelsLocked) : running ? t(copy.channelsRunning) : t(copy.channelsPaused)}
        </span>
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
