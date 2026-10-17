import { useCallback, useEffect, useMemo, useState } from "react";
import { api, type BotFeedbackRow } from "../api";
import { LoadingLottie } from "../components/LoadingLottie";
import { PageHeader } from "../components/PageHeader";
import { copy, type Locale } from "../i18n";

const SOURCE_COPY: Record<string, { ar: string; en: string }> = {
  support_agent: { ar: "مساعد الدعم", en: "Support agent" },
  human_hold: { ar: "بعد الموظف", en: "After staff hold" },
};

function when(iso: string | null, locale: Locale) {
  if (!iso) return "";
  const date = new Date(iso);
  if (Number.isNaN(date.getTime())) return iso.replace("T", " ").slice(0, 16);
  return new Intl.DateTimeFormat(locale === "ar" ? "ar-SY-u-nu-latn" : "en-GB", {
    timeZone: "Asia/Damascus",
    day: "numeric",
    month: "short",
    hour: "2-digit",
    minute: "2-digit",
    hourCycle: "h23",
  }).format(date);
}

export function BotFeedbackPage({ locale, t }: { locale: Locale; t: (c: { ar: string; en: string }) => string }) {
  const ar = locale === "ar";
  const [rows, setRows] = useState<BotFeedbackRow[] | null>(null);
  const [error, setError] = useState("");

  const load = useCallback(async () => {
    const res = await api.botFeedback();
    setRows(res.data);
  }, []);

  useEffect(() => {
    load().catch((err) => setError(err instanceof Error ? err.message : t(copy.saveFailed)));
  }, [load, t]);

  const average = useMemo(() => {
    if (!rows?.length) return null;
    const sum = rows.reduce((total, row) => total + (row.score ?? 0), 0);
    return (sum / rows.length).toFixed(1);
  }, [rows]);

  return (
    <section>
      <PageHeader title={t(copy.navBotFeedback)} lede={t(copy.botFeedbackLede)} />
      {error ? <p className="form-error">{error}</p> : null}
      {rows === null && !error ? <LoadingLottie variant="page" label={t(copy.loading)} /> : null}
      {rows ? (
        <>
          <p className="muted" style={{ marginBottom: "1rem" }}>
            {ar ? `عدد التقييمات: ${rows.length}` : `Ratings: ${rows.length}`}
            {average ? (ar ? ` · المتوسط: ${average} من 5` : ` · Average: ${average} / 5`) : null}
          </p>
          {rows.length === 0 ? (
            <p className="empty-state">{ar ? "ما في تقييمات للبوت بعد." : "No bot feedback yet."}</p>
          ) : (
            <div className="table-wrap">
              <table className="data-table">
                <thead>
                  <tr>
                    <th>{ar ? "التقييم" : "Score"}</th>
                    <th>{ar ? "السبب" : "Reason"}</th>
                    <th>{ar ? "المصدر" : "Source"}</th>
                    <th>{ar ? "العميل" : "Client"}</th>
                    <th>{ar ? "متى" : "When"}</th>
                  </tr>
                </thead>
                <tbody>
                  {rows.map((row) => (
                    <tr key={row.id}>
                      <td>
                        <span className={row.score !== null && row.score <= 3 ? "status status-partial" : "status status-paid"}>
                          {row.score}/5
                        </span>
                      </td>
                      <td>{row.reason_label ?? "—"}</td>
                      <td>{SOURCE_COPY[row.source]?.[locale] ?? row.source}</td>
                      <td>
                        <div>{row.company_name || row.client_name || "—"}</div>
                        {row.phone ? <div className="muted" dir="ltr">{row.phone}</div> : null}
                      </td>
                      <td>{when(row.answered_at, locale)}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </>
      ) : null}
    </section>
  );
}
