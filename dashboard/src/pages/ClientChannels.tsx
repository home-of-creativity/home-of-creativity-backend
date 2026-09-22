import { useEffect, useState } from "react";
import { toast } from "sonner";
import { api, type ClientChannels } from "../api";
import { ConfirmAction } from "../components/ConfirmAction";
import { PageHeader } from "../components/PageHeader";
import { copy, type Locale } from "../i18n";

const defaults: ClientChannels = { telegram_enabled: true, whatsapp_enabled: true };

export function ClientChannelsPage({ t }: { locale: Locale; t: (c: { ar: string; en: string }) => string }) {
  const [channels, setChannels] = useState<ClientChannels>(defaults);
  const [error, setError] = useState("");
  const [busy, setBusy] = useState<"telegram" | "whatsapp" | null>(null);

  useEffect(() => {
    api
      .opsSettings()
      .then((res) => {
        setChannels({
          telegram_enabled: res.data.telegram_enabled !== false,
          whatsapp_enabled: res.data.whatsapp_enabled !== false,
        });
      })
      .catch((err) => setError(err instanceof Error ? err.message : t(copy.saveFailed)));
  }, [t]);

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
          help={t(copy.channelsWhatsappHelp)}
          running={channels.whatsapp_enabled}
          busy={busy === "whatsapp"}
          t={t}
          onPause={() => void save({ ...channels, whatsapp_enabled: false }, "whatsapp")}
          onResume={() => void save({ ...channels, whatsapp_enabled: true }, "whatsapp")}
        />
      </div>
    </>
  );
}

function ChannelCard({
  title,
  help,
  running,
  busy,
  t,
  onPause,
  onResume,
}: {
  title: string;
  help: string;
  running: boolean;
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
          {running ? t(copy.channelsRunning) : t(copy.channelsPaused)}
        </span>
      </div>
      <p className="muted">{help}</p>
      {running ? (
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
