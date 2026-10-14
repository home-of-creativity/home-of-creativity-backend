import { ArrowLeftRight, MessageSquare, StickyNote, X } from "lucide-react";
import { useEffect, useState, type FormEvent } from "react";
import { toast } from "sonner";
import { api, type OdooPaperKind, type OdooPaperMessage } from "../api";
import { copy } from "../i18n";

function when(date: string | null) {
  if (!date) return "";
  // Odoo answers in UTC without a zone.
  const parsed = new Date(`${date.replace(" ", "T")}Z`);
  if (Number.isNaN(parsed.getTime())) return date;
  const lang = document.documentElement.lang === "en" ? "en-GB" : "ar-SY";
  return new Intl.DateTimeFormat(lang, { dateStyle: "medium", timeStyle: "short" }).format(parsed);
}

/** The Odoo chatter of a quotation or invoice, and a box for an internal note. */
export function OdooPaperActivity({
  kind,
  id,
  name,
  canNote,
  t,
  onClose,
}: {
  kind: OdooPaperKind;
  id: number;
  name: string;
  canNote: boolean;
  t: (c: { ar: string; en: string }) => string;
  onClose: () => void;
}) {
  const [messages, setMessages] = useState<OdooPaperMessage[] | null>(null);
  const [error, setError] = useState("");
  const [note, setNote] = useState("");
  const [sending, setSending] = useState(false);

  useEffect(() => {
    let cancelled = false;
    api.odooPaperActivity(kind, id)
      .then((res) => {
        if (!cancelled) setMessages(res.data);
      })
      .catch((err) => {
        if (!cancelled) setError(err instanceof Error ? err.message : String(err));
      });
    return () => {
      cancelled = true;
    };
  }, [kind, id]);

  useEffect(() => {
    const onKey = (event: KeyboardEvent) => {
      if (event.key === "Escape") onClose();
    };
    window.addEventListener("keydown", onKey);
    return () => window.removeEventListener("keydown", onKey);
  }, [onClose]);

  async function send(event: FormEvent) {
    event.preventDefault();
    if (!note.trim()) return;
    setSending(true);
    try {
      const res = await api.odooPaperNote(kind, id, note.trim());
      setMessages(res.data);
      setNote("");
      toast.success(t(copy.paperNoteSent));
    } catch (err) {
      toast.error(err instanceof Error ? err.message : String(err));
    } finally {
      setSending(false);
    }
  }

  return (
    <div className="form-dialog" role="dialog" aria-modal="true" aria-labelledby="odoo-activity-title">
      <div className="form-dialog-backdrop" onClick={onClose} />
      <aside className="form-dialog-panel odoo-activity-panel">
        <div className="form-dialog-head">
          <h2 className="form-dialog-title" id="odoo-activity-title">{t(copy.paperActivity)} {name}</h2>
          <button type="button" className="form-dialog-close" onClick={onClose} aria-label={t(copy.cancel)}>
            <X aria-hidden />
          </button>
        </div>
        <div className="odoo-activity-body">
          {canNote ? (
            <form className="odoo-activity-note" onSubmit={(event) => void send(event)}>
              <label className="field-label">
                {t(copy.paperNoteLabel)}
                <textarea
                  className="field"
                  rows={3}
                  maxLength={4000}
                  value={note}
                  placeholder={t(copy.paperNotePlaceholder)}
                  onChange={(event) => setNote(event.target.value)}
                  onKeyDown={(event) => {
                    if (event.key === "Enter" && (event.ctrlKey || event.metaKey)) void send(event);
                  }}
                />
              </label>
              <button type="submit" className="btn btn-primary" disabled={sending || !note.trim()} aria-busy={sending}>
                {t(copy.paperNoteSend)}
              </button>
            </form>
          ) : null}

          {error ? <p className="error">{error}</p> : messages === null ? (
            <p className="muted">{t(copy.paperActivityLoading)}</p>
          ) : messages.length === 0 ? (
            <p className="muted">{t(copy.paperActivityEmpty)}</p>
          ) : (
            <ol className="odoo-activity-list">
              {messages.map((item) => (
                <li key={item.id} className={`odoo-activity-item is-${item.kind}`}>
                  <span className="odoo-activity-icon" aria-hidden>
                    {item.kind === "note" ? <StickyNote size={15} /> : item.kind === "change" ? <ArrowLeftRight size={15} /> : <MessageSquare size={15} />}
                  </span>
                  <div className="odoo-activity-main">
                    <div className="odoo-activity-meta">
                      <strong>{item.author ?? "Odoo"}</strong>
                      <span className="muted">{when(item.date)}</span>
                      {item.kind === "note" ? <span className="odoo-activity-badge">{t(copy.paperNoteBadge)}</span> : <span className="muted">{item.subtype || t(copy.paperMessageBadge)}</span>}
                    </div>
                    {item.body ? <div className="odoo-activity-text" dangerouslySetInnerHTML={{ __html: item.body }} /> : null}
                    {item.changes.length > 0 ? (
                      <ul className="odoo-activity-changes">
                        {item.changes.map((change, index) => (
                          <li key={index}>
                            <span className="muted">{change.field || t(copy.paperChanged)}:</span>{" "}
                            <span className="odoo-activity-old">{change.old || "-"}</span>
                            <ArrowLeftRight size={12} aria-hidden />
                            <strong>{change.new || "-"}</strong>
                          </li>
                        ))}
                      </ul>
                    ) : null}
                  </div>
                </li>
              ))}
            </ol>
          )}
        </div>
      </aside>
    </div>
  );
}
