import { toast } from "sonner";
import type { UploadProgress } from "../api";
import { copy, type Locale } from "../i18n";

type Translate = (c: { ar: string; en: string }) => string;

export function startUploadToast(t: Translate, locale: Locale) {
  const id = `hoc-upload-${Date.now()}`;
  const started = performance.now();
  let lastPaint = 0;
  let phase: UploadProgress["phase"] = "uploading";

  toast.loading(<UploadToastBody t={t} locale={locale} phase="uploading" percent={0} remaining={null} loaded={0} total={0} />, {
    id,
    duration: Infinity,
    closeButton: false,
  });

  return {
    onProgress(progress: UploadProgress) {
      if (progress.phase === "processing") {
        phase = "processing";
        toast.loading(<UploadToastBody t={t} locale={locale} phase="processing" percent={100} remaining={null} loaded={progress.loaded} total={progress.total} />, {
          id,
          duration: Infinity,
          closeButton: false,
        });
        return;
      }
      if (phase === "processing") return;
      const now = performance.now();
      if (now - lastPaint < 120 && progress.total > 0 && progress.loaded < progress.total) return;
      lastPaint = now;
      const elapsed = (now - started) / 1000;
      const percent = progress.total > 0 ? Math.min(100, Math.round((progress.loaded / progress.total) * 100)) : 0;
      const remaining =
        elapsed >= 0.4 && progress.loaded > 0 && progress.total > progress.loaded
          ? Math.max(1, Math.round((progress.total - progress.loaded) / (progress.loaded / elapsed)))
          : null;
      toast.loading(
        <UploadToastBody t={t} locale={locale} phase="uploading" percent={percent} remaining={remaining} loaded={progress.loaded} total={progress.total} />,
        { id, duration: Infinity, closeButton: false },
      );
    },
    done() {
      const elapsed = (performance.now() - started) / 1000;
      toast.success(t(copy.socialUploadDone).replace("{time}", formatDuration(elapsed, t)), { id });
    },
    fail(message: string) {
      toast.error(message || t(copy.socialUploadFailed), { id });
    },
  };
}

function UploadToastBody({
  t,
  locale,
  phase,
  percent,
  remaining,
  loaded,
  total,
}: {
  t: Translate;
  locale: Locale;
  phase: UploadProgress["phase"];
  percent: number;
  remaining: number | null;
  loaded: number;
  total: number;
}) {
  const title = phase === "processing" ? t(copy.socialUploadProcessing) : t(copy.socialUploading);
  const eta = remaining != null ? t(copy.socialUploadEta).replace("{time}", formatDuration(remaining, t)) : null;
  const size =
    total > 0
      ? t(copy.socialUploadBytes).replace("{loaded}", formatBytes(loaded, locale)).replace("{total}", formatBytes(total, locale))
      : null;

  return (
    <div className="upload-toast" role="status">
      <p className="upload-toast-title">
        {title}
        {phase === "uploading" && total > 0 ? ` ${percent}${locale === "ar" ? "٪" : "%"}` : null}
      </p>
      {phase === "uploading" && (eta || size) ? (
        <p className="upload-toast-meta">
          {eta}
          {eta && size ? " · " : null}
          {size}
        </p>
      ) : null}
      <div
        className={phase === "processing" || total <= 0 ? "upload-toast-bar is-busy" : "upload-toast-bar"}
        role="progressbar"
        aria-valuemin={0}
        aria-valuemax={100}
        aria-valuenow={phase === "uploading" && total > 0 ? percent : undefined}
        aria-label={title}
      >
        <span className="upload-toast-fill" style={total > 0 && phase === "uploading" ? { width: `${percent}%` } : undefined} />
      </div>
    </div>
  );
}

function formatDuration(seconds: number, t: Translate) {
  const whole = Math.max(0, Math.round(seconds));
  if (whole < 1) return t({ ar: "أقل من ثانية", en: "less than a second" });
  if (whole < 60) return t({ ar: `${whole} ث`, en: `${whole}s` });
  const minutes = Math.floor(whole / 60);
  const rest = whole % 60;
  if (rest === 0) return t({ ar: `${minutes} د`, en: `${minutes}m` });
  return t({ ar: `${minutes} د و${rest} ث`, en: `${minutes}m ${rest}s` });
}

function formatBytes(bytes: number, locale: Locale) {
  const mb = bytes / (1024 * 1024);
  if (mb >= 0.1) {
    const value = mb >= 10 ? mb.toFixed(0) : mb.toFixed(1);
    return locale === "ar" ? `${value} م.ب` : `${value} MB`;
  }
  const kb = Math.max(1, Math.round(bytes / 1024));
  return locale === "ar" ? `${kb} ك.ب` : `${kb} KB`;
}
