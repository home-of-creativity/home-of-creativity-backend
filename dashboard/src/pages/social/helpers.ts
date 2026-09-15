import { copy, socialPlacements, socialStatuses, type Copy } from "../../i18n";
import type { SocialAccount } from "../../api";

export type SocialPageGroup = {
  key: string;
  name: string;
  facebook?: SocialAccount;
  instagram?: SocialAccount;
  accounts: SocialAccount[];
};

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

    const matchedFacebook = facebooks.find((facebook) => {
      if (account.facebook_page_id && (facebook.facebook_page_id === account.facebook_page_id || facebook.page_id === account.facebook_page_id)) {
        return true;
      }
      return account.platform === "instagram" && facebook.name === account.name;
    });
    const key = matchedFacebook
      ? matchedFacebook.facebook_page_id || matchedFacebook.page_id || `fb-${matchedFacebook.id}`
      : pageGroupKey(account);
    const current = groups.get(key) ?? { key, name: account.name, accounts: [] };
    current.accounts.push(account);
    if (account.platform === "instagram") {
      current.instagram = account;
    }
    if (!current.facebook) {
      current.name = account.name;
    }
    groups.set(key, current);
  }

  return [...groups.values()];
}

export const socialPlatforms = ["facebook", "instagram", "linkedin", "x", "tiktok", "youtube"] as const;

export function platformLabel(platform: string, t: (c: Copy) => string) {
  if (platform === "instagram") return t(copy.instagram);
  if (platform === "facebook") return t(copy.facebook);
  if (platform === "linkedin") return t(copy.linkedin);
  if (platform === "x") return t(copy.xTwitter);
  if (platform === "tiktok") return t(copy.tiktok);
  if (platform === "youtube") return t(copy.youtube);
  return platform;
}

export function socialPlacementLabel(placement: string | undefined, t: (c: Copy) => string) {
  const key = placement || "feed";
  return socialPlacements[key] ? t(socialPlacements[key]) : key;
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
  if (error === "facebook_app_user_mismatch" || error.includes("Cannot call API for app")) {
    return t(copy.socialFacebookAppMismatch);
  }

  return error.length > 0 ? error : t(copy.socialFacebookError);
}

export function socialAccountStatusLabel(item: { connection_status: string; last_error: string | null }, t: (c: Copy) => string) {
  if (item.connection_status === "error" && item.last_error) {
    return facebookErrorMessage(item.last_error, t);
  }

  return "";
}

export function publishErrorMessage(error: string, t: (c: Copy) => string) {
  if (error.includes("instagram_media_type") || error.toLowerCase().includes("only photo or video") || error.toLowerCase().includes("image_url is required")) {
    return t(copy.socialInstagramMediaType);
  }
  if (error.includes("instagram_media_fetch") || error.includes("2207076") || error.toLowerCase().includes("media upload has failed") || error.toLowerCase().includes("media download has failed")) {
    return t(copy.socialInstagramMediaFetch);
  }
  if (error.includes("instagram_media_processing")) {
    return t(copy.socialInstagramMediaProcessing);
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
