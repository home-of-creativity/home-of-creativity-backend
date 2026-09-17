import { useEffect, useState } from "react";
import { Link } from "react-router-dom";
import { toast } from "sonner";
import { ConfirmAction } from "../../components/ConfirmAction";
import { LoadingTableRow } from "../../components/LoadingTableRow";
import { api, type PricingCategory } from "../../api";
import { copy, type Locale } from "../../i18n";
import { categoryLabel } from "./utils";

export function PricingCategoriesPanel({
  locale,
  t,
}: {
  locale: Locale;
  t: (c: { ar: string; en: string }) => string;
}) {
  const [items, setItems] = useState<PricingCategory[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");
  const [notice, setNotice] = useState("");
  const [movingId, setMovingId] = useState<number | null>(null);

  function load() {
    setLoading(true);
    api
      .pricingCategories()
      .then((res) => setItems(res.data))
      .catch(() => setItems([]))
      .finally(() => setLoading(false));
  }

  useEffect(() => {
    load();
  }, []);

  async function remove(id: number) {
    setError("");
    try {
      await api.deletePricingCategory(id);
      load();
      setNotice(t(copy.deleted));
      toast.success(t(copy.deleteSuccess));
    } catch (err) {
      const message = err instanceof Error ? err.message : t(copy.savePortfolioFailed);
      setError(message);
      toast.error(message);
    }
  }

  async function removeAll() {
    if (!window.confirm(t(copy.confirmDeleteAll))) return;
    setError("");
    try {
      await api.deleteAllPricingCategories();
      load();
    } catch (err) {
      setError(err instanceof Error ? err.message : t(copy.savePortfolioFailed));
    }
  }

  async function move(id: number, direction: "up" | "down") {
    setError("");
    setNotice("");
    setMovingId(id);
    try {
      const res = await api.movePricingCategory(id, direction);
      setItems(res.data);
    } catch (err) {
      setError(err instanceof Error ? err.message : t(copy.savePortfolioFailed));
    } finally {
      setMovingId(null);
    }
  }

  return (
    <>
      <div className="toolbar">
        <Link className="btn btn-primary" to="/pricing/categories/new">
          {t(copy.addPricingCategory)}
        </Link>
        <button type="button" className="btn btn-ghost" onClick={() => void removeAll()} disabled={items.length === 0}>
          {t(copy.deleteAll)}
        </button>
      </div>

      {notice ? <p className="muted">{notice}</p> : null}
      {error ? <p className="error">{error}</p> : null}

      <div className="table-wrap">
        <table>
          <thead>
            <tr>
              <th>{t(copy.title)}</th>
              <th>{t(copy.slug)}</th>
              <th>{t(copy.pricingSubcategoriesCount)}</th>
              <th>{t(copy.paymentPlan)}</th>
              <th>{t(copy.order)}</th>
              <th>{t(copy.published)}</th>
              <th>{t(copy.actions)}</th>
            </tr>
          </thead>
          <tbody>
            {loading ? (
              <LoadingTableRow colSpan={7} label={t(copy.loading)} />
            ) : items.length === 0 ? (
              <tr>
                <td colSpan={7}>{t(copy.empty)}</td>
              </tr>
            ) : (
              items.map((item, index) => (
                <tr key={item.id}>
                  <td>{categoryLabel(item, locale)}</td>
                  <td>{item.slug}</td>
                  <td>{item.subcategories_count ?? 0}</td>
                  <td>
                    <span className={`pay-badge ${item.requires_full_payment ? "pay-badge-full" : "pay-badge-partial"}`}>
                      {item.requires_full_payment ? t(copy.fullPayment) : t(copy.partialPayment)}
                    </span>
                    <span className={`renew-badge ${item.allows_renewal ? "renew-badge-on" : "renew-badge-off"}`}>
                      {item.allows_renewal ? t(copy.renewalOn) : t(copy.renewalOff)}
                    </span>
                  </td>
                  <td>
                    <div className="order-actions">
                      <button type="button" className="btn btn-order" aria-label={t(copy.moveUp)} disabled={index === 0 || movingId === item.id} onClick={() => void move(item.id, "up")}>↑</button>
                      <button type="button" className="btn btn-order" aria-label={t(copy.moveDown)} disabled={index === items.length - 1 || movingId === item.id} onClick={() => void move(item.id, "down")}>↓</button>
                    </div>
                  </td>
                  <td>{item.is_published ? t(copy.published) : t(copy.inactive)}</td>
                  <td className="actions-cell">
                    <Link className="btn btn-ghost" to={`/pricing/categories/${item.id}/edit`}>{t(copy.edit)}</Link>
                    <ConfirmAction label={t(copy.delete)} yesLabel={t(copy.delete)} noLabel={t(copy.cancel)} onConfirm={() => void remove(item.id)} />
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
