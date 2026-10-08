import { useEffect, useMemo, useRef, useState, type FormEvent, type PointerEvent } from "react";
import { Link, useNavigate, useParams, useSearchParams } from "react-router-dom";
import { ArrowLeft, ArrowRight, Globe, Mail, MapPin, Phone, Printer } from "lucide-react";
import { toast } from "sonner";
import { api, canAbility, type FinancialVoucher, type VoucherKind, type VoucherLine, type VoucherTemplate, type VoucherTemplateSummary } from "../api";
import { useAuth } from "../auth";
import { ConfirmAction } from "../components/ConfirmAction";
import { LoadingLottie } from "../components/LoadingLottie";
import { copy, type Locale } from "../i18n";

export const voucherKindCopy: Record<VoucherKind, { name: { ar: string; en: string }; hint: { ar: string; en: string } }> = {
  delivery: { name: copy.voucherDelivery, hint: copy.voucherDeliveryHint },
  receipt: { name: copy.voucherReceipt, hint: copy.voucherReceiptHint },
  payment: { name: copy.voucherPayment, hint: copy.voucherPaymentHint },
  journal: { name: copy.voucherJournal, hint: copy.voucherJournalHint },
  settlement: { name: copy.voucherSettlement, hint: copy.voucherSettlementHint },
};

const KINDS: VoucherKind[] = ["delivery", "receipt", "payment", "journal", "settlement"];

type LineDraft = { memo: string; debit: string; credit: string };

type Draft = {
  kind: VoucherKind;
  party_name: string;
  amount: string;
  currency: "USD" | "SYP";
  amount_words: string;
  issued_on: string;
  purpose: string;
  reference: string;
  lines: LineDraft[];
  signer_name: string;
  counter_signer_name: string;
  signature: string;
  counter_signature: string;
  background: string;
};

function today() {
  const now = new Date();
  const local = new Date(now.getTime() - now.getTimezoneOffset() * 60000);
  return local.toISOString().slice(0, 10);
}

function blankLines(): LineDraft[] {
  return Array.from({ length: 4 }, () => ({ memo: "", debit: "", credit: "" }));
}

function emptyDraft(kind: VoucherKind): Draft {
  return {
    kind,
    party_name: "",
    amount: "",
    currency: "USD",
    amount_words: "",
    issued_on: today(),
    purpose: "",
    reference: "",
    lines: blankLines(),
    signer_name: "",
    counter_signer_name: "",
    signature: "",
    counter_signature: "",
    background: "",
  };
}

function fromTemplate(template: VoucherTemplate): Draft {
  const data = template.data;
  const lines = data.lines && data.lines.length > 0
    ? data.lines.map((line) => ({
      memo: line.memo,
      debit: line.debit ? String(line.debit) : "",
      credit: line.credit ? String(line.credit) : "",
    }))
    : blankLines();
  return {
    kind: template.kind,
    party_name: data.party_name ?? "",
    amount: data.amount ? String(data.amount) : "",
    currency: data.currency === "SYP" ? "SYP" : "USD",
    amount_words: data.amount_words ?? "",
    issued_on: today(),
    purpose: data.purpose ?? "",
    reference: data.reference ?? "",
    lines,
    signer_name: data.signer_name ?? "",
    counter_signer_name: data.counter_signer_name ?? "",
    signature: "",
    counter_signature: "",
    background: data.background ?? "",
  };
}

function readBackground(file: File): Promise<string> {
  return new Promise((resolve, reject) => {
    const url = URL.createObjectURL(file);
    const img = new Image();
    img.onload = () => {
      const max = 1400;
      const scale = Math.min(1, max / Math.max(img.width, img.height));
      const canvas = document.createElement("canvas");
      canvas.width = Math.max(1, Math.round(img.width * scale));
      canvas.height = Math.max(1, Math.round(img.height * scale));
      const ctx = canvas.getContext("2d");
      URL.revokeObjectURL(url);
      if (!ctx) {
        reject(new Error("canvas"));
        return;
      }
      ctx.drawImage(img, 0, 0, canvas.width, canvas.height);
      resolve(canvas.toDataURL("image/jpeg", 0.82));
    };
    img.onerror = () => {
      URL.revokeObjectURL(url);
      reject(new Error("image"));
    };
    img.src = url;
  });
}

function fromVoucher(voucher: FinancialVoucher): Draft {
  const lines = voucher.lines.length > 0
    ? voucher.lines.map((line) => ({
      memo: line.memo,
      debit: line.debit ? String(line.debit) : "",
      credit: line.credit ? String(line.credit) : "",
    }))
    : blankLines();
  return {
    kind: voucher.kind,
    party_name: voucher.party_name,
    amount: voucher.amount ? String(voucher.amount) : "",
    currency: voucher.currency,
    amount_words: voucher.amount_words ?? "",
    issued_on: voucher.issued_on ?? today(),
    purpose: voucher.purpose ?? "",
    reference: voucher.reference ?? "",
    lines,
    signer_name: voucher.signer_name ?? "",
    counter_signer_name: voucher.counter_signer_name ?? "",
    signature: voucher.signature ?? "",
    counter_signature: voucher.counter_signature ?? "",
    background: voucher.background ?? "",
  };
}

function money(amount: number, currency: string) {
  return `${amount.toLocaleString("en-US", { minimumFractionDigits: 2, maximumFractionDigits: 2 })} ${currency}`;
}

function figures(amount: number, currency: string) {
  const value = amount.toLocaleString("en-US", { maximumFractionDigits: 2 });
  return currency === "USD" ? `${value}$` : `${value} ${currency}`;
}

function shortSerial(serial: string) {
  const digits = serial.match(/(\d+)$/)?.[1] ?? "";
  return digits ? `N${digits.slice(-4).padStart(4, "0")}` : "N0001";
}

function showDate(value: string) {
  const [year, month, day] = value.split("-");
  if (!year || !month || !day) return value;
  return `${day}/${month}/${year}`;
}

function SignaturePad({
  label,
  clearLabel,
  value,
  onChange,
  disabled,
}: {
  label: string;
  clearLabel: string;
  value: string;
  onChange: (next: string) => void;
  disabled?: boolean;
}) {
  const canvasRef = useRef<HTMLCanvasElement>(null);
  const drawing = useRef(false);
  const last = useRef<{ x: number; y: number } | null>(null);

  useEffect(() => {
    const canvas = canvasRef.current;
    const ctx = canvas?.getContext("2d");
    if (!canvas || !ctx) return;
    const ratio = window.devicePixelRatio || 1;
    const width = canvas.clientWidth || 320;
    const height = canvas.clientHeight || 140;
    canvas.width = Math.floor(width * ratio);
    canvas.height = Math.floor(height * ratio);
    ctx.setTransform(ratio, 0, 0, ratio, 0, 0);
    ctx.fillStyle = "#ffffff";
    ctx.fillRect(0, 0, width, height);
    ctx.strokeStyle = "#1a0838";
    ctx.lineWidth = 2.1;
    ctx.lineCap = "round";
    ctx.lineJoin = "round";
    if (!value) return;
    const img = new Image();
    img.onload = () => ctx.drawImage(img, 0, 0, width, height);
    img.src = value;
  }, [value]);

  function point(event: PointerEvent<HTMLCanvasElement>) {
    const rect = event.currentTarget.getBoundingClientRect();
    return { x: event.clientX - rect.left, y: event.clientY - rect.top };
  }

  function down(event: PointerEvent<HTMLCanvasElement>) {
    if (disabled) return;
    drawing.current = true;
    last.current = point(event);
    event.currentTarget.setPointerCapture(event.pointerId);
  }

  function move(event: PointerEvent<HTMLCanvasElement>) {
    if (!drawing.current || !last.current) return;
    const ctx = canvasRef.current?.getContext("2d");
    if (!ctx) return;
    const next = point(event);
    ctx.beginPath();
    ctx.moveTo(last.current.x, last.current.y);
    ctx.lineTo(next.x, next.y);
    ctx.stroke();
    last.current = next;
  }

  function up() {
    if (!drawing.current) return;
    drawing.current = false;
    last.current = null;
    onChange(canvasRef.current?.toDataURL("image/png") ?? "");
  }

  return (
    <div className="signature-pad">
      <div className="signature-pad-head">
        <span>{label}</span>
        <button type="button" className="btn btn-ghost btn-sm" disabled={disabled || !value} onClick={() => onChange("")}>
          {clearLabel}
        </button>
      </div>
      <canvas
        ref={canvasRef}
        className="signature-canvas"
        aria-label={label}
        onPointerDown={down}
        onPointerMove={move}
        onPointerUp={up}
        onPointerCancel={up}
      />
    </div>
  );
}

export function VoucherStudio({ locale, t }: { locale: Locale; t: (c: { ar: string; en: string }) => string }) {
  const { user } = useAuth();
  const navigate = useNavigate();
  const params = useParams();
  const [search] = useSearchParams();
  const voucherId = params.id ? Number(params.id) : null;
  const requestedKind = search.get("kind");
  const templateQuery = search.get("template");
  const BackIcon = locale === "ar" ? ArrowRight : ArrowLeft;
  const canWrite = voucherId ? canAbility(user, "ops.vouchers.update") : canAbility(user, "ops.vouchers.create");
  const [phase, setPhase] = useState<"loading" | "template" | "edit" | "error">(voucherId ? "loading" : "template");
  const [serial, setSerial] = useState("");
  const [draft, setDraft] = useState<Draft | null>(null);
  const [templates, setTemplates] = useState<VoucherTemplateSummary[]>([]);
  const [templateName, setTemplateName] = useState("");
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState("");
  const tRef = useRef(t);
  tRef.current = t;

  useEffect(() => {
    if (!voucherId && !templateQuery && !KINDS.includes(requestedKind as VoucherKind)) {
      api.voucherTemplates()
        .then((res) => setTemplates(res.data))
        .catch(() => setTemplates([]));
    }
  }, [voucherId, requestedKind, templateQuery]);

  useEffect(() => {
    if (!voucherId) {
      const templateId = Number(templateQuery);
      if (templateId > 0) {
        let cancelled = false;
        setPhase("loading");
        api.voucherTemplate(templateId)
          .then((res) => {
            if (cancelled) return;
            setSerial("");
            setDraft(fromTemplate(res.data));
            setTemplateName(res.data.name);
            setPhase("edit");
          })
          .catch((err) => {
            if (cancelled) return;
            setError(err instanceof Error ? err.message : tRef.current(copy.saveFailed));
            setPhase("error");
          });
        return () => {
          cancelled = true;
        };
      }
      if (KINDS.includes(requestedKind as VoucherKind)) {
        setDraft((current) => (current?.kind === requestedKind ? current : emptyDraft(requestedKind as VoucherKind)));
        setPhase("edit");
      } else {
        setDraft(null);
        setPhase("template");
      }
      return;
    }
    let cancelled = false;
    setPhase("loading");
    api.voucher(voucherId)
      .then((res) => {
        if (cancelled) return;
        setSerial(res.data.serial);
        setDraft(fromVoucher(res.data));
        setPhase("edit");
      })
      .catch((err) => {
        if (cancelled) return;
        setError(err instanceof Error ? err.message : tRef.current(copy.saveFailed));
        setPhase("error");
      });
    return () => {
      cancelled = true;
    };
  }, [voucherId, requestedKind, templateQuery]);

  const totals = useMemo(() => {
    const lines = draft?.lines ?? [];
    const debit = lines.reduce((sum, line) => sum + (Number(line.debit) || 0), 0);
    const credit = lines.reduce((sum, line) => sum + (Number(line.credit) || 0), 0);
    return { debit, credit };
  }, [draft?.lines]);

  function patch(partial: Partial<Draft>) {
    setDraft((current) => (current ? { ...current, ...partial } : current));
  }

  function patchLine(index: number, partial: Partial<LineDraft>) {
    setDraft((current) => {
      if (!current) return current;
      const lines = current.lines.map((line, i) => (i === index ? { ...line, ...partial } : line));
      return { ...current, lines };
    });
  }

  async function save(event: FormEvent) {
    event.preventDefault();
    if (!draft || !canWrite) return;
    if (!draft.party_name.trim()) {
      setError(t(copy.voucherPartyRequired));
      return;
    }
    const amount = Number(draft.amount) || 0;
    const deliveryAmount = totals.debit > 0 ? totals.debit : amount;
    if ((draft.kind === "receipt" || draft.kind === "payment" || draft.kind === "delivery") && (draft.kind === "delivery" ? deliveryAmount : amount) <= 0) {
      setError(t(copy.voucherAmountRequired));
      return;
    }
    const lines: VoucherLine[] = draft.lines
      .map((line) => ({
        memo: line.memo.trim(),
        debit: Number(line.debit) || 0,
        credit: Number(line.credit) || 0,
      }))
      .filter((line) => line.memo || line.debit || line.credit);
    if (draft.kind === "journal" && (lines.length === 0 || Math.abs(totals.debit - totals.credit) > 0.009)) {
      setError(t(copy.voucherLineBalance));
      return;
    }
    setError("");
    setBusy(true);
    try {
      const res = await api.saveVoucher({
        kind: draft.kind,
        party_name: draft.party_name.trim(),
        amount: draft.kind === "journal" ? totals.debit : draft.kind === "delivery" ? deliveryAmount : amount,
        currency: draft.currency,
        amount_words: draft.amount_words.trim() || undefined,
        issued_on: draft.issued_on,
        purpose: draft.purpose.trim() || undefined,
        reference: draft.reference.trim() || undefined,
        lines,
        signer_name: (draft.signer_name.trim() || (draft.kind === "delivery" ? draft.party_name.trim() : "")) || undefined,
        counter_signer_name: draft.counter_signer_name.trim() || undefined,
        signature: draft.signature || undefined,
        counter_signature: draft.counter_signature || undefined,
        background: draft.background || undefined,
      }, voucherId ?? undefined);
      setSerial(res.data.serial);
      toast.success(res.message ?? t(copy.voucherSaved));
      if (!voucherId) navigate(`/vouchers/${res.data.id}`, { replace: true });
    } catch (err) {
      setError(err instanceof Error ? err.message : t(copy.saveFailed));
    } finally {
      setBusy(false);
    }
  }

  async function saveTemplate() {
    if (!draft || !canWrite) return;
    const name = templateName.trim();
    if (name === "") {
      setError(t(copy.voucherTemplateNameRequired));
      return;
    }
    const lines: VoucherLine[] = draft.lines
      .map((line) => ({
        memo: line.memo.trim(),
        debit: Number(line.debit) || 0,
        credit: Number(line.credit) || 0,
      }))
      .filter((line) => line.memo || line.debit || line.credit);
    const lineSum = totals.debit;
    setError("");
    setBusy(true);
    try {
      await api.saveVoucherTemplate({
        name,
        kind: draft.kind,
        party_name: draft.party_name.trim(),
        amount: draft.kind === "journal" || draft.kind === "delivery"
          ? (lineSum > 0 ? lineSum : Number(draft.amount) || 0)
          : Number(draft.amount) || 0,
        currency: draft.currency,
        amount_words: draft.amount_words.trim() || undefined,
        purpose: draft.purpose.trim() || undefined,
        reference: draft.reference.trim() || undefined,
        lines,
        signer_name: draft.signer_name.trim() || undefined,
        counter_signer_name: draft.counter_signer_name.trim() || undefined,
        background: draft.background || undefined,
      });
      toast.success(t(copy.voucherTemplateSaved));
    } catch (err) {
      setError(err instanceof Error ? err.message : t(copy.saveFailed));
    } finally {
      setBusy(false);
    }
  }

  function removeTemplate(id: number) {
    api.deleteVoucherTemplate(id)
      .then(() => {
        setTemplates((current) => current.filter((row) => row.id !== id));
        toast.success(t(copy.voucherTemplateDeleted));
      })
      .catch((err) => toast.error(err instanceof Error ? err.message : t(copy.saveFailed)));
  }

  if (phase === "loading") return <LoadingLottie variant="page" label={t(copy.loading)} />;

  if (phase === "error") {
    return (
      <section className="empty-state">
        <h1>{error}</h1>
        <Link className="btn" to="/vouchers">{t(copy.voucherBack)}</Link>
      </section>
    );
  }

  if (phase === "template" || !draft) {
    return (
      <section className="report-start">
        <Link className="back-link" to="/vouchers"><BackIcon size={16} aria-hidden="true" />{t(copy.voucherBack)}</Link>
        <header className="report-start-head">
          <h1>{t(copy.voucherTemplateTitle)}</h1>
          <p>{t(copy.voucherTemplateLede)}</p>
        </header>
        <div className="template-grid">
          {KINDS.map((kind) => (
            <button key={kind} type="button" className={`template-card is-${kind}`} onClick={() => navigate(`/vouchers/new?kind=${kind}`)}>
              <span className="template-sheet" aria-hidden="true"><i /><i /><i /><i /></span>
              <strong>{t(voucherKindCopy[kind].name)}</strong>
              <span>{t(voucherKindCopy[kind].hint)}</span>
            </button>
          ))}
        </div>
        <h2 className="section-title">{t(copy.voucherSavedTemplates)}</h2>
        {templates.length === 0 ? <p className="muted">{t(copy.voucherNoTemplates)}</p> : (
          <div className="template-grid">
            {templates.map((template) => (
              <div key={template.id} className="template-card is-saved">
                <button type="button" onClick={() => navigate(`/vouchers/new?template=${template.id}`)}>
                  <strong>{template.name}</strong>
                  <span>{t(voucherKindCopy[template.kind].name)}</span>
                </button>
                {canAbility(user, "ops.vouchers.delete") ? (
                  <ConfirmAction
                    label={t(copy.delete)}
                    confirmLabel={t(copy.confirmDelete)}
                    yesLabel={t(copy.delete)}
                    noLabel={t(copy.cancel)}
                    className="btn btn-ghost btn-sm btn-danger"
                    onConfirm={() => removeTemplate(template.id)}
                  />
                ) : null}
              </div>
            ))}
          </div>
        )}
      </section>
    );
  }

  const shownAmount = draft.kind === "journal"
    ? totals.debit
    : draft.kind === "delivery"
      ? (totals.debit > 0 ? totals.debit : Number(draft.amount) || 0)
      : Number(draft.amount) || 0;
  const partyLabel = draft.kind === "payment" ? t(copy.voucherPaidTo) : t(copy.voucherReceivedFrom);

  return (
    <section className="voucher-page">
      <div className="voucher-toolbar">
        <Link className="back-link" to="/vouchers"><BackIcon size={16} aria-hidden="true" />{t(copy.voucherBack)}</Link>
        <button type="button" className="btn btn-ghost btn-sm" onClick={() => window.print()}>
          <Printer size={15} aria-hidden="true" />
          {t(copy.voucherPrint)}
        </button>
      </div>
      {error ? <p className="error">{error}</p> : null}
      <div className="voucher-studio">
        <form className="card voucher-editor" onSubmit={(event) => void save(event)}>
          <h2 className="form-title">{t(voucherKindCopy[draft.kind].name)}</h2>
          <label className="field-label">
            {draft.kind === "delivery" ? t(copy.voucherRecipient) : t(copy.voucherParty)}
            <input className="field" value={draft.party_name} disabled={!canWrite} required maxLength={160} placeholder={t(copy.voucherPartyPh)} onChange={(event) => patch({ party_name: event.target.value })} />
          </label>
          <div className="voucher-split">
            <label className="field-label">
              {t(copy.voucherDate)}
              <input className="field" type="date" value={draft.issued_on} disabled={!canWrite} required onChange={(event) => patch({ issued_on: event.target.value })} />
            </label>
            <label className="field-label">
              {t(copy.voucherCurrency)}
              <select className="field" value={draft.currency} disabled={!canWrite} onChange={(event) => patch({ currency: event.target.value as Draft["currency"] })}>
                <option value="USD">USD</option>
                <option value="SYP">SYP</option>
              </select>
            </label>
          </div>
          {draft.kind === "journal" ? (
            <div className="voucher-lines">
              <div className="voucher-line voucher-line-head" aria-hidden="true">
                <span>{t(copy.voucherMemo)}</span>
                <span>{t(copy.voucherDebit)}</span>
                <span>{t(copy.voucherCredit)}</span>
              </div>
              {draft.lines.map((line, index) => (
                <div className="voucher-line" key={index}>
                  <input className="field" aria-label={t(copy.voucherMemo)} placeholder={t(copy.voucherMemo)} value={line.memo} disabled={!canWrite} maxLength={180} onChange={(event) => patchLine(index, { memo: event.target.value })} />
                  <input className="field" dir="ltr" inputMode="decimal" aria-label={t(copy.voucherDebit)} placeholder={t(copy.voucherDebit)} value={line.debit} disabled={!canWrite} onChange={(event) => patchLine(index, { debit: event.target.value })} />
                  <input className="field" dir="ltr" inputMode="decimal" aria-label={t(copy.voucherCredit)} placeholder={t(copy.voucherCredit)} value={line.credit} disabled={!canWrite} onChange={(event) => patchLine(index, { credit: event.target.value })} />
                </div>
              ))}
              {draft.lines.length < 8 && canWrite ? (
                <button type="button" className="btn btn-ghost btn-sm" onClick={() => patch({ lines: [...draft.lines, { memo: "", debit: "", credit: "" }] })}>
                  {t(copy.voucherAddLine)}
                </button>
              ) : null}
              <p className="muted" role="status">
                {t(copy.voucherDebit)} {money(totals.debit, draft.currency)} · {t(copy.voucherCredit)} {money(totals.credit, draft.currency)}
              </p>
            </div>
          ) : draft.kind === "delivery" ? (
            <div className="voucher-lines">
              <div className="voucher-line is-delivery voucher-line-head" aria-hidden="true">
                <span>{t(copy.voucherItem)}</span>
                <span>{t(copy.voucherAmount)}</span>
              </div>
              {draft.lines.map((line, index) => (
                <div className="voucher-line is-delivery" key={index}>
                  <input className="field" aria-label={t(copy.voucherItem)} placeholder={t(copy.voucherItem)} value={line.memo} disabled={!canWrite} maxLength={180} onChange={(event) => patchLine(index, { memo: event.target.value })} />
                  <input className="field" dir="ltr" inputMode="decimal" aria-label={t(copy.voucherAmount)} placeholder={t(copy.voucherAmount)} value={line.debit} disabled={!canWrite} onChange={(event) => patchLine(index, { debit: event.target.value })} />
                </div>
              ))}
              {draft.lines.length < 8 && canWrite ? (
                <button type="button" className="btn btn-ghost btn-sm" onClick={() => patch({ lines: [...draft.lines, { memo: "", debit: "", credit: "" }] })}>
                  {t(copy.voucherAddLine)}
                </button>
              ) : null}
              <p className="muted" role="status">{t(copy.voucherTotal)} <span dir="ltr">{figures(shownAmount, draft.currency)}</span></p>
            </div>
          ) : (
            <label className="field-label">
              {t(copy.voucherAmount)}
              <input className="field" dir="ltr" inputMode="decimal" min="0" step="0.01" value={draft.amount} disabled={!canWrite} required onChange={(event) => patch({ amount: event.target.value })} />
            </label>
          )}
          <label className="field-label">
            {t(copy.voucherAmountWords)}
            <input className="field" value={draft.amount_words} disabled={!canWrite} maxLength={240} onChange={(event) => patch({ amount_words: event.target.value })} />
          </label>
          {draft.kind === "delivery" ? (
            <>
              <label className="field-label">
                {t(copy.voucherRecipientTitle)}
                <input className="field" value={draft.reference} disabled={!canWrite} maxLength={120} onChange={(event) => patch({ reference: event.target.value })} />
              </label>
              <label className="field-label">
                {t(copy.voucherGiver)}
                <input className="field" value={draft.counter_signer_name} disabled={!canWrite} maxLength={120} onChange={(event) => patch({ counter_signer_name: event.target.value })} />
              </label>
              <label className="field-label">
                {t(copy.voucherGiverRole)}
                <input className="field" value={draft.purpose} disabled={!canWrite} maxLength={120} onChange={(event) => patch({ purpose: event.target.value })} />
              </label>
            </>
          ) : (
            <>
              <label className="field-label">
                {t(copy.voucherPurpose)}
                <textarea className="field" rows={3} value={draft.purpose} disabled={!canWrite} maxLength={2000} onChange={(event) => patch({ purpose: event.target.value })} />
              </label>
              <label className="field-label">
                {t(copy.voucherReference)}
                <input className="field" value={draft.reference} disabled={!canWrite} maxLength={120} onChange={(event) => patch({ reference: event.target.value })} />
              </label>
            </>
          )}
          <label className="field-label">
            {t(copy.voucherBackground)}
            <input
              className="field"
              type="file"
              accept="image/jpeg,image/png,image/webp"
              disabled={!canWrite}
              onChange={(event) => {
                const file = event.target.files?.[0];
                event.target.value = "";
                if (!file) return;
                void readBackground(file)
                  .then((background) => patch({ background }))
                  .catch(() => toast.error(t(copy.voucherBackgroundInvalid)));
              }}
            />
          </label>
          <p className="muted">{t(copy.voucherBackgroundHint)}</p>
          {draft.background ? (
            <button type="button" className="btn btn-ghost btn-sm" disabled={!canWrite} onClick={() => patch({ background: "" })}>{t(copy.voucherBackgroundClear)}</button>
          ) : null}
          <p className="muted">{t(copy.voucherSignHint)}</p>
          {draft.kind === "delivery" ? null : (
            <label className="field-label">
              {t(copy.voucherSignerName)}
              <input className="field" value={draft.signer_name} disabled={!canWrite} maxLength={120} onChange={(event) => patch({ signer_name: event.target.value })} />
            </label>
          )}
          <SignaturePad label={draft.kind === "delivery" ? t(copy.voucherSignRecipient) : t(copy.voucherSigner)} clearLabel={t(copy.voucherClearSign)} value={draft.signature} disabled={!canWrite} onChange={(signature) => patch({ signature })} />
          {draft.kind === "delivery" ? null : (
            <label className="field-label">
              {t(copy.voucherSignerName)}
              <input className="field" value={draft.counter_signer_name} disabled={!canWrite} maxLength={120} onChange={(event) => patch({ counter_signer_name: event.target.value })} />
            </label>
          )}
          <SignaturePad label={draft.kind === "delivery" ? t(copy.voucherSignGiver) : t(copy.voucherCounterSigner)} clearLabel={t(copy.voucherClearSign)} value={draft.counter_signature} disabled={!canWrite} onChange={(counter_signature) => patch({ counter_signature })} />
          {canWrite ? (
            <>
              <button className="btn btn-primary" type="submit" disabled={busy} aria-busy={busy}>{t(copy.voucherSave)}</button>
              <label className="field-label">
                {t(copy.voucherTemplateName)}
                <input className="field" value={templateName} maxLength={120} disabled={busy} onChange={(event) => setTemplateName(event.target.value)} />
              </label>
              <button className="btn" type="button" disabled={busy} onClick={() => void saveTemplate()}>{t(copy.voucherSaveTemplate)}</button>
            </>
          ) : null}
        </form>

        <article className="voucher-sheet is-letter" dir="rtl">
          {draft.background ? <img className="voucher-page-bg" src={draft.background} alt="" /> : <img className="voucher-watermark" src={`${import.meta.env.BASE_URL}hummingbird.svg`} alt="" />}
          <span className="voucher-corner is-top" aria-hidden="true" />
          <span className="voucher-corner is-bottom" aria-hidden="true" />
          <header className="voucher-lockup" dir="ltr">
            <img src={`${import.meta.env.BASE_URL}hummingbird.svg`} alt="" width={72} height={52} />
            <span>Home of Creativity</span>
          </header>
          <h1 className="voucher-doc-title">{t(voucherKindCopy[draft.kind].name)}</h1>
          <div className="voucher-meta">
            <p><span>{t(copy.voucherDate)}</span><strong dir="ltr">{showDate(draft.issued_on)}</strong></p>
            <p><span>{t(copy.voucherCompany)}</span><strong>{t(copy.voucherCompanyValue)}</strong></p>
            <p><span>{t(copy.voucherSerial)}</span><strong dir="ltr">{shortSerial(serial)}</strong></p>
          </div>
          <div className="voucher-text">
          {draft.kind === "delivery" ? (
            <div className="voucher-copy">
              <p><span>{t(copy.voucherRecipient)}</span><strong>{[draft.party_name, draft.reference].filter(Boolean).join(" / ") || "………………"}</strong></p>
              <p><span>{t(copy.voucherGiver)}</span><strong>{[draft.counter_signer_name, draft.purpose].filter(Boolean).join(" / ") || "………………"}</strong></p>
              <p><span>{t(copy.voucherAmountDigits)}</span><strong dir="ltr">{figures(shownAmount, draft.currency)}</strong></p>
              <p><span>{t(copy.voucherAmountWords)}</span><strong>{draft.amount_words || "………………"}</strong></p>
              <p className="voucher-statement-label">{t(copy.voucherStatement)}</p>
              <ul>
                {draft.lines.filter((line) => line.memo || line.debit).map((line, index) => (
                  <li key={index}>
                    <span>{line.memo || "………………"}</span>
                    {line.debit ? <b dir="ltr">{figures(Number(line.debit) || 0, draft.currency)}</b> : null}
                  </li>
                ))}
              </ul>
              <p className="voucher-ack">{t(copy.voucherAck)}</p>
            </div>
          ) : draft.kind === "journal" ? (
            <table className="voucher-table">
              <thead>
                <tr>
                  <th>{t(copy.voucherMemo)}</th>
                  <th>{t(copy.voucherDebit)}</th>
                  <th>{t(copy.voucherCredit)}</th>
                </tr>
              </thead>
              <tbody>
                {draft.lines.filter((line) => line.memo || line.debit || line.credit).map((line, index) => (
                  <tr key={index}>
                    <td>{line.memo || "—"}</td>
                    <td dir="ltr">{line.debit ? money(Number(line.debit) || 0, draft.currency) : ""}</td>
                    <td dir="ltr">{line.credit ? money(Number(line.credit) || 0, draft.currency) : ""}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          ) : (
            <div className="voucher-copy">
              <p><span>{partyLabel}</span><strong>{draft.party_name || "………………"}</strong></p>
              <p><span>{t(copy.voucherAmountDigits)}</span><strong dir="ltr">{figures(shownAmount, draft.currency)}</strong></p>
              {draft.amount_words ? <p><span>{t(copy.voucherAmountWords)}</span><strong>{draft.amount_words}</strong></p> : null}
              <p><span>{t(copy.voucherFor)}</span><strong>{draft.purpose || "………………"}</strong></p>
            </div>
          )}
          </div>
          <footer className="voucher-signs">
            <div>
              <span>{draft.kind === "delivery" ? t(copy.voucherSignRecipient) : t(copy.voucherStaffSign)}</span>
              <strong>{draft.signer_name || draft.party_name || "………………"}</strong>
              {draft.signature ? <img src={draft.signature} alt="" /> : <i />}
            </div>
            <div>
              <span>{draft.kind === "delivery" ? t(copy.voucherSignGiver) : t(copy.voucherOtherSign)}</span>
              <strong>{draft.counter_signer_name || "………………"}</strong>
              {draft.counter_signature ? <img src={draft.counter_signature} alt="" /> : <i />}
            </div>
          </footer>
          <footer className="voucher-contact" dir="ltr">
            <ul>
              <li><Phone size={14} aria-hidden="true" />+963 968 862 822</li>
              <li><Phone size={14} aria-hidden="true" />+963 954 187 154</li>
              <li><Globe size={14} aria-hidden="true" />www.hoc.agency</li>
              <li><Mail size={14} aria-hidden="true" />info@hoc.agency</li>
              <li><MapPin size={14} aria-hidden="true" />Riyadh, Al Murabaa</li>
              <li><MapPin size={14} aria-hidden="true" />Damascus, Al Hamra st</li>
            </ul>
            <img src={`${import.meta.env.BASE_URL}hoc-site-qr.svg`} alt="hoc.agency" />
          </footer>
        </article>
      </div>
    </section>
  );
}
