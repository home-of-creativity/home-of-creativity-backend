import { useEffect, useMemo, useState } from "react";
import { Link } from "react-router-dom";
import { api, type SocialPost } from "../../api";
import { useAuth } from "../../auth";
import { copy, type Locale } from "../../i18n";
import { SocialChrome } from "./SocialChrome";
import { socialStatusLabel } from "./helpers";

function startOfMonth(date: Date) {
  return new Date(date.getFullYear(), date.getMonth(), 1);
}

function isoDate(date: Date) {
  const pad = (value: number) => String(value).padStart(2, "0");
  return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`;
}

export function SocialCalendar({ locale, t }: { locale: Locale; t: (c: { ar: string; en: string }) => string }) {
  const { user } = useAuth();
  const [cursor, setCursor] = useState(() => startOfMonth(new Date()));
  const [items, setItems] = useState<SocialPost[]>([]);
  const [error, setError] = useState("");

  const from = isoDate(cursor);
  const to = isoDate(new Date(cursor.getFullYear(), cursor.getMonth() + 1, 0));

  useEffect(() => {
    api
      .socialPosts({ from, to, page: 1, per_page: 100 })
      .then((res) => {
        setItems(res.data);
        setError("");
      })
      .catch((err) => setError(err instanceof Error ? err.message : t(copy.loading)));
  }, [from, to]);

  const days = useMemo(() => {
    const firstWeekday = cursor.getDay();
    const daysInMonth = new Date(cursor.getFullYear(), cursor.getMonth() + 1, 0).getDate();
    const cells: Array<{ date: Date | null; posts: SocialPost[] }> = [];
    for (let i = 0; i < firstWeekday; i++) cells.push({ date: null, posts: [] });
    for (let day = 1; day <= daysInMonth; day++) {
      const date = new Date(cursor.getFullYear(), cursor.getMonth(), day);
      const key = isoDate(date);
      cells.push({
        date,
        posts: items.filter((item) => {
          const stamp = item.scheduled_at ?? item.published_at ?? item.created_at;
          return stamp ? stamp.slice(0, 10) === key : false;
        }),
      });
    }
    return cells;
  }, [cursor, items]);

  const monthLabel = new Intl.DateTimeFormat(locale === "ar" ? "ar" : "en", { month: "long", year: "numeric" }).format(cursor);

  return (
    <SocialChrome locale={locale} t={t} user={user} title={copy.socialCalendar} lede={copy.socialCalendarLede}>
      <div className="toolbar">
        <button type="button" className="btn" onClick={() => setCursor(new Date(cursor.getFullYear(), cursor.getMonth() - 1, 1))}>
          {t(copy.socialPrevMonth)}
        </button>
        <strong>{monthLabel}</strong>
        <button type="button" className="btn" onClick={() => setCursor(new Date(cursor.getFullYear(), cursor.getMonth() + 1, 1))}>
          {t(copy.socialNextMonth)}
        </button>
      </div>
      {error ? <p className="error">{error}</p> : null}
      <div className="social-calendar card">
        {days.map((cell, index) => (
          <div key={index} className={cell.date ? "social-calendar-day" : "social-calendar-day is-empty"}>
            {cell.date ? <span className="social-calendar-num">{cell.date.getDate()}</span> : null}
            {cell.posts.map((post) => (
              <Link key={post.id} className={`social-calendar-chip status-${post.status}`} to={`/social/compose/${post.id}`}>
                {socialStatusLabel(post.status, t)} · {post.body.slice(0, 28)}
              </Link>
            ))}
          </div>
        ))}
      </div>
    </SocialChrome>
  );
}
