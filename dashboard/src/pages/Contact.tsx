import { useEffect, useMemo, useState, type FormEvent } from "react";
import { useSearchParams } from "react-router-dom";
import { FormDialog } from "../components/FormDialog";
import { LoadingTableRow } from "../components/LoadingTableRow";
import { SocialBrandIcon } from "../components/SocialBrandIcon";
import { api, type ContactChannel } from "../api";
import { copy, type Locale } from "../i18n";

type Tab = "numbers" | "social" | "locations";
const tabs: Tab[] = ["numbers", "social", "locations"];
const platforms = ["instagram", "facebook", "linkedin", "x", "tiktok", "youtube"] as const;

function readTab(value: string | null): Tab {
  return tabs.includes(value as Tab) ? (value as Tab) : "numbers";
}

type NumberForm = {
  kind: "mobile" | "whatsapp";
  region: string;
  value: string;
  digits: string;
  is_published: boolean;
};

const emptyNumber: NumberForm = {
  kind: "mobile",
  region: "SYR",
  value: "",
  digits: "",
  is_published: true,
};

const emptySocial = {
  platform: "instagram",
  value: "Instagram",
  value_ar: "إنستغرام",
  url: "",
  is_published: true,
};

const platformNames: Record<string, { ar: string; en: string }> = {
  instagram: copy.instagram,
  facebook: copy.facebook,
  linkedin: copy.linkedin,
  x: copy.xTwitter,
  tiktok: copy.tiktok,
  youtube: copy.youtube,
};

const emptyLocation = {
  region: "SYR",
  value: "",
  value_ar: "",
  is_published: true,
};

export function Contact({ t }: { locale: Locale; t: (c: { ar: string; en: string }) => string }) {
  const [searchParams, setSearchParams] = useSearchParams();
  const tab = readTab(searchParams.get("tab"));
  const [items, setItems] = useState<ContactChannel[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");
  const [notice, setNotice] = useState("");
  const [showForm, setShowForm] = useState(false);
  const [editingId, setEditingId] = useState<number | null>(null);
  const [movingId, setMovingId] = useState<number | null>(null);
  const [numberForm, setNumberForm] = useState(emptyNumber);
  const [socialForm, setSocialForm] = useState(emptySocial);
  const [locationForm, setLocationForm] = useState(emptyLocation);

  function setTab(next: Tab) {
    resetForm();
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

  function resetForm() {
    setEditingId(null);
    setShowForm(false);
    setNumberForm(emptyNumber);
    setSocialForm(emptySocial);
    setLocationForm(emptyLocation);
    setError("");
  }

  function startAdd() {
    setEditingId(null);
    setNumberForm(emptyNumber);
    setSocialForm(emptySocial);
    setLocationForm(emptyLocation);
    setShowForm(true);
    setError("");
  }

  function startEdit(item: ContactChannel) {
    setEditingId(item.id);
    setShowForm(true);
    setError("");
    if (item.kind === "social") {
      setSocialForm({
        platform: item.platform ?? "instagram",
        value: item.value,
        value_ar: item.value_ar ?? "",
        url: item.url ?? "",
        is_published: item.is_published,
      });
      return;
    }
    if (item.kind === "location") {
      setLocationForm({
        region: item.region ?? "SYR",
        value: item.value,
        value_ar: item.value_ar ?? "",
        is_published: item.is_published,
      });
      return;
    }
    setNumberForm({
      kind: item.kind === "whatsapp" ? "whatsapp" : "mobile",
      region: item.region ?? "SYR",
      value: item.value,
      digits: item.digits ?? "",
      is_published: item.is_published,
    });
  }

  async function submit(event: FormEvent) {
    event.preventDefault();
    setError("");
    setNotice("");
    try {
      const payload =
        tab === "social"
          ? { kind: "social" as const, ...socialForm, url: socialForm.url.trim() }
          : tab === "locations"
            ? { kind: "location" as const, ...locationForm }
            : { ...numberForm, digits: numberForm.digits.replace(/\D+/g, "") };
      if (editingId) await api.updateContactChannel(editingId, payload);
      else await api.createContactChannel(payload);
      resetForm();
      setNotice(
        t(tab === "social" ? copy.saveContactSocial : tab === "locations" ? copy.saveContactLocation : copy.saveContactNumber),
      );
      load();
    } catch (err) {
      setError(err instanceof Error ? err.message : t(copy.savePortfolioFailed));
    }
  }

  async function remove(id: number) {
    if (!window.confirm(t(copy.confirmDelete))) return;
    setError("");
    try {
      await api.deleteContactChannel(id);
      if (editingId === id) resetForm();
      load();
      setNotice(t(copy.deleted));
    } catch (err) {
      setError(err instanceof Error ? err.message : t(copy.savePortfolioFailed));
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
      <header className="page-head">
        <div>
          <p className="eyebrow">{t(copy.brandMark)}</p>
          <h1 className="page-title">{t(copy.contactTitle)}</h1>
          <p className="page-lede">{t(copy.contactLede)}</p>
        </div>
      </header>

      <div className="tabs" role="tablist" aria-label={t(copy.contactTitle)}>
        {tabs.map((entry) => (
          <button
            key={entry}
            type="button"
            role="tab"
            aria-selected={tab === entry}
            className={tab === entry ? "tab is-active" : "tab"}
            onClick={() => setTab(entry)}
          >
            {entry === "social" ? t(copy.contactTabSocial) : entry === "locations" ? t(copy.contactTabLocations) : t(copy.contactTabNumbers)}
          </button>
        ))}
      </div>

      <div className="toolbar">
        <button type="button" className="btn btn-primary" onClick={startAdd}>
          {tab === "social" ? t(copy.addContactSocial) : tab === "locations" ? t(copy.addContactLocation) : t(copy.addContactNumber)}
        </button>
      </div>

      {notice ? <p className="notice">{notice}</p> : null}
      {!showForm && error ? <p className="error">{error}</p> : null}

      {showForm ? (
        <FormDialog
          title={
            editingId
              ? t(copy.edit)
              : tab === "social"
                ? t(copy.addContactSocial)
                : tab === "locations"
                  ? t(copy.addContactLocation)
                  : t(copy.addContactNumber)
          }
          onClose={resetForm}
          onSubmit={submit}
          submitLabel={
            tab === "social" ? t(copy.saveContactSocial) : tab === "locations" ? t(copy.saveContactLocation) : t(copy.saveContactNumber)
          }
          cancelLabel={t(copy.cancel)}
          closeLabel={t(copy.close)}
          error={error}
        >
          <div className="portfolio-form-grid">
            {tab === "numbers" ? (
              <>
                <label className="field-label">
                  {t(copy.contactKind)}
                  <select className="field" value={numberForm.kind} onChange={(e) => setNumberForm((prev) => ({ ...prev, kind: e.target.value as "mobile" | "whatsapp" }))}>
                    <option value="mobile">{t(copy.contactKindMobile)}</option>
                    <option value="whatsapp">{t(copy.contactKindWhatsapp)}</option>
                  </select>
                </label>
                <label className="field-label">
                  {t(copy.contactRegion)}
                  <select className="field" value={numberForm.region} onChange={(e) => setNumberForm((prev) => ({ ...prev, region: e.target.value }))}>
                    <option value="SYR">{t(copy.regionSyr)}</option>
                    <option value="KSA">{t(copy.regionKsa)}</option>
                  </select>
                </label>
                <label className="field-label">
                  {t(copy.contactDisplay)}
                  <input className="field" dir="ltr" value={numberForm.value} onChange={(e) => setNumberForm((prev) => ({ ...prev, value: e.target.value }))} required />
                </label>
                <label className="field-label">
                  {t(copy.contactDigits)}
                  <input className="field" dir="ltr" value={numberForm.digits} onChange={(e) => setNumberForm((prev) => ({ ...prev, digits: e.target.value }))} required />
                </label>
              </>
            ) : null}
            {tab === "social" ? (
              <>
                <label className="field-label">
                  {t(copy.contactKind)}
                  <select
                    className="field"
                    value={socialForm.platform}
                    onChange={(e) => {
                      const platform = e.target.value;
                      const names = platformNames[platform];
                      setSocialForm((prev) => ({
                        ...prev,
                        platform,
                        value: names?.en ?? platform,
                        value_ar: names?.ar ?? platform,
                      }));
                    }}
                  >
                    {platforms.map((platform) => (
                      <option key={platform} value={platform}>
                        {platformLabel(platform)}
                      </option>
                    ))}
                  </select>
                </label>
                <label className="field-label">
                  {t(copy.contactUrl)}
                  <input className="field" dir="ltr" type="url" value={socialForm.url} onChange={(e) => setSocialForm((prev) => ({ ...prev, url: e.target.value }))} required />
                </label>
                <label className="field-label">
                  {t(copy.contactDisplayEn)}
                  <input className="field" value={socialForm.value} onChange={(e) => setSocialForm((prev) => ({ ...prev, value: e.target.value }))} required />
                </label>
                <label className="field-label">
                  {t(copy.contactDisplayAr)}
                  <input className="field" value={socialForm.value_ar} onChange={(e) => setSocialForm((prev) => ({ ...prev, value_ar: e.target.value }))} />
                </label>
              </>
            ) : null}
            {tab === "locations" ? (
              <>
                <label className="field-label">
                  {t(copy.contactRegion)}
                  <select className="field" value={locationForm.region} onChange={(e) => setLocationForm((prev) => ({ ...prev, region: e.target.value }))}>
                    <option value="SYR">{t(copy.regionSyr)}</option>
                    <option value="KSA">{t(copy.regionKsa)}</option>
                  </select>
                </label>
                <label className="field-label">
                  {t(copy.cityEn)}
                  <input className="field" value={locationForm.value} onChange={(e) => setLocationForm((prev) => ({ ...prev, value: e.target.value }))} required />
                </label>
                <label className="field-label">
                  {t(copy.cityAr)}
                  <input className="field" value={locationForm.value_ar} onChange={(e) => setLocationForm((prev) => ({ ...prev, value_ar: e.target.value }))} required />
                </label>
              </>
            ) : null}
            <label className="checkbox-row">
              <input
                type="checkbox"
                checked={tab === "social" ? socialForm.is_published : tab === "locations" ? locationForm.is_published : numberForm.is_published}
                onChange={(e) => {
                  const checked = e.target.checked;
                  if (tab === "social") setSocialForm((prev) => ({ ...prev, is_published: checked }));
                  else if (tab === "locations") setLocationForm((prev) => ({ ...prev, is_published: checked }));
                  else setNumberForm((prev) => ({ ...prev, is_published: checked }));
                }}
              />
              {t(copy.published)}
            </label>
          </div>
        </FormDialog>
      ) : null}

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
                    <button type="button" className="btn btn-ghost" onClick={() => startEdit(item)}>
                      {t(copy.edit)}
                    </button>
                    <button type="button" className="btn btn-ghost" onClick={() => void remove(item.id)}>
                      {t(copy.delete)}
                    </button>
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
