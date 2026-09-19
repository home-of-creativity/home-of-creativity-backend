import { Bookmark, Heart, MessageCircle, Repeat2, Send, Share2, ThumbsUp, X } from "lucide-react";
import { useEffect, useRef, useState, type ReactNode } from "react";
import { api, type SocialAccount, type SocialPost, type SocialPostMedia } from "../../api";
import { SocialBrandIcon } from "../../components/SocialBrandIcon";
import { FileDropzone } from "../../components/FileDropzone";
import { copy, type Locale } from "../../i18n";
import { accountAvatarUrl, formatWhen, matchesSocialPlacement, platformLabel, socialStatusLabel } from "./helpers";

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

type OpenMedia = (slides: Slide[], index: number) => void;

function PhoneMediaViewer({
  slides,
  index,
  onIndex,
  onClose,
  closeLabel,
  prevLabel,
  nextLabel,
  title,
}: {
  slides: Slide[];
  index: number;
  onIndex: (value: number) => void;
  onClose: () => void;
  closeLabel: string;
  prevLabel: string;
  nextLabel: string;
  title: string;
}) {
  const closeRef = useRef<HTMLButtonElement>(null);
  const current = slides[index] ?? null;

  useEffect(() => {
    closeRef.current?.focus();
  }, []);

  useEffect(() => {
    const onKey = (event: KeyboardEvent) => {
      if (event.key === "Escape") {
        event.preventDefault();
        onClose();
        return;
      }
      if (slides.length < 2) return;
      if (event.key === "ArrowLeft") {
        event.preventDefault();
        onIndex(index === 0 ? slides.length - 1 : index - 1);
      }
      if (event.key === "ArrowRight") {
        event.preventDefault();
        onIndex(index === slides.length - 1 ? 0 : index + 1);
      }
    };
    window.addEventListener("keydown", onKey);
    return () => window.removeEventListener("keydown", onKey);
  }, [index, onClose, onIndex, slides.length]);

  if (!current) return null;

  return (
    <div className="social-phone-viewer" role="dialog" aria-modal="true" aria-label={title} onClick={(event) => event.stopPropagation()}>
      <div className="social-phone-viewer-bar">
        <button ref={closeRef} type="button" className="social-phone-viewer-close" aria-label={closeLabel} onClick={onClose}>
          <X size={18} strokeWidth={2} />
        </button>
      </div>
      <div className="social-phone-viewer-stage">
        {current.kind === "video" ? (
          <video
            key={current.key}
            className="social-phone-viewer-media"
            src={current.url}
            controls
            autoPlay
            playsInline
            preload="metadata"
            controlsList="nodownload nofullscreen noremoteplayback"
            disablePictureInPicture
          />
        ) : (
          <img className="social-phone-viewer-media" src={current.url} alt={current.name} />
        )}
      </div>
      {slides.length > 1 ? (
        <div className="social-phone-viewer-nav">
          <button type="button" className="social-phone-viewer-step" aria-label={prevLabel} onClick={() => onIndex(index === 0 ? slides.length - 1 : index - 1)}>
            ‹
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
          <button type="button" className="social-phone-viewer-step" aria-label={nextLabel} onClick={() => onIndex(index === slides.length - 1 ? 0 : index + 1)}>
            ›
          </button>
        </div>
      ) : (
        <div className="social-phone-viewer-nav" aria-hidden />
      )}
    </div>
  );
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
  focused,
  onFocus,
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
  focused?: boolean;
  onFocus?: () => void;
}) {
  const [accountId, setAccountId] = useState<number | null>(accounts[0]?.id ?? null);
  const [slide, setSlide] = useState(0);
  const [neighbors, setNeighbors] = useState<SocialPost[]>([]);
  const [viewer, setViewer] = useState<{ slides: Slide[]; index: number } | null>(null);
  const openerRef = useRef<HTMLElement | null>(null);
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

  const viewPlacement = phonePlacement(account?.platform, placement);
  const openLabel = t(copy.socialMediaFullscreen);
  const openMedia: OpenMedia = (nextSlides, nextIndex) => {
    if (!nextSlides[nextIndex]) return;
    openerRef.current = document.activeElement instanceof HTMLElement ? document.activeElement : null;
    setViewer({ slides: nextSlides, index: nextIndex });
  };
  const closeViewer = () => {
    setViewer(null);
    openerRef.current?.focus();
  };
  const filteredNeighbors = neighbors.filter((item) => {
    if (feedFilter === "draft") return item.status === "draft" || item.status === "failed";
    if (feedFilter === "scheduled") return item.status === "scheduled" || item.status === "publishing";
    return true;
  }).filter((item) => matchesSocialPlacement(item, viewPlacement));

  const dropzone = onFiles ? (
    <FileDropzone
      className={account?.platform === "instagram" && viewPlacement !== "story" ? "ig-studio-compose-drop" : "social-phone-drop"}
      accept={{ "image/*": [], "video/*": [] }}
      multiple
      disabled={dropDisabled}
      hint={dropHint ?? t(copy.socialDropPost)}
      activeHint={dropActiveHint ?? t(copy.socialDropPostActive)}
      onFiles={onFiles}
    />
  ) : null;
  const filters = onFeedFilter ? (
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
  ) : null;

  return (
    <div
      className={[studio ? "social-phone-preview is-studio" : "social-phone-preview", focused ? "is-focus" : ""]
        .filter(Boolean)
        .join(" ")}
      onClick={onFocus}
    >
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
        <div className={studio ? `social-phone-screen is-${viewPlacement} is-studio is-${account?.platform ?? "empty"}` : `social-phone-screen is-${placement} is-${account?.platform ?? "empty"}`} dir={locale === "ar" ? "rtl" : "ltr"}>
          <div className="social-phone-screen-body" {...(viewer ? { inert: true } : {})}>
          {!account ? (
            <p className="social-phone-empty">{t(copy.socialPreviewPickAccount)}</p>
          ) : account.platform === "instagram" && viewPlacement !== "story" ? (
            <InstagramStudioProfile
              account={account}
              handle={handle}
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
              mode={viewPlacement === "reel" ? "reel" : "feed"}
              lead={dropzone}
              filters={studio ? null : filters}
              renderPostActions={renderPostActions}
              openLabel={openLabel}
              onOpenMedia={openMedia}
            />
          ) : viewPlacement === "story" ? (
            <StoryTimeline
              account={account}
              handle={handle}
              slide={current}
              caption={body}
              emptyLabel={t(copy.socialPreviewEmptyMedia)}
              draftLabel={t(copy.socialPreviewThisPost)}
              neighbors={filteredNeighbors}
              placeholder={dropzone}
              openLabel={openLabel}
              onOpenMedia={openMedia}
            />
          ) : viewPlacement === "reel" ? (
            <ReelTimeline
              account={account}
              handle={handle}
              slide={current}
              caption={body}
              emptyLabel={t(copy.socialPreviewEmptyMedia)}
              nowLabel={t(copy.socialNow)}
              draftLabel={t(copy.socialPreviewThisPost)}
              neighbors={filteredNeighbors}
              locale={locale}
              placeholder={dropzone}
              openLabel={openLabel}
              onOpenMedia={openMedia}
            />
          ) : (
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
              lead={dropzone}
              filters={filters}
              openLabel={openLabel}
              onOpenMedia={openMedia}
            />
          )}
          </div>
          {viewer ? (
            <PhoneMediaViewer
              slides={viewer.slides}
              index={viewer.index}
              onIndex={(next) => setViewer((currentViewer) => (currentViewer ? { ...currentViewer, index: next } : currentViewer))}
              onClose={closeViewer}
              closeLabel={t(copy.close)}
              prevLabel={t(copy.socialPreviewPrev)}
              nextLabel={t(copy.socialPreviewNext)}
              title={openLabel}
            />
          ) : null}
        </div>
      </div>
      {!studio && account && neighbors.length > 0 ? <p className="muted social-phone-feed-hint">{t(copy.socialPreviewOnPage)}</p> : null}
    </div>
  );
}

function phonePlacement(platform: string | undefined, placement: string) {
  if (platform === "linkedin") return "feed";
  if (platform === "threads" && placement !== "feed") return "feed";
  return placement;
}

export function SocialPostCard({
  account,
  post,
  locale,
  t,
  toolbar,
}: {
  account: SocialAccount;
  post: SocialPost;
  locale: Locale;
  t: (c: { ar: string; en: string }) => string;
  toolbar?: ReactNode;
}) {
  return (
    <FeedPost
      account={account}
      handle={account.handle ? `@${account.handle.replace(/^@/, "")}` : account.name}
      timeLabel={formatWhen(post.published_at || post.scheduled_at || post.created_at, locale)}
      body={post.body}
      slides={postSlides(post)}
      index={0}
      statusLabel={post.status !== "published" ? socialStatusLabel(post.status, t) : undefined}
      toolbar={toolbar}
      t={t}
    />
  );
}

function InstagramStudioProfile({
  account,
  draftLabel,
  emptyFeedLabel,
  slides,
  index,
  neighbors,
  t,
  lead,
  filters,
  renderPostActions,
  openLabel,
  onOpenMedia,
}: {
  account: SocialAccount;
  handle: string;
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
  mode: "feed" | "reel";
  lead?: ReactNode;
  filters?: ReactNode;
  renderPostActions?: (post: SocialPost) => ReactNode;
  openLabel: string;
  onOpenMedia: OpenMedia;
}) {
  const composeSlot = Boolean(lead || slides.length > 0);
  const username = account.handle?.replace(/^@/, "") || account.name;
  const postCount = neighbors.length + (composeSlot && slides.length > 0 ? 1 : 0);

  return (
    <div className="ig-studio">
      <div className="ig-studio-top">
        <span className="ig-studio-username">{username}</span>
        {filters}
      </div>
      <div className="ig-studio-head">
        <AccountMark account={account} size="profile" />
        <div>
          <p className="ig-studio-name">{account.name}</p>
          <p className="ig-studio-handle"><bdi>@{username}</bdi></p>
        </div>
        <dl className="ig-studio-stats">
          <div>
            <dd>{postCount}</dd>
            <dt>{t(copy.socialProfilePosts)}</dt>
          </div>
        </dl>
      </div>
      <div className="ig-studio-grid">
        {composeSlot ? (
          <div className="ig-studio-tile-wrap is-compose">
            {slides[0] ? (
              <button type="button" className="ig-studio-tile is-compose" aria-label={openLabel} onClick={() => onOpenMedia(slides, index)}>
                <MediaSlide slide={slides[0]} className="ig-studio-media" />
                {slides.length > 1 ? (
                  <svg className="ig-studio-badge" viewBox="0 0 24 24" aria-hidden>
                    <path fill="currentColor" d="M7 7h10v10H7V7Zm-3 3h2v8h8v2H4V10Zm16-6H9a2 2 0 0 0-2 2v1h11v11h1a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2Z" />
                  </svg>
                ) : slides[0].kind === "video" ? (
                  <svg className="ig-studio-badge" viewBox="0 0 24 24" aria-hidden>
                    <path fill="currentColor" d="M8 6.8v10.4L18 12 8 6.8Z" />
                  </svg>
                ) : null}
                <span className="ig-studio-compose-chip">{draftLabel}</span>
              </button>
            ) : (
              <div className="ig-studio-tile is-compose">{lead}</div>
            )}
          </div>
        ) : null}
        {neighbors.map((post) => {
          const media = postSlides(post);
          const first = media[0];
          const tile = (
            <>
              {first ? <MediaSlide slide={first} className="ig-studio-media" /> : <span className="ig-studio-tile-empty">{post.body.trim() || t(copy.socialUntitledPost)}</span>}
              {post.media && post.media.length > 1 ? (
                <svg className="ig-studio-badge" viewBox="0 0 24 24" aria-hidden>
                  <path fill="currentColor" d="M7 7h10v10H7V7Zm-3 3h2v8h8v2H4V10Zm16-6H9a2 2 0 0 0-2 2v1h11v11h1a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2Z" />
                </svg>
              ) : first?.kind === "video" ? (
                <svg className="ig-studio-badge" viewBox="0 0 24 24" aria-hidden>
                  <path fill="currentColor" d="M8 6.8v10.4L18 12 8 6.8Z" />
                </svg>
              ) : null}
            </>
          );
          return (
            <div key={post.id} className="ig-studio-tile-wrap">
              {first ? (
                <button type="button" className="ig-studio-tile" aria-label={openLabel} onClick={() => onOpenMedia(media, 0)}>
                  {tile}
                </button>
              ) : (
                <div className="ig-studio-tile">{tile}</div>
              )}
              {renderPostActions ? <div className="ig-studio-tile-more">{renderPostActions(post)}</div> : null}
            </div>
          );
        })}
      </div>
      {!composeSlot && neighbors.length === 0 ? <p className="social-phone-empty-feed">{emptyFeedLabel}</p> : null}
    </div>
  );
}

function AccountMark({ account, size = "sm" }: { account: SocialAccount; size?: "sm" | "profile" }) {
  const src = accountAvatarUrl(account) ?? (size === "profile" ? `${import.meta.env.BASE_URL}hummingbird.svg` : null);

  return (
    <span className={`social-phone-avatar is-${account.platform}${size === "profile" ? " is-profile" : ""}`} aria-hidden>
      {src ? <img src={src} alt="" referrerPolicy="no-referrer" /> : <SocialBrandIcon platform={account.platform} />}
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

function MediaHit({
  slides,
  index,
  openLabel,
  onOpen,
  mediaClassName,
  cover,
}: {
  slides: Slide[];
  index: number;
  openLabel: string;
  onOpen: OpenMedia;
  mediaClassName: string;
  cover?: boolean;
}) {
  const slide = slides[index];
  if (!slide) return null;
  return (
    <button
      type="button"
      className={cover ? "social-phone-media-hit is-cover" : "social-phone-media-hit"}
      aria-label={openLabel}
      onClick={() => onOpen(slides, index)}
    >
      <MediaSlide slide={slide} className={mediaClassName} />
    </button>
  );
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
  openLabel,
  onOpenMedia,
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
  openLabel: string;
  onOpenMedia: OpenMedia;
}) {
  const composing = Boolean(body.trim() || slides.length > 0 || lead);

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
          placeholder={!slides.length ? lead : undefined}
          t={t}
          openLabel={openLabel}
          onOpenMedia={onOpenMedia}
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
          openLabel={openLabel}
          onOpenMedia={onOpenMedia}
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
  placeholder,
  t,
  openLabel,
  onOpenMedia,
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
  placeholder?: ReactNode;
  t: (c: { ar: string; en: string }) => string;
  openLabel?: string;
  onOpenMedia?: OpenMedia;
}) {
  const current = slides[index] ?? null;
  const caption = body.trim() ? <p className="social-phone-caption">{body}</p> : null;
  const media = current ? (
    <div className="social-phone-media">
      {onOpenMedia && openLabel ? (
        <MediaHit slides={slides} index={index} openLabel={openLabel} onOpen={onOpenMedia} mediaClassName="social-phone-media-item" />
      ) : (
        <MediaSlide slide={current} className="social-phone-media-item" interactive={interactive} />
      )}
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
        <div className="social-phone-post-identity">
          <p className="social-phone-name">{account.name}</p>
          <p className="social-phone-handle">
            {account.platform === "instagram" ? null : <><bdi>{handle}</bdi> · </>}
            {timeLabel}
          </p>
        </div>
        <span className="social-phone-post-spacer" aria-hidden="true" />
        {isDraft && draftLabel ? <span className="social-phone-draft-chip">{draftLabel}</span> : null}
        {!isDraft && statusLabel ? <span className="social-phone-status-chip">{statusLabel}</span> : null}
        {toolbar}
      </header>
      {caption}
      {media}
      {!current && placeholder ? <div className="social-phone-media is-drop">{placeholder}</div> : null}
      {current || placeholder || body.trim() ? <EngageBar platform={account.platform} t={t} /> : null}
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
  placeholder,
  openLabel,
  onOpenMedia,
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
  placeholder?: ReactNode;
  openLabel: string;
  onOpenMedia: OpenMedia;
}) {
  return (
    <div className="social-phone-reel-scroll">
      <div className="social-phone-reel is-draft">
        {slide ? (
          <MediaHit slides={[slide]} index={0} openLabel={openLabel} onOpen={onOpenMedia} mediaClassName="social-phone-reel-media" cover />
        ) : (
          placeholder ?? <p className="social-phone-empty">{emptyLabel}</p>
        )}
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
        const media = postSlides(post);
        return (
          <div key={post.id} className="social-phone-reel">
            {media[0] ? (
              <MediaHit slides={media} index={0} openLabel={openLabel} onOpen={onOpenMedia} mediaClassName="social-phone-reel-media" cover />
            ) : (
              <p className="social-phone-empty">{post.body}</p>
            )}
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
  placeholder,
  openLabel,
  onOpenMedia,
}: {
  account: SocialAccount;
  handle: string;
  slide: Slide | null;
  caption: string;
  emptyLabel: string;
  draftLabel: string;
  neighbors: SocialPost[];
  placeholder?: ReactNode;
  openLabel: string;
  onOpenMedia: OpenMedia;
}) {
  return (
    <div className="social-phone-story">
      {slide ? (
        <MediaHit slides={[slide]} index={0} openLabel={openLabel} onOpen={onOpenMedia} mediaClassName="social-phone-story-media" cover />
      ) : (
        placeholder ?? <p className="social-phone-empty">{emptyLabel}</p>
      )}
      <div className="social-phone-story-tray" aria-label={draftLabel}>
        <button
          type="button"
          className="social-phone-story-ring is-draft"
          aria-label={openLabel}
          disabled={!slide}
          onClick={() => slide && onOpenMedia([slide], 0)}
        >
          {slide?.kind === "image" ? <img src={slide.url} alt="" /> : <AccountMark account={account} />}
        </button>
        {neighbors.map((post) => {
          const media = postSlides(post);
          const thumb = media[0];
          return (
            <button
              key={post.id}
              type="button"
              className="social-phone-story-ring"
              aria-label={openLabel}
              disabled={!thumb}
              onClick={() => thumb && onOpenMedia(media, 0)}
            >
              {thumb?.kind === "image" ? <img src={thumb.url} alt="" /> : <AccountMark account={account} />}
            </button>
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
