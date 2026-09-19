import { copy, socialPlacements, socialStatuses, type Copy } from "../../i18n";
import type { SocialAccount } from "../../api";

export type SocialPageGroup = {
  key: string;
  name: string;
  facebook?: SocialAccount;
  instagram?: SocialAccount;
  threads?: SocialAccount;
  linkedin?: SocialAccount;
  accounts: SocialAccount[];
};

export function accountAvatarUrl(account: Pick<SocialAccount, "platform" | "page_id" | "facebook_page_id">): string | null {
  const pageId = account.platform === "facebook" ? account.page_id : account.facebook_page_id || account.page_id;
  if (!pageId) {
    return null;
  }

  return `https://graph.facebook.com/${pageId}/picture?type=large`;
}

export function socialProfileUrl(account: Pick<SocialAccount, "platform" | "handle" | "page_id">): string | undefined {
  const handle = account.handle?.replace(/^@/, "").trim();
  if (account.platform === "instagram" && handle) return `https://www.instagram.com/${handle}/`;
  if (account.platform === "facebook") {
    if (account.page_id) return `https://www.facebook.com/${account.page_id}`;
    if (handle) return `https://www.facebook.com/${handle}`;
  }
  if (account.platform === "threads" && handle) return `https://www.threads.net/@${handle}`;
  if (account.platform === "linkedin" && account.page_id) return `https://www.linkedin.com/company/${account.page_id}`;
  if ((account.platform === "x" || account.platform === "twitter") && handle) return `https://x.com/${handle}`;
  if (account.platform === "tiktok" && handle) return `https://www.tiktok.com/@${handle}`;
  if (account.platform === "youtube" && handle) return `https://www.youtube.com/@${handle}`;
  return undefined;
}

export function pageGroupKey(account: SocialAccount) {
  return account.facebook_page_id
    || (account.platform === "facebook" ? account.page_id : null)
    || `solo-${account.id}`;
}

export function groupSocialPages(accounts: SocialAccount[]): SocialPageGroup[] {
  const groups = new Map<string, SocialPageGroup>();
  const facebooks = accounts.filter((account) => account.platform === "facebook");

  for (const facebook of facebooks) {
    const key = facebook.facebook_page_id || facebook.page_id || `fb-${facebook.id}`;
    groups.set(key, {
      key,
      name: facebook.name,
      facebook,
      accounts: [facebook],
    });
  }

  for (const account of accounts) {
    if (account.platform === "facebook") continue;

    let matchedFacebook = matchFacebookPage(account, facebooks, groups);
    if (account.platform === "threads" && !matchedFacebook && facebooks.length === 1) {
      matchedFacebook = facebooks[0];
    }

    const key = matchedFacebook
      ? matchedFacebook.facebook_page_id || matchedFacebook.page_id || `fb-${matchedFacebook.id}`
      : pageGroupKey(account);
    attachAccount(groups, key, account, matchedFacebook?.name);
  }

  return [...groups.values()];
}

export function orderedPageAccounts(page: SocialPageGroup): SocialAccount[] {
  const rank = ["facebook", "instagram", "threads", "linkedin"];
  return [...page.accounts].sort((left, right) => {
    const leftRank = rank.indexOf(left.platform);
    const rightRank = rank.indexOf(right.platform);
    return (leftRank < 0 ? 99 : leftRank) - (rightRank < 0 ? 99 : rightRank);
  });
}

function matchFacebookPage(
  account: SocialAccount,
  facebooks: SocialAccount[],
  groups: Map<string, SocialPageGroup>,
): SocialAccount | undefined {
  const byPageId = facebooks.find((facebook) => Boolean(
    account.facebook_page_id
    && (facebook.facebook_page_id === account.facebook_page_id || facebook.page_id === account.facebook_page_id),
  ));
  if (byPageId) return byPageId;

  const byName = facebooks.find((facebook) => (
    (account.platform === "instagram" || account.platform === "threads")
    && facebook.name === account.name
  ));
  if (byName) return byName;

  if (account.platform !== "threads" || !account.handle) return undefined;

  const handle = account.handle.replace(/^@/, "").toLowerCase();
  const byInstagramHandle = [...groups.values()].find((group) => {
    const igHandle = group.instagram?.handle?.replace(/^@/, "").toLowerCase();
    return Boolean(igHandle && igHandle === handle);
  });

  return byInstagramHandle?.facebook;
}

function attachAccount(
  groups: Map<string, SocialPageGroup>,
  key: string,
  account: SocialAccount,
  fallbackName?: string,
): void {
  const current = groups.get(key) ?? { key, name: fallbackName || account.name, accounts: [] };
  if (!current.accounts.some((item) => item.id === account.id)) {
    current.accounts.push(account);
  }
  if (account.platform === "instagram") {
    current.instagram = account;
  }
  if (account.platform === "threads") {
    current.threads = account;
  }
  if (account.platform === "linkedin") {
    current.linkedin = account;
  }
  if (!current.facebook) {
    current.name = account.name;
  }
  groups.set(key, current);
}

export function threadsAccountForPage(page: SocialPageGroup, accounts: SocialAccount[]): SocialAccount | undefined {
  return page.threads ?? accounts.find((account) => account.platform === "threads");
}

export function pagePublishAccountIds(page: SocialPageGroup, accounts: SocialAccount[]): number[] {
  const ids = page.accounts.map((account) => account.id);
  const threads = threadsAccountForPage(page, accounts);
  if (threads && !ids.includes(threads.id)) {
    ids.push(threads.id);
  }

  return ids;
}

export const socialPlatforms = ["facebook", "instagram", "threads", "linkedin", "x", "tiktok", "youtube"] as const;

export function platformLabel(platform: string, t: (c: Copy) => string) {
  if (platform === "instagram") return t(copy.instagram);
  if (platform === "facebook") return t(copy.facebook);
  if (platform === "threads") return t(copy.threads);
  if (platform === "linkedin") return t(copy.linkedin);
  if (platform === "x") return t(copy.xTwitter);
  if (platform === "tiktok") return t(copy.tiktok);
  if (platform === "youtube") return t(copy.youtube);
  if (platform === "pinterest") return t(copy.pinterest);
  return platform;
}

export function pageChannelSummary(page: SocialPageGroup, t: (c: Copy) => string) {
  const labels = [
    page.facebook ? t(copy.facebook) : null,
    page.instagram ? t(copy.instagram) : null,
    page.threads ? t(copy.threads) : null,
    page.linkedin ? t(copy.linkedin) : null,
  ].filter((label): label is string => Boolean(label));

  if (labels.length > 0) {
    return labels.join(" + ");
  }

  return platformLabel(page.accounts[0]?.platform ?? "", t);
}

export function socialPlacementLabel(placement: string | undefined, t: (c: Copy) => string) {
  const key = placement || "feed";
  return socialPlacements[key] ? t(socialPlacements[key]) : key;
}

export function matchesSocialPlacement(post: { placement?: string; media?: { kind: string }[] }, placement: string) {
  const value = post.placement || "feed";
  if (placement === "story") return value === "story";
  if (placement === "reel") {
    return value === "reel" || (value === "feed" && (post.media ?? []).some((item) => item.kind === "video"));
  }
  return value === "feed";
}

export function socialStatusLabel(status: string, t: (c: Copy) => string) {
  return socialStatuses[status] ? t(socialStatuses[status]) : status;
}

export const socialTimezone = "Asia/Damascus";

export function toLocalInput(iso: string | null | undefined) {
  if (!iso) return "";
  const date = new Date(iso);
  if (Number.isNaN(date.getTime())) return "";
  const parts = new Intl.DateTimeFormat("en-CA", {
    timeZone: socialTimezone,
    year: "numeric",
    month: "2-digit",
    day: "2-digit",
    hour: "2-digit",
    minute: "2-digit",
    hourCycle: "h23",
  }).formatToParts(date);
  const pick = (type: string) => parts.find((part) => part.type === type)?.value ?? "00";
  return `${pick("year")}-${pick("month")}-${pick("day")}T${pick("hour")}:${pick("minute")}`;
}

export function fromLocalInput(value: string) {
  return value;
}

export function facebookErrorMessage(error: string, t: (c: Copy) => string) {
  if (error === "no_pages") return t(copy.socialFacebookNoPages);
  if (error === "missing_page_token") return t(copy.socialFacebookMissingPageToken);
  if (error === "page_not_in_token") return t(copy.socialFacebookPageNotInToken);
  if (error === "threads_token_missing") return t(copy.socialThreadsTokenMissing);
  if (error === "threads_profile_missing") return t(copy.socialThreadsError);
  if (error === "facebook_app_user_mismatch" || error.includes("Cannot call API for app")) {
    return t(copy.socialFacebookAppMismatch);
  }
  if (error.includes("graph_timeout") || error.includes("cURL error 28") || error.toLowerCase().includes("resolving timed out")) {
    return t(copy.socialGraphTimeout);
  }
  if (error.includes("facebook_new_pages_text")) {
    return t(copy.socialFacebookNewPagesText);
  }
  if (error.includes("facebook_new_pages") || error.toLowerCase().includes("new pages experience")) {
    return t(copy.socialFacebookNewPages);
  }

  return error.length > 0 ? error : t(copy.socialFacebookError);
}

export function linkedinOauthMessage(code: string, t: (c: Copy) => string) {
  if (code === "denied") return t(copy.socialLinkedinOauthDenied);
  if (code === "invalid_state") return t(copy.socialLinkedinOauthInvalid);
  if (code === "token_exchange") return t(copy.socialLinkedinOauthToken);
  if (code === "no_organizations") return t(copy.socialLinkedinOauthNoOrganizations);
  return t(copy.socialLinkedinOauthInvalid);
}

export function socialAccountStatusLabel(item: { connection_status: string; last_error: string | null }, t: (c: Copy) => string) {
  if (item.connection_status === "error" && item.last_error) {
    return facebookErrorMessage(item.last_error, t);
  }

  return "";
}

export function publishErrorMessage(error: string, t: (c: Copy) => string) {
  if (error.includes(" | ")) {
    return error
      .split(" | ")
      .map((part) => {
        const colon = part.indexOf(": ");
        if (colon > 0) {
          return `${part.slice(0, colon)}: ${publishErrorCode(part.slice(colon + 2), t)}`;
        }
        return publishErrorCode(part, t);
      })
      .join(" · ");
  }

  return publishErrorCode(error, t);
}

function publishErrorCode(error: string, t: (c: Copy) => string) {
  if (error.includes("graph_timeout") || error.includes("cURL error 28") || error.includes("cURL error 6") || error.toLowerCase().includes("resolving timed out") || error.toLowerCase().includes("connection timed out")) {
    return t(copy.socialGraphTimeout);
  }
  if (error.includes("instagram_media_type") || error.toLowerCase().includes("only photo or video") || error.toLowerCase().includes("image_url is required")) {
    return t(copy.socialInstagramMediaType);
  }
  if (error.includes("instagram_media_fetch") || error.includes("2207076") || error.toLowerCase().includes("media upload has failed") || error.toLowerCase().includes("media download has failed")) {
    return t(copy.socialInstagramMediaFetch);
  }
  if (error.includes("instagram_media_processing")) {
    return t(copy.socialInstagramMediaProcessing);
  }
  if (error.includes("threads_media_fetch")) {
    return t(copy.socialThreadsMediaFetch);
  }
  if (error.includes("threads_media_processing")) {
    return t(copy.socialThreadsMediaProcessing);
  }
  if (error.includes("threads_edit_unsupported")) {
    return t(copy.socialThreadsEditUnsupported);
  }
  if (error.includes("threads_token_missing")) {
    return t(copy.socialThreadsTokenMissing);
  }
  if (error.includes("linkedin_missing_permission")) {
    return t(copy.socialLinkedinMissingPermission);
  }
  if (error.includes("linkedin_edit_unsupported")) {
    return t(copy.socialLinkedinEditUnsupported);
  }
  if (error.includes("linkedin_placement_unsupported")) {
    return t(copy.socialLinkedinPlacementUnsupported);
  }
  if (error.includes("linkedin_media_processing")) {
    return t(copy.socialLinkedinMediaProcessing);
  }
  if (error.includes("linkedin_media_fetch") || error.includes("linkedin_media_missing")) {
    return t(copy.socialLinkedinMediaMissing);
  }
  if (error.includes("linkedin_messages_unsupported")) {
    return t(copy.socialLinkedinMessagesUnsupported);
  }
  if (error.includes("linkedin_comment_target_missing")) {
    return t(copy.socialLinkedinCommentTargetMissing);
  }
  if (error.includes("facebook_unsupported_post") || error.toLowerCase().includes("unsupported post request")) {
    return t(copy.socialFacebookUnsupportedPost);
  }
  if (error.includes("Facebook Graph HTTP 400") || error.includes("Facebook Graph HTTP")) {
    return t(copy.socialGraphRejectedUpload);
  }
  if (error.includes("story_needs_media")) {
    return t(copy.socialStoryNeedsMedia);
  }
  if (error.includes("reel_needs_video")) {
    return t(copy.socialReelNeedsVideo);
  }
  if (error.includes("missing_pages_manage_engagement") || error.includes("pages_manage_engagement")) {
    return t(copy.socialNeedEngagePerm);
  }
  if (error.includes("missing_pages_messaging") || error.includes("pages_messaging") || error.includes("missing_message_recipient")) {
    return t(copy.socialNeedMessagingPerm);
  }
  if (error === "facebook_app_user_mismatch" || error.includes("Cannot call API for app")) {
    return t(copy.socialFacebookAppMismatch);
  }
  if (error.includes("facebook_new_pages_text")) {
    return t(copy.socialFacebookNewPagesText);
  }
  if (error.includes("facebook_new_pages") || error.toLowerCase().includes("new pages experience")) {
    return t(copy.socialFacebookNewPages);
  }

  return error;
}

export function inboxErrorMessage(error: string, t: (c: Copy) => string) {
  if (error.includes("missing_pages_manage_engagement") || error.includes("pages_manage_engagement")) {
    return t(copy.socialNeedEngagePerm);
  }
  if (error.includes("missing_pages_messaging") || error.includes("pages_messaging") || error.includes("missing_message_recipient")) {
    return t(copy.socialNeedMessagingPerm);
  }
  if (error === "facebook_app_user_mismatch" || error.includes("Cannot call API for app")) {
    return t(copy.socialFacebookAppMismatch);
  }
  if (error.includes("facebook_new_pages") || error.toLowerCase().includes("new pages experience")) {
    return t(copy.socialFacebookNewPages);
  }
  if (error.includes("linkedin_missing_permission")) {
    return t(copy.socialLinkedinMissingPermission);
  }
  if (error.includes("linkedin_messages_unsupported")) {
    return t(copy.socialLinkedinMessagesUnsupported);
  }
  if (error.includes("linkedin_comment_target_missing")) {
    return t(copy.socialLinkedinCommentTargetMissing);
  }

  return error.length > 0 ? error : t(copy.loading);
}

export function formatWhen(iso: string | null | undefined, locale: string) {
  if (!iso) return "—";
  const date = new Date(iso);
  if (Number.isNaN(date.getTime())) return "—";
  return new Intl.DateTimeFormat(locale === "ar" ? "ar" : "en", {
    timeZone: socialTimezone,
    dateStyle: "medium",
    timeStyle: "short",
  }).format(date);
}
