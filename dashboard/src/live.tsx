import { createContext, useCallback, useContext, useEffect, useRef, useState, type ReactNode } from "react";
import { toast } from "sonner";
import { api, type LiveSnapshot } from "./api";
import { copy, socialStatuses, statuses, type Locale } from "./i18n";

const INTERVAL_MS = 4000;

type LiveContextValue = {
  snapshot: LiveSnapshot | null;
  requestsStamp: string;
  socialStamp: string;
};

const LiveContext = createContext<LiveContextValue>({
  snapshot: null,
  requestsStamp: "",
  socialStamp: "",
});

function snippet(value: string | undefined) {
  const text = (value ?? "").replace(/\s+/g, " ").trim();
  if (!text) return "";
  return text.length > 42 ? `${text.slice(0, 42)}…` : text;
}

function toastKey(kind: string, id: number, status: string) {
  return `${kind}:${id}:${status}`;
}

export function LiveFeedProvider({
  locale,
  t,
  children,
}: {
  locale: Locale;
  t: (c: { ar: string; en: string }) => string;
  children: ReactNode;
}) {
  const [snapshot, setSnapshot] = useState<LiveSnapshot | null>(null);
  const seen = useRef(new Set<string>());
  const primed = useRef(false);

  const announce = useCallback(
    (next: LiveSnapshot) => {
      if (!primed.current) {
        next.requests.forEach((item) => seen.current.add(toastKey("request", item.id, item.status)));
        next.posts.forEach((item) => seen.current.add(toastKey("post", item.id, item.status)));
        primed.current = true;
        return;
      }

      next.requests.forEach((item) => {
        const key = toastKey("request", item.id, item.status);
        if (seen.current.has(key)) return;
        seen.current.add(key);
        const label = t(statuses[item.status] ?? { ar: item.status, en: item.status });
        toast.message(t(copy.liveRequestChanged).replace("{number}", item.number || `#${item.id}`), {
          description: label,
        });
      });

      next.posts.forEach((item) => {
        const key = toastKey("post", item.id, item.status);
        if (seen.current.has(key)) return;
        seen.current.add(key);
        const caption = snippet(item.body);
        const label = t(socialStatuses[item.status] ?? { ar: item.status, en: item.status });
        if (item.status === "published") {
          toast.success(t(copy.livePostPublished), { description: caption || label });
          return;
        }
        if (item.status === "failed") {
          toast.error(t(copy.livePostFailed), { description: caption || label });
          return;
        }
        if (item.status === "publishing") {
          toast.message(t(copy.livePostPublishing), { description: caption || label });
        }
      });
    },
    [t],
  );

  useEffect(() => {
    let cancelled = false;

    function tick() {
      if (document.hidden) return;
      api
        .live()
        .then((res) => {
          if (cancelled) return;
          announce(res.data);
          setSnapshot(res.data);
        })
        .catch(() => {});
    }

    tick();
    const timer = window.setInterval(tick, INTERVAL_MS);
    const onVisible = () => {
      if (!document.hidden) tick();
    };
    document.addEventListener("visibilitychange", onVisible);
    return () => {
      cancelled = true;
      window.clearInterval(timer);
      document.removeEventListener("visibilitychange", onVisible);
    };
  }, [announce, locale]);

  return (
    <LiveContext.Provider
      value={{
        snapshot,
        requestsStamp: snapshot?.requests_stamp ?? "",
        socialStamp: snapshot?.social_stamp ?? "",
      }}
    >
      {children}
    </LiveContext.Provider>
  );
}

export function useLive() {
  return useContext(LiveContext);
}

export function useLiveStamp(stamp: string, onChange: () => void) {
  const previous = useRef("");

  useEffect(() => {
    if (!stamp) return;
    if (!previous.current) {
      previous.current = stamp;
      return;
    }
    if (previous.current === stamp) return;
    previous.current = stamp;
    onChange();
  }, [stamp, onChange]);
}
