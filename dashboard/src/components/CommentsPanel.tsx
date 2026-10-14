import { useCallback, useEffect, useRef, useState, type KeyboardEvent } from "react";
import { Image as ImageIcon, Mic, MessageSquare, Paperclip, Pencil, Reply, Send, Square, X } from "lucide-react";
import { toast } from "sonner";
import { api, type CommentSubject, type TeamComment } from "../api";
import { copy, type Locale } from "../i18n";
import { CommentAttachment } from "./CommentAttachment";
import { ConfirmAction } from "./ConfirmAction";

const REFRESH_MS = 30_000;
const MAX_ATTACHMENT_BYTES = 15 * 1024 * 1024;
/** Long voice notes stop on their own so the file stays under the upload limit. */
const MAX_RECORDING_SECONDS = 300;

function fill(template: string, values: Record<string, string | number>) {
  return template.replace(/\{(\w+)\}/g, (_, key: string) => String(values[key] ?? ""));
}

function initials(name: string) {
  const parts = name.trim().split(/\s+/).filter(Boolean);
  return (parts[0]?.[0] ?? "؟") + (parts[1]?.[0] ?? "");
}

function when(value: string | null, locale: Locale) {
  if (!value) return "";
  const seconds = Math.round((new Date(value).getTime() - Date.now()) / 1000);
  const format = new Intl.RelativeTimeFormat(locale === "ar" ? "ar" : "en", { numeric: "auto" });
  const steps: Array<[number, Intl.RelativeTimeFormatUnit]> = [[60, "second"], [3600, "minute"], [86400, "hour"], [604800, "day"]];
  for (const [limit, unit] of steps) {
    if (Math.abs(seconds) < limit) {
      const size = unit === "second" ? 1 : unit === "minute" ? 60 : unit === "hour" ? 3600 : 86400;
      return format.format(Math.round(seconds / size), unit);
    }
  }
  return new Date(value).toLocaleDateString(locale === "ar" ? "ar-SA-u-nu-latn" : "en-GB", { dateStyle: "medium" });
}

/**
 * Team comments on one thing (report, post, calendar event, booking). The server decides
 * who may read and write; a 403 shows as a quiet note.
 */
export function CommentsPanel({
  subject,
  locale,
  t,
  compact = false,
  onCount,
}: {
  subject: CommentSubject;
  locale: Locale;
  t: (c: { ar: string; en: string }) => string;
  compact?: boolean;
  onCount?: (count: number) => void;
}) {
  const [rows, setRows] = useState<TeamComment[]>([]);
  const [state, setState] = useState<"loading" | "ready" | "denied" | "error">("loading");
  const [body, setBody] = useState("");
  const [replyTo, setReplyTo] = useState<TeamComment | null>(null);
  const [editing, setEditing] = useState<{ id: number; body: string } | null>(null);
  const [busy, setBusy] = useState(false);
  const [attachment, setAttachment] = useState<File | null>(null);
  const [recording, setRecording] = useState<number | null>(null);
  const box = useRef<HTMLTextAreaElement>(null);
  const picker = useRef<HTMLInputElement>(null);
  const recorder = useRef<MediaRecorder | null>(null);
  const recordTimer = useRef<number | null>(null);
  const key = `${subject.type}:${subject.calendarId ?? ""}:${subject.id}`;
  const countRef = useRef(onCount);
  countRef.current = onCount;

  const load = useCallback(async (quiet: boolean) => {
    try {
      const res = await api.comments(subject);
      setRows(res.data);
      setState("ready");
      countRef.current?.(res.data.length);
    } catch (err) {
      if (quiet) return;
      const message = err instanceof Error ? err.message : "";
      setState(/403|صلاحية|forbidden/i.test(message) ? "denied" : "error");
    }
    // `key` stands for every field of the subject.
  }, [key]);

  useEffect(() => {
    setState("loading");
    setRows([]);
    setReplyTo(null);
    setEditing(null);
    void load(false);
    const timer = window.setInterval(() => {
      if (document.visibilityState === "visible") void load(true);
    }, REFRESH_MS);
    return () => window.clearInterval(timer);
  }, [load]);

  useEffect(() => () => {
    if (recordTimer.current !== null) window.clearInterval(recordTimer.current);
    recorder.current?.stream.getTracks().forEach((track) => track.stop());
  }, []);

  function pick(file: File | undefined) {
    if (!file) return;
    if (!/^(image|audio)\//.test(file.type)) {
      toast.error(t(copy.commentsFileType));
      return;
    }
    if (file.size > MAX_ATTACHMENT_BYTES) {
      toast.error(t(copy.commentsFileTooBig));
      return;
    }
    setAttachment(file);
  }

  async function startRecording() {
    if (recording !== null || typeof MediaRecorder === "undefined") return;
    let stream: MediaStream;
    try {
      stream = await navigator.mediaDevices.getUserMedia({ audio: true });
    } catch {
      toast.error(t(copy.commentsMicDenied));
      return;
    }
    const media = new MediaRecorder(stream);
    const chunks: Blob[] = [];
    media.ondataavailable = (event) => {
      if (event.data.size > 0) chunks.push(event.data);
    };
    media.onstop = () => {
      stream.getTracks().forEach((track) => track.stop());
      if (recordTimer.current !== null) window.clearInterval(recordTimer.current);
      recordTimer.current = null;
      recorder.current = null;
      setRecording(null);
      const type = (media.mimeType || "audio/webm").split(";")[0];
      const blob = new Blob(chunks, { type });
      if (blob.size > 0) pick(new File([blob], `voice-${Date.now()}.${type.includes("ogg") ? "ogg" : type.includes("mp4") ? "m4a" : "webm"}`, { type }));
    };
    recorder.current = media;
    media.start();
    setRecording(0);
    recordTimer.current = window.setInterval(() => {
      setRecording((seconds) => {
        const next = (seconds ?? 0) + 1;
        if (next >= MAX_RECORDING_SECONDS) recorder.current?.stop();
        return next;
      });
    }, 1000);
  }

  function stopRecording() {
    if (recorder.current?.state === "recording") recorder.current.stop();
  }

  async function submit() {
    const text = body.trim();
    if ((!text && !attachment) || busy || recording !== null) return;
    setBusy(true);
    try {
      const res = await api.addComment(subject, text, replyTo?.id, attachment);
      setRows((current) => {
        const next = [...current, res.data];
        countRef.current?.(next.length);
        return next;
      });
      setBody("");
      setAttachment(null);
      setReplyTo(null);
    } catch (err) {
      toast.error(err instanceof Error ? err.message : t(copy.saveFailed));
    } finally {
      setBusy(false);
    }
  }

  async function saveEdit() {
    if (!editing || !editing.body.trim()) return;
    setBusy(true);
    try {
      const res = await api.updateComment(editing.id, editing.body.trim());
      setRows((current) => current.map((row) => (row.id === res.data.id ? res.data : row)));
      setEditing(null);
    } catch (err) {
      toast.error(err instanceof Error ? err.message : t(copy.saveFailed));
    } finally {
      setBusy(false);
    }
  }

  async function remove(row: TeamComment) {
    try {
      await api.deleteComment(row.id);
      // A thread start takes its replies with it.
      setRows((current) => {
        const next = current.filter((item) => item.id !== row.id && item.parent_id !== row.id);
        countRef.current?.(next.length);
        return next;
      });
    } catch (err) {
      toast.error(err instanceof Error ? err.message : t(copy.saveFailed));
    }
  }

  function onKey(event: KeyboardEvent<HTMLTextAreaElement>) {
    if (event.key === "Enter" && (event.ctrlKey || event.metaKey)) {
      event.preventDefault();
      void submit();
    }
  }

  if (state === "denied") return <p className="muted comments-note">{t(copy.commentsDenied)}</p>;

  const threads = rows.filter((row) => row.parent_id === null);
  const repliesOf = (id: number) => rows.filter((row) => row.parent_id === id);

  const item = (row: TeamComment, reply: boolean) => (
    <li key={row.id} className={reply ? "comment is-reply" : "comment"}>
      <span className="comment-avatar" aria-hidden="true">{initials(row.user?.name ?? "؟")}</span>
      <div className="comment-main">
        <div className="comment-head">
          <strong>{row.user?.name ?? t(copy.commentsFormer)}</strong>
          <time dateTime={row.created_at ?? undefined} title={row.created_at ? new Date(row.created_at).toLocaleString() : undefined}>{when(row.created_at, locale)}</time>
          {row.edited_at ? <span className="muted">· {t(copy.commentsEdited)}</span> : null}
        </div>
        {editing?.id === row.id ? (
          <div className="comment-edit">
            <textarea className="field" rows={2} value={editing.body} maxLength={4000} onChange={(event) => setEditing({ id: row.id, body: event.target.value })} />
            <div className="comment-actions">
              <button type="button" className="btn btn-primary btn-sm" disabled={busy} onClick={() => void saveEdit()}>{t(copy.save)}</button>
              <button type="button" className="btn btn-ghost btn-sm" onClick={() => setEditing(null)}>{t(copy.cancel)}</button>
            </div>
          </div>
        ) : (
          row.body ? <p className="comment-body">{row.body}</p> : null
        )}
        {row.attachment ? <CommentAttachment comment={row} t={t} /> : null}
        {editing?.id === row.id ? null : (
          <div className="comment-actions">
            {reply ? null : (
              <button type="button" className="btn btn-ghost btn-sm" onClick={() => { setReplyTo(row); box.current?.focus(); }}>
                <Reply size={13} aria-hidden="true" />{t(copy.commentsReply)}
              </button>
            )}
            {row.can_edit ? (
              <button type="button" className="btn btn-ghost btn-sm" onClick={() => setEditing({ id: row.id, body: row.body })}>
                <Pencil size={13} aria-hidden="true" />{t(copy.edit)}
              </button>
            ) : null}
            {row.can_delete ? (
              <ConfirmAction
                label={t(copy.delete)}
                confirmLabel={reply ? t(copy.commentsDeleteConfirm) : t(copy.commentsDeleteThreadConfirm)}
                yesLabel={t(copy.delete)}
                noLabel={t(copy.cancel)}
                className="btn btn-ghost btn-sm btn-danger"
                onConfirm={() => void remove(row)}
              />
            ) : null}
          </div>
        )}
      </div>
    </li>
  );

  return (
    <section className={compact ? "comments is-compact" : "comments"} aria-label={t(copy.comments)}>
      {state === "loading" ? <p className="muted comments-note">{t(copy.loading)}</p> : null}
      {state === "error" ? <p className="error comments-note">{t(copy.commentsLoadFailed)}</p> : null}
      {state === "ready" && threads.length === 0 ? (
        <p className="muted comments-note"><MessageSquare size={15} aria-hidden="true" />{t(copy.commentsEmpty)}</p>
      ) : null}
      {threads.length > 0 ? (
        <ul className="comment-list">
          {threads.map((row) => (
            <li key={row.id} className="comment-thread">
              <ul>
                {item(row, false)}
                {repliesOf(row.id).map((child) => item(child, true))}
              </ul>
            </li>
          ))}
        </ul>
      ) : null}
      {state === "ready" ? (
        // Not a <form>: the panel also sits inside page forms (the post editor).
        <div className="comment-compose">
          {replyTo ? (
            <p className="comment-replying">
              {t(copy.commentsReplyingTo)} <strong>{replyTo.user?.name ?? t(copy.commentsFormer)}</strong>
              <button type="button" className="btn btn-ghost btn-sm" onClick={() => setReplyTo(null)}>{t(copy.cancel)}</button>
            </p>
          ) : null}
          <textarea
            ref={box}
            className="field"
            rows={compact ? 2 : 3}
            value={body}
            maxLength={4000}
            placeholder={t(copy.commentsPlaceholder)}
            aria-label={t(copy.commentsPlaceholder)}
            onChange={(event) => setBody(event.target.value)}
            onKeyDown={onKey}
          />
          {attachment ? (
            <p className="comment-pending">
              {attachment.type.startsWith("image/") ? <ImageIcon size={14} aria-hidden="true" /> : <Mic size={14} aria-hidden="true" />}
              <span className="file-name">{attachment.type.startsWith("audio/") && attachment.name.startsWith("voice-") ? t(copy.commentsVoiceNote) : attachment.name}</span>
              <small>{Math.ceil(attachment.size / 1024)} KB</small>
              <button type="button" className="btn btn-ghost btn-icon btn-sm" aria-label={t(copy.commentsRemoveAttachment)} title={t(copy.commentsRemoveAttachment)} onClick={() => setAttachment(null)}>
                <X size={14} aria-hidden="true" />
              </button>
            </p>
          ) : null}
          <div className="comment-compose-actions">
            <input
              ref={picker}
              type="file"
              accept="image/*,audio/*"
              hidden
              onChange={(event) => {
                pick(event.target.files?.[0]);
                event.target.value = "";
              }}
            />
            <button type="button" className="btn btn-ghost btn-icon btn-sm" disabled={busy || recording !== null} aria-label={t(copy.commentsAttach)} title={t(copy.commentsAttach)} onClick={() => picker.current?.click()}>
              <Paperclip size={15} aria-hidden="true" />
            </button>
            {typeof MediaRecorder !== "undefined" ? (
              recording !== null ? (
                <button type="button" className="btn btn-sm btn-danger comment-recording" aria-label={t(copy.commentsStopRecording)} onClick={stopRecording}>
                  <Square size={13} aria-hidden="true" />{fill(t(copy.commentsRecording), { seconds: recording })}
                </button>
              ) : (
                <button type="button" className="btn btn-ghost btn-icon btn-sm" disabled={busy} aria-label={t(copy.commentsRecord)} title={t(copy.commentsRecord)} onClick={() => void startRecording()}>
                  <Mic size={15} aria-hidden="true" />
                </button>
              )
            ) : null}
            <button type="button" className="btn btn-primary btn-sm" disabled={busy || recording !== null || (body.trim() === "" && !attachment)} aria-busy={busy} onClick={() => void submit()}>
              <Send size={14} aria-hidden="true" />{t(copy.commentsSend)}
            </button>
          </div>
        </div>
      ) : null}
    </section>
  );
}
