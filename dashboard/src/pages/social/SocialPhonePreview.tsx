import { Bookmark, Heart, MessageCircle, Repeat2, Send, Share2, ThumbsUp } from "lucide-react";
import { useEffect, useState, type ReactNode } from "react";
import { api, type SocialAccount, type SocialPost, type SocialPostMedia } from "../../api";
import { SocialBrandIcon } from "../../components/SocialBrandIcon";
import { FileDropzone } from "../../components/FileDropzone";
import { copy, type Locale } from "../../i18n";
import { formatWhen, platformLabel, socialStatusLabel } from "./helpers";

type PreviewFile = { name: string; url: string; kind: string };

type Slide = { key: string; url: string; kind: "image" | "video"; name: string };

function toSlides(existing: SocialPostMedia[], files: PreviewFile[]): Slide[] {
  return [
    ...existing.flatMap((item) =>
      item.url
        ? [{ key: `saved-${item.id}`, url: item.url, kind: item.kind === "video" ? "video" as const : "image" as const, name: item.original_name }]
        : [],
    ),
    ...files.map((file) => ({
      key: file.url,
      url: file.url,
      kind: file.kind === "video" ? "video" as const : "image" as const,
      name: file.name,
    })),
  ];
}

function postSlides(post: SocialPost): Slide[] {
  return toSlides(post.media ?? [], []);
}

function captionFirst(platform: string) {
  return platform !== "instagram";
}

export function SocialPhonePreview({
  locale,
  t,
  accounts,
  placement,
  body,
  existingMedia,
  files,
  excludePostId,
  studio,
  posts,
  onFiles,
  dropHint,
  dropActiveHint,
  dropDisabled,
  feedFilter = "all",
  onFeedFilter,
  renderPostActions,
}: {
  locale: Locale;
  t: (c: { ar: string; en: string }) => string;
  accounts: SocialAccount[];
  placement: string;
  body: string;
  existingMedia: SocialPostMedia[];
  files: PreviewFile[];
  excludePostId?: number | null;
  studio?: boolean;
  posts?: SocialPost[];
  onFiles?: (files: File[]) => void;
  dropHint?: string;
  dropActiveHint?: string;
  dropDisabled?: boolean;
  feedFilter?: "all" | "draft" | "scheduled";
  onFeedFilter?: (value: "all" | "draft" | "scheduled") => void;
  renderPostActions?: (post: SocialPost) => ReactNode;
}) {
  const [accountId, setAccountId] = useState<number | null>(accounts[0]?.id ?? null);
  const [slide, setSlide] = useState(0);
  const [neighbors, setNeighbors] = useState<SocialPost[]>([]);
  const account = accounts.find((item) => item.id === accountId) ?? accounts[0] ?? null;
  const slides = toSlides(existingMedia, files);
  const current = slides[slide] ?? null;
  const mediaKey = slides.map((item) => item.key).join("|");
  const handle = account?.handle ? `@${account.handle.replace(/^@/, "")}` : account?.name ?? "";

  useEffect(() => {
    if (accountId && accounts.some((item) => item.id === accountId)) return;
    setAccountId(accounts[0]?.id ?? null);
  }, [accountId, accounts]);

  useEffect(() => {
    setSlide(0);
  }, [mediaKey]);

  useEffect(() => {
    if (posts) {
      setNeighbors(posts.filter((item) => item.id !== excludePostId));
      return;
    }
    if (!account) {
      setNeighbors([]);
      return;
    }
    let cancelled = false;
    api
      .socialPosts({
        account_id: account.id,
        placement: studio ? undefined : placement,
        per_page: studio ? 100 : 8,
      })
      .then((res) => {
        if (cancelled) return;
        setNeighbors(res.data.filter((item) => item.id !== excludePostId));
      })
      .catch(() => {
        if (!cancelled) setNeighbors([]);
      });
    return () => {
      cancelled = true;
    };
  }, [account?.id, placement, excludePostId, posts, studio]);

  const filteredNeighbors = neighbors.filter((item) => {
    if (feedFilter === "draft") return item.status === "draft" || item.status === "failed";
    if (feedFilter === "scheduled") return item.status === "scheduled" || item.status === "publishing";
    return true;
  });

  return (
    <div className={studio ? "social-phone-preview is-studio" : "social-phone-preview"}>
      {!studio && accounts.length > 1 ? (
        <div className="social-phone-account-switch" role="tablist" aria-label={t(copy.socialPickPlatforms)}>
          {accounts.map((item) => (
            <button
              key={item.id}
              type="button"
              role="tab"
              aria-selected={account?.id === item.id}
              className={account?.id === item.id ? "social-phone-account-tab is-active" : "social-phone-account-tab"}
              onClick={() => setAccountId(item.id)}
            >
              <SocialBrandIcon platform={item.platform} />
              <span>{platformLabel(item.platform, t)}</span>
            </button>
          ))}
        </div>
      ) : null}

      <div className={studio ? `social-phone-bezel is-studio is-${account?.platform ?? "empty"}` : `social-phone-bezel is-${account?.platform ?? "empty"}`}>
        <div className="social-phone-notch" aria-hidden />
        <div className={studio ? `social-phone-screen is-feed is-studio is-${account?.platform ?? "empty"}` : `social-phone-screen is-${placement} is-${account?.platform ?? "empty"}`} dir={locale === "ar" ? "rtl" : "ltr"}>
          {!account ? (
            <p className="social-phone-empty">{t(copy.socialPreviewPickAccount)}</p>
          ) : studio || placement === "feed" ? (
            <FeedTimeline
              account={account}
              handle={handle}
              appName={platformLabel(account.platform, t)}
              nowLabel={t(copy.socialNow)}
              draftLabel={t(copy.socialPreviewThisPost)}
              emptyFeedLabel={t(copy.socialEmptyFeed)}
              body={body}
              slides={slides}
              index={slide}
              prevLabel={t(copy.socialPreviewPrev)}
              nextLabel={t(copy.socialPreviewNext)}
              onIndex={setSlide}
              neighbors={filteredNeighbors}
              locale={locale}
              t={t}
              renderPostActions={renderPostActions}
              lead={
                onFiles ? (
                  <FileDropzone
                    className="social-phone-drop"
                    accept={{ "image/*": [], "video/*": [] }}
                    multiple
                    disabled={dropDisabled}
                    hint={dropHint ?? t(copy.socialDropPost)}
                    activeHint={dropActiveHint ?? t(copy.socialDropPostActive)}
                    onFiles={onFiles}
                  />
                ) : null
              }
              filters={
                onFeedFilter ? (
                  <div className="social-phone-filters" role="tablist" aria-label={t(copy.socialPosts)}>
                    {(["all", "draft", "scheduled"] as const).map((value) => (
                      <button
                        key={value}
                        type="button"
                        role="tab"
                        aria-selected={feedFilter === value}
                        className={feedFilter === value ? "is-on" : undefined}
                        onClick={() => onFeedFilter(value)}
                      >
                        {value === "all" ? t(copy.socialFeedAll) : value === "draft" ? t(copy.socialFeedDrafts) : t(copy.socialFeedScheduled)}
                      </button>
                    ))}
                  </div>
                ) : null
              }
            />
          ) : placement === "story" ? (
            <StoryTimeline
              account={account}
              handle={handle}
              slide={current}
              caption={body}
              emptyLabel={t(copy.socialPreviewEmptyMedia)}
              draftLabel={t(copy.socialPreviewThisPost)}
              neighbors={neighbors}
            />
          ) : (
            <ReelTimeline
              account={account}
              handle={handle}
              slide={current}
              caption={body}
              emptyLabel={t(copy.socialPreviewEmptyMedia)}
              nowLabel={t(copy.socialNow)}
              draftLabel={t(copy.socialPreviewThisPost)}
              neighbors={neighbors}
              locale={locale}
            />
          )}
        </div>
      </div>
      {!studio && account && neighbors.length > 0 ? <p className="muted social-phone-feed-hint">{t(copy.socialPreviewOnPage)}</p> : null}
    </div>
  );
}

function AccountMark({ account }: { account: SocialAccount }) {
  return (
    <span className={`social-phone-avatar is-${account.platform}`} aria-hidden>
      <SocialBrandIcon platform={account.platform} />
    </span>
  );
}

function MediaSlide({ slide, className, interactive }: { slide: Slide; className: string; interactive?: boolean }) {
  if (slide.kind === "video") {
    return (
      <video
        className={className}
        src={slide.url}
        controls={interactive}
        muted
        playsInline
        preload="metadata"
        controlsList="nodownload nofullscreen noremoteplayback"
        disablePictureInPicture
      />
    );
  }
  return <img className={className} src={slide.url} alt={slide.name} />;
}

function EngageBar({ platform, t }: { platform: string; t: (c: { ar: string; en: string }) => string }) {
  if (platform === "instagram") {
    return (
      <div className="social-phone-engage is-instagram" aria-hidden>
        <span className="social-phone-engage-icons">
          <Heart size={18} strokeWidth={1.8} />
          <MessageCircle size={18} strokeWidth={1.8} />
          <Send size={18} strokeWidth={1.8} />
        </span>
        <Bookmark size={18} strokeWidth={1.8} />
      </div>
    );
  }

  if (platform === "threads") {
    return (
      <div className="social-phone-engage is-threads" aria-hidden>
        <Heart size={16} strokeWidth={1.8} />
        <MessageCircle size={16} strokeWidth={1.8} />
        <Repeat2 size={16} strokeWidth={1.8} />
        <Send size={16} strokeWidth={1.8} />
      </div>
    );
  }

  if (platform === "linkedin") {
    return (
      <div className="social-phone-engage is-linkedin">
        <span><ThumbsUp size={14} strokeWidth={2} /> {t(copy.socialFeedLike)}</span>
        <span><MessageCircle size={14} strokeWidth={2} /> {t(copy.socialComment)}</span>
        <span><Repeat2 size={14} strokeWidth={2} /> {t(copy.socialFeedRepost)}</span>
        <span><Send size={14} strokeWidth={2} /> {t(copy.socialFeedShare)}</span>
      </div>
    );
  }

  return (
    <div className="social-phone-engage is-facebook">
      <span><ThumbsUp size={14} strokeWidth={2} /> {t(copy.socialFeedLike)}</span>
      <span><MessageCircle size={14} strokeWidth={2} /> {t(copy.socialComment)}</span>
      <span><Share2 size={14} strokeWidth={2} /> {t(copy.socialFeedShare)}</span>
    </div>
  );
}

function FeedTimeline({
  account,
  handle,
  appName,
  nowLabel,
  draftLabel,
  emptyFeedLabel,
  body,
  slides,
  index,
  prevLabel,
  nextLabel,
  onIndex,
  neighbors,
  locale,
  t,
  lead,
  filters,
  renderPostActions,
}: {
  account: SocialAccount;
  handle: string;
  appName: string;
  nowLabel: string;
  draftLabel: string;
  emptyFeedLabel: string;
  body: string;
  slides: Slide[];
  index: number;
  prevLabel: string;
  nextLabel: string;
  onIndex: (value: number) => void;
  neighbors: SocialPost[];
  locale: Locale;
  t: (c: { ar: string; en: string }) => string;
  lead?: ReactNode;
  filters?: ReactNode;
  renderPostActions?: (post: SocialPost) => ReactNode;
}) {
  const composing = Boolean(body.trim() || slides.length > 0);

  return (
    <div className={`social-phone-feed-scroll is-${account.platform}`}>
      <header className={`social-phone-feed-bar is-${account.platform}`}>
        <p className="social-phone-app-name">{appName}</p>
        <div className="social-phone-feed-identity">
          <AccountMark account={account} />
          <div>
            <p className="social-phone-name">{account.name}</p>
            <p className="social-phone-handle"><bdi>{handle}</bdi></p>
          </div>
        </div>
        {filters}
      </header>
      {lead}
      {composing ? (
        <FeedPost
          account={account}
          handle={handle}
          timeLabel={nowLabel}
          body={body}
          slides={slides}
          index={index}
          prevLabel={prevLabel}
          nextLabel={nextLabel}
          onIndex={onIndex}
          isDraft
          draftLabel={draftLabel}
          interactive
          t={t}
        />
      ) : null}
      {neighbors.map((post) => (
        <FeedPost
          key={post.id}
          account={account}
          handle={handle}
          timeLabel={formatWhen(post.published_at || post.scheduled_at || post.created_at, locale)}
          body={post.body}
          slides={postSlides(post)}
          index={0}
          statusLabel={post.status !== "published" ? socialStatusLabel(post.status, t) : undefined}
          toolbar={renderPostActions?.(post)}
          t={t}
        />
      ))}
      {!composing && neighbors.length === 0 ? <p className="social-phone-empty-feed">{emptyFeedLabel}</p> : null}
    </div>
  );
}

function FeedPost({
  account,
  handle,
  timeLabel,
  body,
  slides,
  index,
  prevLabel,
  nextLabel,
  onIndex,
  isDraft,
  draftLabel,
  interactive,
  statusLabel,
  toolbar,
  t,
}: {
  account: SocialAccount;
  handle: string;
  timeLabel: string;
  body: string;
  slides: Slide[];
  index: number;
  prevLabel?: string;
  nextLabel?: string;
  onIndex?: (value: number) => void;
  isDraft?: boolean;
  draftLabel?: string;
  interactive?: boolean;
  statusLabel?: string;
  toolbar?: ReactNode;
  t: (c: { ar: string; en: string }) => string;
}) {
  const current = slides[index] ?? null;
  const textFirst = captionFirst(account.platform);
  const caption = body.trim() ? (
    <p className="social-phone-caption">
      {account.platform === "instagram" ? <strong>{account.name} </strong> : null}
      {body}
    </p>
  ) : null;
  const media = current ? (
    <div className="social-phone-media">
      <MediaSlide slide={current} className="social-phone-media-item" interactive={interactive} />
      {interactive && slides.length > 1 && onIndex && prevLabel && nextLabel ? (
        <>
          <button type="button" className="social-phone-nav is-prev" aria-label={prevLabel} onClick={() => onIndex(index === 0 ? slides.length - 1 : index - 1)}>
            ‹
          </button>
          <button type="button" className="social-phone-nav is-next" aria-label={nextLabel} onClick={() => onIndex(index === slides.length - 1 ? 0 : index + 1)}>
            ›
          </button>
          <div className="social-phone-dots" role="tablist">
            {slides.map((item, i) => (
              <button
                key={item.key}
                type="button"
                role="tab"
                aria-selected={i === index}
                aria-label={item.name}
                className={i === index ? "is-active" : undefined}
                onClick={() => onIndex(i)}
              />
            ))}
          </div>
        </>
      ) : null}
    </div>
  ) : null;

  return (
    <article className={`social-phone-feed-post is-${account.platform}${isDraft ? " is-draft" : ""}`}>
      <header className="social-phone-post-head">
        <AccountMark account={account} />
        <div>
          <p className="social-phone-name">{account.name}</p>
          <p className="social-phone-handle">
            {account.platform === "instagram" ? null : <><bdi>{handle}</bdi> · </>}
            {timeLabel}
          </p>
        </div>
        {isDraft && draftLabel ? <span className="social-phone-draft-chip">{draftLabel}</span> : null}
        {!isDraft && statusLabel ? <span className="social-phone-status-chip">{statusLabel}</span> : null}
      </header>
      {textFirst ? caption : media}
      {textFirst ? media : caption}
      {current || body.trim() ? <EngageBar platform={account.platform} t={t} /> : null}
      {toolbar}
    </article>
  );
}

function ReelTimeline({
  account,
  handle,
  slide,
  caption,
  emptyLabel,
  nowLabel,
  draftLabel,
  neighbors,
  locale,
}: {
  account: SocialAccount;
  handle: string;
  slide: Slide | null;
  caption: string;
  emptyLabel: string;
  nowLabel: string;
  draftLabel: string;
  neighbors: SocialPost[];
  locale: Locale;
}) {
  return (
    <div className="social-phone-reel-scroll">
      <div className="social-phone-reel is-draft">
        {slide ? <MediaSlide slide={slide} className="social-phone-reel-media" interactive /> : <p className="social-phone-empty">{emptyLabel}</p>}
        <div className="social-phone-reel-meta">
          <AccountMark account={account} />
          <div>
            <p className="social-phone-name">{account.name}</p>
            <p className="social-phone-handle"><bdi>{handle}</bdi> · {nowLabel}</p>
          </div>
          <span className="social-phone-draft-chip">{draftLabel}</span>
        </div>
        {caption ? <p className="social-phone-reel-caption">{caption}</p> : null}
      </div>
      {neighbors.map((post) => {
        const media = postSlides(post)[0];
        return (
          <div key={post.id} className="social-phone-reel">
            {media ? <MediaSlide slide={media} className="social-phone-reel-media" /> : <p className="social-phone-empty">{post.body}</p>}
            <div className="social-phone-reel-meta">
              <AccountMark account={account} />
              <div>
                <p className="social-phone-name">{account.name}</p>
                <p className="social-phone-handle"><bdi>{handle}</bdi> · {formatWhen(post.published_at || post.created_at, locale)}</p>
              </div>
            </div>
            {post.body ? <p className="social-phone-reel-caption">{post.body}</p> : null}
          </div>
        );
      })}
    </div>
  );
}

function StoryTimeline({
  account,
  handle,
  slide,
  caption,
  emptyLabel,
  draftLabel,
  neighbors,
}: {
  account: SocialAccount;
  handle: string;
  slide: Slide | null;
  caption: string;
  emptyLabel: string;
  draftLabel: string;
  neighbors: SocialPost[];
}) {
  return (
    <div className="social-phone-story">
      {slide ? <MediaSlide slide={slide} className="social-phone-story-media" interactive /> : <p className="social-phone-empty">{emptyLabel}</p>}
      <div className="social-phone-story-tray" aria-label={draftLabel}>
        <span className="social-phone-story-ring is-draft">
          {slide?.kind === "image" ? <img src={slide.url} alt="" /> : <AccountMark account={account} />}
        </span>
        {neighbors.map((post) => {
          const thumb = postSlides(post)[0];
          return (
            <span key={post.id} className="social-phone-story-ring">
              {thumb?.kind === "image" ? <img src={thumb.url} alt="" /> : <AccountMark account={account} />}
            </span>
          );
        })}
      </div>
      <header className="social-phone-story-head">
        <AccountMark account={account} />
        <div>
          <p className="social-phone-name">{account.name}</p>
          <p className="social-phone-handle"><bdi>{handle}</bdi></p>
        </div>
        <span className="social-phone-draft-chip">{draftLabel}</span>
      </header>
      {caption ? <p className="social-phone-reel-caption">{caption}</p> : null}
    </div>
  );
}
