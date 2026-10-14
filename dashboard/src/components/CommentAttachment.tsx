import { useEffect, useState } from "react";
import { api, type TeamComment } from "../api";
import { copy } from "../i18n";

/** A comment's picture or voice note, read through the API with the viewer's token. */
export function CommentAttachment({ comment, t }: { comment: TeamComment; t: (c: { ar: string; en: string }) => string }) {
  const [url, setUrl] = useState<string | null>(null);
  const [failed, setFailed] = useState(false);
  const attachment = comment.attachment;

  useEffect(() => {
    if (!attachment) return;
    let objectUrl: string | null = null;
    let cancelled = false;
    api.commentAttachment(comment.id)
      .then((blob) => {
        if (cancelled) return;
        objectUrl = URL.createObjectURL(blob);
        setUrl(objectUrl);
      })
      .catch(() => {
        if (!cancelled) setFailed(true);
      });
    return () => {
      cancelled = true;
      if (objectUrl) URL.revokeObjectURL(objectUrl);
    };
  }, [comment.id, attachment]);

  if (!attachment) return null;
  if (failed) return <p className="muted comment-attachment">{t(copy.commentsAttachmentFailed)}</p>;
  if (!url) return <p className="muted comment-attachment">{t(copy.loading)}</p>;

  return attachment.kind === "image" ? (
    <a className="comment-attachment" href={url} target="_blank" rel="noreferrer">
      <img src={url} alt={attachment.name} loading="lazy" />
    </a>
  ) : (
    <audio className="comment-attachment" controls preload="metadata" src={url} aria-label={t(copy.commentsVoiceNote)} />
  );
}
