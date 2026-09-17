import { useEffect, useMemo, useState } from "react";
import { Link, useSearchParams } from "react-router-dom";
import { toast } from "sonner";
import { ConfirmAction } from "../components/ConfirmAction";
import { LoadingTableRow } from "../components/LoadingTableRow";
import { PageHeader } from "../components/PageHeader";
import { SocialBrandIcon } from "../components/SocialBrandIcon";
import { Tabs } from "../components/Tabs";
import { api, type ContactChannel } from "../api";
import { copy, type Locale } from "../i18n";

type Tab = "numbers" | "social" | "locations";
const tabs: Tab[] = ["numbers", "social", "locations"];

function readTab(value: string | null): Tab {
  return tabs.includes(value as Tab) ? (value as Tab) : "numbers";
}

export function Contact({ t }: { locale: Locale; t: (c: { ar: string; en: string }) => string }) {
  const [searchParams, setSearchParams] = useSearchParams();
  const tab = readTab(searchParams.get("tab"));
  const [items, setItems] = useState<ContactChannel[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");
  const [notice, setNotice] = useState("");
  const [movingId, setMovingId] = useState<number | null>(null);

  function setTab(next: Tab) {
    if (next === "numbers") {
      setSearchParams({});
      return;
    }
    setSearchParams({ tab: next });
  }

  function load() {
    setLoading(true);
    api
      .contactChannels()
      .then((res) => setItems(res.data))
      .catch(() => setItems([]))
      .finally(() => setLoading(false));
  }

  useEffect(() => {
    load();
  }, []);

  const visible = useMemo(() => {
    if (tab === "social") return items.filter((item) => item.kind === "social");
    if (tab === "locations") return items.filter((item) => item.kind === "location");
    return items.filter((item) => item.kind === "mobile" || item.kind === "whatsapp");
  }, [items, tab]);

  async function remove(id: number) {
    setError("");
    try {
      await api.deleteContactChannel(id);
      load();
      setNotice(t(copy.deleted));
      toast.success(t(copy.deleteSuccess));
    } catch (err) {
      const message = err instanceof Error ? err.message : t(copy.savePortfolioFailed);
      setError(message);
      toast.error(message);
    }
  }

  async function move(id: number, direction: "up" | "down") {
    setMovingId(id);
    try {
      const res = await api.moveContactChannel(id, direction);
      setItems(res.data);
    } catch (err) {
      setError(err instanceof Error ? err.message : t(copy.savePortfolioFailed));
    } finally {
      setMovingId(null);
    }
  }

  function kindLabel(kind: string) {
    if (kind === "whatsapp") return t(copy.contactKindWhatsapp);
    if (kind === "mobile") return t(copy.contactKindMobile);
    return kind;
  }

  function platformLabel(platform: string | null) {
    if (platform === "instagram") return t(copy.instagram);
    if (platform === "facebook") return t(copy.facebook);
    if (platform === "linkedin") return t(copy.linkedin);
    if (platform === "x") return t(copy.xTwitter);
    if (platform === "tiktok") return t(copy.tiktok);
    if (platform === "youtube") return t(copy.youtube);
    return platform ?? "—";
  }

  function regionLabel(region: string | null) {
    if (region === "KSA") return t(copy.regionKsa);
    if (region === "SYR") return t(copy.regionSyr);
    return region ?? "—";
  }

  return (
    <>
      <PageHeader
        eyebrow={t(copy.brandMark)}
        title={t(copy.contactTitle)}
        lede={t(copy.contactLede)}
        actions={
          <Link className="btn btn-primary" to={`/contact/new?tab=${tab}`}>
            {tab === "social" ? t(copy.addContactSocial) : tab === "locations" ? t(copy.addContactLocation) : t(copy.addContactNumber)}
          </Link>
        }
      />

      <Tabs
        value={tab}
        onValueChange={(next) => setTab(next as Tab)}
        ariaLabel={t(copy.contactTitle)}
        items={tabs.map((entry) => ({
          value: entry,
          label: entry === "social" ? t(copy.contactTabSocial) : entry === "locations" ? t(copy.contactTabLocations) : t(copy.contactTabNumbers),
        }))}
      />

      {notice ? <p className="notice">{notice}</p> : null}
      {error ? <p className="error">{error}</p> : null}

      <div className="table-wrap">
        <table>
          <thead>
            <tr>
              {tab === "numbers" ? (
                <>
                  <th>{t(copy.contactKind)}</th>
                  <th>{t(copy.contactRegion)}</th>
                  <th>{t(copy.contactDisplay)}</th>
                  <th>{t(copy.contactDigits)}</th>
                </>
              ) : null}
              {tab === "social" ? (
                <>
                  <th>{t(copy.contactKind)}</th>
                  <th>{t(copy.contactUrl)}</th>
                </>
              ) : null}
              {tab === "locations" ? (
                <>
                  <th>{t(copy.contactRegion)}</th>
                  <th>{t(copy.cityEn)}</th>
                  <th>{t(copy.cityAr)}</th>
                </>
              ) : null}
              <th>{t(copy.published)}</th>
              <th>{t(copy.order)}</th>
              <th>{t(copy.actions)}</th>
            </tr>
          </thead>
          <tbody>
            {loading ? (
              <LoadingTableRow colSpan={7} label={t(copy.loading)} />
            ) : visible.length === 0 ? (
              <tr>
                <td colSpan={7}>{t(copy.empty)}</td>
              </tr>
            ) : (
              visible.map((item, index) => (
                <tr key={item.id}>
                  {tab === "numbers" ? (
                    <>
                      <td>{kindLabel(item.kind)}</td>
                      <td>{regionLabel(item.region)}</td>
                      <td dir="ltr">{item.value}</td>
                      <td dir="ltr">{item.digits}</td>
                    </>
                  ) : null}
                  {tab === "social" ? (
                    <>
                      <td>
                        <span className="social-platform">
                          {item.platform ? <SocialBrandIcon platform={item.platform} /> : null}
                          {platformLabel(item.platform)}
                        </span>
                      </td>
                      <td dir="ltr">
                        {item.url ? (
                          <a href={item.url} target="_blank" rel="noreferrer">
                            {item.url}
                          </a>
                        ) : (
                          "—"
                        )}
                      </td>
                    </>
                  ) : null}
                  {tab === "locations" ? (
                    <>
                      <td>{regionLabel(item.region)}</td>
                      <td>{item.value}</td>
                      <td>{item.value_ar}</td>
                    </>
                  ) : null}
                  <td>{item.is_published ? t(copy.published) : t(copy.inactive)}</td>
                  <td>
                    <div className="order-actions">
                      <button type="button" className="btn-order" disabled={movingId === item.id || index === 0} onClick={() => void move(item.id, "up")} aria-label={t(copy.moveUp)}>
                        ↑
                      </button>
                      <button type="button" className="btn-order" disabled={movingId === item.id || index === visible.length - 1} onClick={() => void move(item.id, "down")} aria-label={t(copy.moveDown)}>
                        ↓
                      </button>
                    </div>
                  </td>
                  <td className="actions-cell">
                    <Link className="btn btn-ghost" to={`/contact/${item.id}/edit`}>
                      {t(copy.edit)}
                    </Link>
                    <ConfirmAction
                      label={t(copy.delete)}
                      yesLabel={t(copy.delete)}
                      noLabel={t(copy.cancel)}
                      onConfirm={() => void remove(item.id)}
                    />
                  </td>
                </tr>
              ))
            )}
          </tbody>
        </table>
      </div>
    </>
  );
}
