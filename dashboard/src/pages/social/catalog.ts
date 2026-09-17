export type SocialPlatformId =
  | "instagram"
  | "facebook"
  | "threads"
  | "linkedin"
  | "tiktok"
  | "pinterest"
  | "youtube";

export type SocialPlatformDef = {
  id: SocialPlatformId;
  live: boolean;
};

/**
 * Publishing surface catalog. Flip `live` (and add Graph/REST adapters)
 * when a network is ready — Home, picker, and compose all read this list.
 */
export const SOCIAL_PLATFORMS: SocialPlatformDef[] = [
  { id: "instagram", live: true },
  { id: "facebook", live: true },
  { id: "threads", live: true },
  { id: "linkedin", live: true },
  { id: "tiktok", live: false },
  { id: "pinterest", live: false },
  { id: "youtube", live: false },
];

export const LIVE_SOCIAL_PLATFORMS = SOCIAL_PLATFORMS.filter((item) => item.live).map((item) => item.id);

export const STUDIO_HOME_CARDS = [
  { id: "idea", tone: "sky", action: "picker" },
  { id: "calendar", tone: "mint", to: "/social/calendar" },
  { id: "own", tone: "sunset", to: "/social/compose" },
] as const;
