import { useEffect, useState } from "react";
import { api, type SocialAccount, type SocialPost, type SocialPostMedia } from "../../api";
import { SocialBrandIcon } from "../../components/SocialBrandIcon";
import { copy, type Locale } from "../../i18n";
import { formatWhen, platformLabel } from "./helpers";

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

export function SocialPhonePreview({
  locale,
  t,
  accounts,
  placement,
  body,
  existingMedia,
  files,
  excludePostId,
}: {
  locale: Locale;
  t: (c: { ar: string; en: string }) => string;
  accounts: SocialAccount[];
  placement: string;
  body: string;
  existingMedia: SocialPostMedia[];
  files: PreviewFile[];
  excludePostId?: number | null;
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
    if (!account) {
      setNeighbors([]);
      return;
    }
    let cancelled = false;
    api
      .socialPosts({
        account_id: account.id,
        placement,
        per_page: 8,
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
  }, [account?.id, placement, excludePostId]);

  return (
    <div className="social-phone-preview">
      {accounts.length > 1 ? (
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

      <div className={`social-phone-bezel is-${account?.platform ?? "empty"}`}>
        <div className="social-phone-notch" aria-hidden />
        <div className={`social-phone-screen is-${placement}`} dir={locale === "ar" ? "rtl" : "ltr"}>
          {!account ? (
            <p className="social-phone-empty">{t(copy.socialPreviewPickAccount)}</p>
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
          ) : placement === "reel" ? (
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
          ) : (
            <FeedTimeline
              account={account}
              handle={handle}
              nowLabel={t(copy.socialNow)}
              draftLabel={t(copy.socialPreviewThisPost)}
              body={body}
              slides={slides}
              index={slide}
              emptyLabel={t(copy.socialPreviewEmptyMedia)}
              prevLabel={t(copy.socialPreviewPrev)}
              nextLabel={t(copy.socialPreviewNext)}
              onIndex={setSlide}
              neighbors={neighbors}
              locale={locale}
            />
          )}
        </div>
      </div>
      {account && neighbors.length > 0 ? <p className="muted social-phone-feed-hint">{t(copy.socialPreviewOnPage)}</p> : null}
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

function FeedTimeline({
  account,
  handle,
  nowLabel,
  draftLabel,
  body,
  slides,
  index,
  emptyLabel,
  prevLabel,
  nextLabel,
  onIndex,
  neighbors,
  locale,
}: {
  account: SocialAccount;
  handle: string;
  nowLabel: string;
  draftLabel: string;
  body: string;
  slides: Slide[];
  index: number;
  emptyLabel: string;
  prevLabel: string;
  nextLabel: string;
  onIndex: (value: number) => void;
  neighbors: SocialPost[];
  locale: Locale;
}) {
  return (
    <div className="social-phone-feed-scroll">
      <header className="social-phone-feed-bar">
        <AccountMark account={account} />
        <div>
          <p className="social-phone-name">{account.name}</p>
          <p className="social-phone-handle"><bdi>{handle}</bdi></p>
        </div>
      </header>
      <FeedPost
        account={account}
        handle={handle}
        timeLabel={nowLabel}
        body={body}
        slides={slides}
        index={index}
        emptyLabel={emptyLabel}
        prevLabel={prevLabel}
        nextLabel={nextLabel}
        onIndex={onIndex}
        isDraft
        draftLabel={draftLabel}
        interactive
      />
      {neighbors.map((post) => (
        <FeedPost
          key={post.id}
          account={account}
          handle={handle}
          timeLabel={formatWhen(post.published_at || post.created_at, locale)}
          body={post.body}
          slides={postSlides(post)}
          index={0}
          emptyLabel=""
        />
      ))}
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
  emptyLabel,
  prevLabel,
  nextLabel,
  onIndex,
  isDraft,
  draftLabel,
  interactive,
}: {
  account: SocialAccount;
  handle: string;
  timeLabel: string;
  body: string;
  slides: Slide[];
  index: number;
  emptyLabel: string;
  prevLabel?: string;
  nextLabel?: string;
  onIndex?: (value: number) => void;
  isDraft?: boolean;
  draftLabel?: string;
  interactive?: boolean;
}) {
  const current = slides[index] ?? null;

  return (
    <article className={isDraft ? "social-phone-feed-post is-draft" : "social-phone-feed-post"}>
      <header className="social-phone-post-head">
        <AccountMark account={account} />
        <div>
          <p className="social-phone-name">{account.name}</p>
          <p className="social-phone-handle"><bdi>{handle}</bdi> · {timeLabel}</p>
        </div>
        {isDraft && draftLabel ? <span className="social-phone-draft-chip">{draftLabel}</span> : <SocialBrandIcon platform={account.platform} />}
      </header>
      {current || isDraft ? (
        <div className="social-phone-media">
          {current ? (
            <>
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
            </>
          ) : (
            <p className="social-phone-empty">{emptyLabel}</p>
          )}
        </div>
      ) : null}
      {body ? <p className="social-phone-caption">{body}</p> : null}
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
