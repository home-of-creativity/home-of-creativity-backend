import { useEffect, useMemo, useRef, useState, type FormEvent, type PointerEvent } from "react";
import { Link, useNavigate, useParams, useSearchParams } from "react-router-dom";
import { ArrowLeft, ArrowRight, Globe, Mail, MapPin, Phone, Printer, Upload, X } from "lucide-react";
import { toast } from "sonner";
import { api, canAbility, type FinancialVoucher, type VoucherKind, type VoucherLine, type VoucherSignature, type VoucherTemplate, type VoucherTemplateSummary } from "../api";
import { useAuth } from "../auth";
import { ConfirmAction } from "../components/ConfirmAction";
import { sheetToPdf } from "../components/voucherPdf";
import { LoadingLottie } from "../components/LoadingLottie";
import { copy, type Locale } from "../i18n";

export const voucherKindCopy: Record<VoucherKind, { name: { ar: string; en: string }; hint: { ar: string; en: string } }> = {
  delivery: { name: copy.voucherDelivery, hint: copy.voucherDeliveryHint },
  receipt: { name: copy.voucherReceipt, hint: copy.voucherReceiptHint },
  payment: { name: copy.voucherPayment, hint: copy.voucherPaymentHint },
  journal: { name: copy.voucherJournal, hint: copy.voucherJournalHint },
  settlement: { name: copy.voucherSettlement, hint: copy.voucherSettlementHint },
  notes: { name: copy.voucherNotes, hint: copy.voucherNotesHint },
};

const KINDS: VoucherKind[] = ["delivery", "receipt", "payment", "journal", "settlement", "notes"];
const MAX_NOTES = 40;

/** The default word in each signature label; a voucher can replace it, e.g. «الطرف الآخر» with «HOC». */
function signWord(kind: VoucherKind, side: "signer" | "counter") {
  if (kind === "delivery") return side === "signer" ? copy.voucherWordRecipient : copy.voucherWordGiver;
  if (kind === "notes") return side === "signer" ? copy.voucherWordFirst : copy.voucherWordSecond;
  return side === "signer" ? copy.voucherWordStaff : copy.voucherWordOther;
}

function signLabel(kind: VoucherKind, word: string, t: (c: { ar: string; en: string }) => string) {
  return t(kind === "delivery" ? copy.voucherNameSignAs : copy.voucherSignAs).replace("{word}", word);
}

/** Sum of the lines that carry an amount, negative ones included; null when none does. */
function pricedSum(lines: { debit: string }[]) {
  const priced = lines.filter((line) => Number(line.debit));
  return priced.length > 0 ? priced.reduce((sum, line) => sum + Number(line.debit), 0) : null;
}

type LineDraft = { memo: string; debit: string; credit: string };

type Draft = {
  kind: VoucherKind;
  title: string;
  party_name: string;
  amount: string;
  remaining: string;
  show_remaining: boolean;
  currency: "USD" | "SYP";
  amount_words: string;
  issued_on: string;
  purpose: string;
  reference: string;
  lines: LineDraft[];
  signer_name: string;
  signer_title: string;
  counter_signer_name: string;
  counter_signer_title: string;
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
    title: "",
    party_name: "",
    amount: "",
    remaining: "",
    show_remaining: false,
    currency: "USD",
    amount_words: "",
    issued_on: today(),
    purpose: "",
    reference: "",
    lines: blankLines(),
    signer_name: "",
    signer_title: "",
    counter_signer_name: "",
    counter_signer_title: "",
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
    title: data.title ?? "",
    party_name: data.party_name ?? "",
    amount: data.amount ? String(data.amount) : "",
    remaining: data.remaining != null ? String(data.remaining) : "",
    show_remaining: Boolean(data.show_remaining),
    currency: data.currency === "SYP" ? "SYP" : "USD",
    amount_words: data.amount_words ?? "",
    issued_on: today(),
    purpose: data.purpose ?? "",
    reference: data.reference ?? "",
    lines,
    signer_name: data.signer_name ?? "",
    signer_title: data.signer_title ?? "",
    counter_signer_name: data.counter_signer_name ?? "",
    counter_signer_title: data.counter_signer_title ?? "",
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

/** Keeps transparency so a scanned signature sits cleanly on the sheet. */
function readSignatureImage(file: File): Promise<string> {
  return new Promise((resolve, reject) => {
    const url = URL.createObjectURL(file);
    const img = new Image();
    img.onload = () => {
      const max = 900;
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
      resolve(canvas.toDataURL("image/png"));
    };
    img.onerror = () => {
      URL.revokeObjectURL(url);
      reject(new Error("image"));
    };
    img.src = url;
  });
}

type SignTarget = "signature" | "counter_signature";

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
    title: voucher.title ?? "",
    party_name: voucher.party_name,
    amount: voucher.amount ? String(voucher.amount) : "",
    remaining: voucher.remaining != null ? String(voucher.remaining) : "",
    show_remaining: Boolean(voucher.show_remaining),
    currency: voucher.currency,
    amount_words: voucher.amount_words ?? "",
    issued_on: voucher.issued_on ?? today(),
    purpose: voucher.purpose ?? "",
    reference: voucher.reference ?? "",
    lines,
    signer_name: voucher.signer_name ?? "",
    signer_title: voucher.signer_title ?? "",
    counter_signer_name: voucher.counter_signer_name ?? "",
    counter_signer_title: voucher.counter_signer_title ?? "",
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

/** Sheet label from a stored serial (HOC-V-2026-0007 → N0007). */
function shortSerial(serial: string) {
  const digits = serial.match(/(\d+)$/)?.[1] ?? "";
  return digits ? `N${digits.slice(-4).padStart(4, "0")}` : "—";
}

/** Digits shown in the edit field (HOC-V-2026-0007 → 7). */
function serialInputValue(serial: string) {
  const digits = serial.match(/(\d+)$/)?.[1] ?? "";
  return digits ? String(Number(digits)) : "";
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
  const [sent, setSent] = useState<{ at: string | null; to: string | null }>({ at: null, to: null });
  const [sendForm, setSendForm] = useState({ name: "", email: "", phone: "", message: "", byEmail: true, byWhatsApp: true });
  const [sending, setSending] = useState(false);
  const sheetRef = useRef<HTMLElement>(null);
  const canSend = canAbility(user, "ops.vouchers.send");
  const [draft, setDraft] = useState<Draft | null>(null);
  const [templates, setTemplates] = useState<VoucherTemplateSummary[]>([]);
  const [templateName, setTemplateName] = useState("");
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState("");
  const [signsOpen, setSignsOpen] = useState(false);
  const [signatures, setSignatures] = useState<VoucherSignature[]>([]);
  const [signsLoading, setSignsLoading] = useState(false);
  const [signName, setSignName] = useState("");
  const [signTarget, setSignTarget] = useState<SignTarget>("signature");
  const [signBusy, setSignBusy] = useState(false);
  const tRef = useRef(t);
  tRef.current = t;

  useEffect(() => {
    if (!signsOpen) return;
    let cancelled = false;
    setSignsLoading(true);
    api.voucherSignatures()
      .then((res) => {
        if (!cancelled) setSignatures(res.data);
      })
      .catch(() => {
        if (!cancelled) toast.error(tRef.current(copy.saveFailed));
      })
      .finally(() => {
        if (!cancelled) setSignsLoading(false);
      });
    return () => {
      cancelled = true;
    };
  }, [signsOpen]);

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
        Promise.all([api.voucherTemplate(templateId), api.voucherNextSerial()])
          .then(([res, next]) => {
            if (cancelled) return;
            setSerial(next.data.serial);
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
        let cancelled = false;
        setDraft((current) => (current?.kind === requestedKind ? current : emptyDraft(requestedKind as VoucherKind)));
        setPhase("edit");
        api.voucherNextSerial()
          .then((next) => {
            if (!cancelled) setSerial(next.data.serial);
          })
          .catch(() => {
            if (!cancelled) setSerial("");
          });
        return () => {
          cancelled = true;
        };
      }
      setDraft(null);
      setPhase("template");
      return;
    }
    let cancelled = false;
    setPhase("loading");
    api.voucher(voucherId)
      .then((res) => {
        if (cancelled) return;
        setSerial(res.data.serial);
        setSent({ at: res.data.sent_at ?? null, to: res.data.sent_to ?? null });
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

  function moveNote(index: number, step: -1 | 1) {
    setDraft((current) => {
      if (!current) return current;
      const target = index + step;
      if (target < 0 || target >= current.lines.length) return current;
      const lines = [...current.lines];
      [lines[index], lines[target]] = [lines[target], lines[index]];
      return { ...current, lines };
    });
  }

  function removeNote(index: number) {
    setDraft((current) => {
      if (!current) return current;
      const lines = current.lines.filter((_, i) => i !== index);
      return { ...current, lines: lines.length > 0 ? lines : [{ memo: "", debit: "", credit: "" }] };
    });
  }

  /** The sheet as a PDF, sent to anyone by email and/or WhatsApp. */
  async function send() {
    if (!voucherId || !sheetRef.current) return;
    const channel = sendForm.byEmail && sendForm.byWhatsApp ? "both" : sendForm.byEmail ? "email" : sendForm.byWhatsApp ? "whatsapp" : "";
    if (!channel) {
      toast.error(t(copy.voucherSendPickChannel));
      return;
    }
    if (!sendForm.name.trim()) {
      toast.error(t(copy.voucherSendNeedName));
      return;
    }
    setSending(true);
    try {
      const pdf = await sheetToPdf(sheetRef.current);
      const form = new FormData();
      form.append("pdf", pdf, `${serial || "voucher"}.pdf`);
      form.append("channel", channel);
      form.append("name", sendForm.name.trim());
      if (sendForm.byEmail) form.append("email", sendForm.email.trim());
      if (sendForm.byWhatsApp) form.append("phone", sendForm.phone.trim());
      if (sendForm.message.trim()) form.append("message", sendForm.message.trim());
      const res = await api.sendVoucher(voucherId, form);
      setSent({ at: res.data.voucher.sent_at ?? null, to: res.data.voucher.sent_to ?? null });
      toast.success(res.message ?? t(copy.saved));
    } catch (err) {
      toast.error(err instanceof Error ? err.message : t(copy.saveFailed));
    } finally {
      setSending(false);
    }
  }

  async function save(event: FormEvent) {
    event.preventDefault();
    if (!draft || !canWrite) return;
    if (!draft.party_name.trim()) {
      setError(t(copy.voucherPartyRequired));
      return;
    }
    if (draft.kind === "notes" && !draft.lines.some((line) => line.memo.trim())) {
      setError(t(copy.voucherNotesRequired));
      return;
    }
    const amount = draft.kind === "notes" ? 0 : Number(draft.amount) || 0;
    const deliveryAmount = pricedSum(draft.lines) ?? amount;
    if ((draft.kind === "receipt" || draft.kind === "payment" || draft.kind === "delivery") && (draft.kind === "delivery" ? deliveryAmount : amount) === 0) {
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
    if (!serial.trim()) {
      setError(t(copy.voucherSerialRequired));
      return;
    }
    setError("");
    setBusy(true);
    try {
      const res = await api.saveVoucher({
        kind: draft.kind,
        title: draft.title.trim() || undefined,
        party_name: draft.party_name.trim(),
        serial: serial.trim(),
        amount: draft.kind === "journal" ? totals.debit : draft.kind === "delivery" ? deliveryAmount : amount,
        remaining: draft.remaining.trim() === "" ? null : Number(draft.remaining),
        show_remaining: draft.show_remaining,
        currency: draft.currency,
        amount_words: draft.amount_words.trim() || undefined,
        issued_on: draft.issued_on,
        purpose: draft.purpose.trim() || undefined,
        reference: draft.reference.trim() || undefined,
        lines,
        signer_name: (draft.signer_name.trim() || (draft.kind === "delivery" ? draft.party_name.trim() : "")) || undefined,
        signer_title: draft.signer_title.trim() || undefined,
        counter_signer_name: draft.counter_signer_name.trim() || undefined,
        counter_signer_title: draft.counter_signer_title.trim() || undefined,
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
    const lineSum = draft.kind === "delivery" ? pricedSum(draft.lines) : totals.debit;
    setError("");
    setBusy(true);
    try {
      await api.saveVoucherTemplate({
        name,
        kind: draft.kind,
        title: draft.title.trim() || undefined,
        party_name: draft.party_name.trim(),
        amount: draft.kind === "journal" || draft.kind === "delivery"
          ? (lineSum ? lineSum : Number(draft.amount) || 0)
          : Number(draft.amount) || 0,
        remaining: draft.remaining.trim() === "" ? null : Number(draft.remaining),
        show_remaining: draft.show_remaining,
        currency: draft.currency,
        amount_words: draft.amount_words.trim() || undefined,
        purpose: draft.purpose.trim() || undefined,
        reference: draft.reference.trim() || undefined,
        lines,
        signer_name: draft.signer_name.trim() || undefined,
        signer_title: draft.signer_title.trim() || undefined,
        counter_signer_name: draft.counter_signer_name.trim() || undefined,
        counter_signer_title: draft.counter_signer_title.trim() || undefined,
        background: draft.background || undefined,
      });
      toast.success(t(copy.voucherTemplateSaved));
    } catch (err) {
      setError(err instanceof Error ? err.message : t(copy.saveFailed));
    } finally {
      setBusy(false);
    }
  }

  async function uploadSignature(file: File | undefined) {
    if (!file || !canWrite) return;
    const name = signName.trim() || file.name.replace(/\.[^.]+$/, "").slice(0, 120);
    if (name === "") {
      toast.error(t(copy.voucherSignatureNameRequired));
      return;
    }
    setSignBusy(true);
    try {
      const image = await readSignatureImage(file);
      const res = await api.saveVoucherSignature({ name, image });
      setSignatures((current) => [res.data, ...current]);
      setSignName("");
      toast.success(t(copy.voucherSignatureSaved));
    } catch (err) {
      toast.error(err instanceof Error ? err.message : t(copy.voucherSignatureInvalid));
    } finally {
      setSignBusy(false);
    }
  }

  function applySignature(image: string) {
    if (!canWrite) return;
    patch({ [signTarget]: image });
    toast.success(t(copy.voucherSignatureApplied));
  }

  function removeSignature(id: number) {
    api.deleteVoucherSignature(id)
      .then(() => {
        setSignatures((current) => current.filter((row) => row.id !== id));
        toast.success(t(copy.voucherSignatureDeleted));
      })
      .catch((err) => toast.error(err instanceof Error ? err.message : t(copy.saveFailed)));
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
        {voucherId ? (
          <span className={canWrite ? "voucher-mode is-edit" : "voucher-mode"} role="status">
            {canWrite ? t(copy.voucherEditing) : t(copy.voucherViewOnly)}
            {serial ? <span dir="ltr"> · {serial}</span> : null}
          </span>
        ) : null}
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
      ? (pricedSum(draft.lines) ?? (Number(draft.amount) || 0))
      : Number(draft.amount) || 0;
  const docTitle = draft.title.trim() || t(voucherKindCopy[draft.kind].name);
  const signerWord = draft.signer_title.trim() || t(signWord(draft.kind, "signer"));
  const counterWord = draft.counter_signer_title.trim() || t(signWord(draft.kind, "counter"));
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
      <div className={`voucher-studio${signsOpen ? " has-signs-panel" : ""}`}>
        <form className="card voucher-editor" onSubmit={(event) => void save(event)}>
          <h2 className="form-title">{t(voucherKindCopy[draft.kind].name)}</h2>
          <label className="field-label">
            {t(copy.voucherSerialEdit)}
            <input
              className="field"
              value={serialInputValue(serial)}
              disabled={!canWrite}
              inputMode="numeric"
              maxLength={6}
              placeholder="1"
              dir="ltr"
              onChange={(event) => {
                const digits = event.target.value.replace(/\D/g, "").slice(0, 6);
                const year = Number(serial.match(/HOC-V-(\d{4})-/)?.[1]) || new Date().getFullYear();
                setSerial(digits ? `HOC-V-${year}-${digits.padStart(4, "0")}` : "");
              }}
            />
          </label>
          <p className="muted">{t(copy.voucherSerialHint)}</p>
          <label className="field-label">
            {t(copy.voucherTitle)}
            <input className="field" value={draft.title} disabled={!canWrite} maxLength={120} placeholder={t(voucherKindCopy[draft.kind].name)} onChange={(event) => patch({ title: event.target.value })} />
          </label>
          {draft.kind === "notes" ? (
            <label className="field-label">
              {t(copy.voucherNotesFirst)}
              <input className="field" value={draft.signer_name} disabled={!canWrite} maxLength={120} placeholder={t(copy.voucherNotesFirstPh)} onChange={(event) => patch({ signer_name: event.target.value })} />
            </label>
          ) : null}
          <label className="field-label">
            {draft.kind === "delivery" ? t(copy.voucherRecipient) : draft.kind === "notes" ? t(copy.voucherNotesSecond) : t(copy.voucherParty)}
            <input className="field" value={draft.party_name} disabled={!canWrite} required maxLength={160} placeholder={t(copy.voucherPartyPh)} onChange={(event) => patch({ party_name: event.target.value })} />
          </label>
          <div className="voucher-split">
            <label className="field-label">
              {t(copy.voucherDate)}
              <input className="field" type="date" value={draft.issued_on} disabled={!canWrite} required onChange={(event) => patch({ issued_on: event.target.value })} />
            </label>
            {draft.kind === "notes" ? null : (
            <label className="field-label">
              {t(copy.voucherCurrency)}
              <select className="field" value={draft.currency} disabled={!canWrite} onChange={(event) => patch({ currency: event.target.value as Draft["currency"] })}>
                <option value="USD">USD</option>
                <option value="SYP">SYP</option>
              </select>
            </label>
            )}
          </div>
          {draft.kind === "notes" ? (
            <>
              <label className="field-label">
                {t(copy.voucherNotesSubject)}
                <textarea className="field" rows={2} value={draft.purpose} disabled={!canWrite} maxLength={2000} onChange={(event) => patch({ purpose: event.target.value })} />
              </label>
              <label className="field-label">
                {t(copy.voucherNotesWhere)}
                <input className="field" value={draft.reference} disabled={!canWrite} maxLength={120} placeholder={t(copy.voucherNotesWherePh)} onChange={(event) => patch({ reference: event.target.value })} />
              </label>
              <div className="voucher-notes-edit">
                <span className="field-label">{t(copy.voucherNotesList)}</span>
                <ol>
                  {draft.lines.map((line, index) => (
                    <li key={index}>
                      <span className="voucher-note-number" aria-hidden="true">{index + 1}</span>
                      <textarea
                        className="field"
                        rows={2}
                        value={line.memo}
                        disabled={!canWrite}
                        maxLength={1000}
                        placeholder={t(copy.voucherNotesNotePh)}
                        aria-label={`${t(copy.voucherNotesList)} ${index + 1}`}
                        onChange={(event) => patchLine(index, { memo: event.target.value })}
                      />
                      {canWrite ? (
                        <span className="voucher-note-tools">
                          <button type="button" className="btn btn-ghost btn-sm" disabled={index === 0} aria-label={t(copy.voucherNotesUp)} title={t(copy.voucherNotesUp)} onClick={() => moveNote(index, -1)}>↑</button>
                          <button type="button" className="btn btn-ghost btn-sm" disabled={index === draft.lines.length - 1} aria-label={t(copy.voucherNotesDown)} title={t(copy.voucherNotesDown)} onClick={() => moveNote(index, 1)}>↓</button>
                          <button type="button" className="btn btn-ghost btn-sm btn-danger" aria-label={t(copy.voucherNotesRemove)} title={t(copy.voucherNotesRemove)} onClick={() => removeNote(index)}>×</button>
                        </span>
                      ) : null}
                    </li>
                  ))}
                </ol>
                {draft.lines.length < MAX_NOTES && canWrite ? (
                  <button type="button" className="btn btn-ghost btn-sm" onClick={() => patch({ lines: [...draft.lines, { memo: "", debit: "", credit: "" }] })}>
                    {t(copy.voucherNotesAdd)}
                  </button>
                ) : null}
              </div>
            </>
          ) : draft.kind === "journal" ? (
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
              <input className="field" dir="ltr" inputMode="decimal" value={draft.amount} disabled={!canWrite} required onChange={(event) => patch({ amount: event.target.value })} />
            </label>
          )}
          {draft.kind === "notes" ? null : (
            <>
              <p className="muted">{t(copy.voucherNegativeHint)}</p>
              <label className="field-label">
                {t(copy.voucherAmountWords)}
                <input className="field" value={draft.amount_words} disabled={!canWrite} maxLength={240} onChange={(event) => patch({ amount_words: event.target.value })} />
              </label>
            </>
          )}
          <div className="voucher-remaining-row">
            <label className="field-label">
              {t(copy.voucherRemaining)}
              <input
                className="field"
                dir="ltr"
                inputMode="decimal"
                value={draft.remaining}
                disabled={!canWrite}
                onChange={(event) => patch({ remaining: event.target.value })}
              />
            </label>
            <label className="checkbox-row">
              <input
                type="checkbox"
                checked={draft.show_remaining}
                disabled={!canWrite}
                onChange={(event) => patch({ show_remaining: event.target.checked })}
              />
              <span>{t(copy.voucherShowRemaining)}</span>
            </label>
          </div>
          {draft.kind === "notes" ? null : draft.kind === "delivery" ? (
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
          <div className="voucher-sign-tools">
            <p className="muted">{t(copy.voucherSignHint)}</p>
            <button
              type="button"
              className={`btn btn-sm${signsOpen ? " is-active" : ""}`}
              aria-expanded={signsOpen}
              aria-controls="voucher-signs-panel"
              onClick={() => setSignsOpen((open) => !open)}
            >
              {t(copy.voucherSignatures)}
            </button>
          </div>
          {draft.kind === "delivery" || draft.kind === "notes" ? null : (
            <label className="field-label">
              {t(copy.voucherSignerName)}
              <input className="field" value={draft.signer_name} disabled={!canWrite} maxLength={120} onChange={(event) => patch({ signer_name: event.target.value })} onFocus={() => setSignTarget("signature")} />
            </label>
          )}
          <label className="field-label">
            {t(copy.voucherSignWord)}
            <input className="field" value={draft.signer_title} disabled={!canWrite} maxLength={60} placeholder={t(signWord(draft.kind, "signer"))} onChange={(event) => patch({ signer_title: event.target.value })} onFocus={() => setSignTarget("signature")} />
          </label>
          <SignaturePad label={signLabel(draft.kind, signerWord, t)} clearLabel={t(copy.voucherClearSign)} value={draft.signature} disabled={!canWrite} onChange={(signature) => { setSignTarget("signature"); patch({ signature }); }} />
          {draft.kind === "delivery" ? null : (
            <label className="field-label">
              {t(copy.voucherSignerName)}
              <input className="field" value={draft.counter_signer_name} disabled={!canWrite} maxLength={120} onChange={(event) => patch({ counter_signer_name: event.target.value })} onFocus={() => setSignTarget("counter_signature")} />
            </label>
          )}
          <label className="field-label">
            {t(copy.voucherSignWord)}
            <input className="field" value={draft.counter_signer_title} disabled={!canWrite} maxLength={60} placeholder={t(signWord(draft.kind, "counter"))} onChange={(event) => patch({ counter_signer_title: event.target.value })} onFocus={() => setSignTarget("counter_signature")} />
          </label>
          <SignaturePad label={signLabel(draft.kind, counterWord, t)} clearLabel={t(copy.voucherClearSign)} value={draft.counter_signature} disabled={!canWrite} onChange={(counter_signature) => { setSignTarget("counter_signature"); patch({ counter_signature }); }} />
          {canWrite ? (
            <>
              <button className="btn btn-primary" type="submit" disabled={busy} aria-busy={busy}>{voucherId ? t(copy.voucherSaveChanges) : t(copy.voucherSave)}</button>
              <label className="field-label">
                {t(copy.voucherTemplateName)}
                <input className="field" value={templateName} maxLength={120} disabled={busy} onChange={(event) => setTemplateName(event.target.value)} />
              </label>
              <button className="btn" type="button" disabled={busy} onClick={() => void saveTemplate()}>{t(copy.voucherSaveTemplate)}</button>
            </>
          ) : null}
          {canSend ? (
            // Not a <form>: it sits inside the voucher form.
            <div className="voucher-send">
              <h3 className="section-title">{t(copy.voucherSend)}</h3>
              {sent.at ? (
                <p className="muted" role="status">
                  {t(copy.voucherSentTo)} <bdi>{sent.to}</bdi> · <span dir="ltr">{new Date(sent.at).toLocaleString(locale === "ar" ? "ar-SA-u-nu-latn" : "en-GB", { dateStyle: "medium", timeStyle: "short" })}</span>
                </p>
              ) : null}
              {voucherId ? (
                <>
                  <label className="field-label">
                    {t(copy.voucherSendName)}
                    <input className="field" value={sendForm.name} maxLength={120} placeholder={draft.counter_signer_name || draft.party_name} onChange={(event) => setSendForm((current) => ({ ...current, name: event.target.value }))} />
                  </label>
                  <div className="voucher-send-channels">
                    <label><input type="checkbox" checked={sendForm.byEmail} onChange={(event) => setSendForm((current) => ({ ...current, byEmail: event.target.checked }))} />{t(copy.voucherSendByEmail)}</label>
                    <label><input type="checkbox" checked={sendForm.byWhatsApp} onChange={(event) => setSendForm((current) => ({ ...current, byWhatsApp: event.target.checked }))} />{t(copy.voucherSendByWhatsApp)}</label>
                  </div>
                  {sendForm.byEmail ? (
                    <label className="field-label">
                      {t(copy.voucherSendEmail)}
                      <input className="field" type="email" dir="ltr" value={sendForm.email} maxLength={190} onChange={(event) => setSendForm((current) => ({ ...current, email: event.target.value }))} />
                    </label>
                  ) : null}
                  {sendForm.byWhatsApp ? (
                    <label className="field-label">
                      {t(copy.voucherSendPhone)}
                      <input className="field" type="tel" dir="ltr" value={sendForm.phone} maxLength={40} placeholder="+963 9xx xxx xxx" onChange={(event) => setSendForm((current) => ({ ...current, phone: event.target.value }))} />
                    </label>
                  ) : null}
                  <label className="field-label">
                    {t(copy.voucherSendMessage)}
                    <textarea className="field" rows={2} value={sendForm.message} maxLength={2000} onChange={(event) => setSendForm((current) => ({ ...current, message: event.target.value }))} />
                  </label>
                  <button type="button" className="btn btn-primary" disabled={sending} aria-busy={sending} onClick={() => void send()}>
                    {sending ? t(copy.voucherSendBuilding) : t(copy.voucherSendGo)}
                  </button>
                </>
              ) : (
                <p className="muted">{t(copy.voucherSendSaveFirst)}</p>
              )}
            </div>
          ) : null}
        </form>

        <article ref={sheetRef} className="voucher-sheet is-letter" dir="rtl">
          {draft.background ? <img className="voucher-page-bg" src={draft.background} alt="" /> : <img className="voucher-watermark" src={`${import.meta.env.BASE_URL}hummingbird.svg`} alt="" />}
          <span className="voucher-corner is-top" aria-hidden="true" />
          <span className="voucher-corner is-bottom" aria-hidden="true" />
          <header className="voucher-lockup" dir="ltr">
            <img src={`${import.meta.env.BASE_URL}hummingbird.svg`} alt="" width={72} height={52} />
            <span>Home of Creativity</span>
          </header>
          <h1 className="voucher-doc-title">{docTitle}</h1>
          <div className="voucher-meta">
            <p><span>{t(copy.voucherDate)}</span><strong dir="ltr">{showDate(draft.issued_on)}</strong></p>
            <p><span>{t(copy.voucherCompany)}</span><strong>{t(copy.voucherCompanyValue)}</strong></p>
            <p><span>{t(copy.voucherSerial)}</span><strong dir="ltr">{shortSerial(serial)}</strong></p>
          </div>
          <div className="voucher-text">
          {draft.kind === "notes" ? (
            <div className="voucher-copy">
              <p><span>{t(copy.voucherNotesFirst)}</span><strong>{draft.signer_name || t(copy.voucherCompanyValue)}</strong></p>
              <p><span>{t(copy.voucherNotesSecond)}</span><strong>{draft.party_name || "………………"}</strong></p>
              {draft.purpose ? <p><span>{t(copy.voucherNotesSubject)}</span><strong>{draft.purpose}</strong></p> : null}
              {draft.reference ? <p><span>{t(copy.voucherNotesWhere)}</span><strong>{draft.reference}</strong></p> : null}
              <p className="voucher-statement-label">{t(copy.voucherNotesList)}</p>
              <ol className="voucher-notes">
                {draft.lines.filter((line) => line.memo.trim()).map((line, index) => (
                  <li key={index}>{line.memo}</li>
                ))}
              </ol>
              <p className="voucher-ack">{t(copy.voucherNotesAck)}</p>
              {draft.show_remaining ? (
                <p><span>{t(copy.voucherRemaining)}</span><strong dir="ltr">{figures(Number(draft.remaining) || 0, draft.currency)}</strong></p>
              ) : null}
            </div>
          ) : draft.kind === "delivery" ? (
            <div className="voucher-copy">
              <p><span>{t(copy.voucherRecipient)}</span><strong>{[draft.party_name, draft.reference].filter(Boolean).join(" / ") || "………………"}</strong></p>
              <p><span>{t(copy.voucherGiver)}</span><strong>{[draft.counter_signer_name, draft.purpose].filter(Boolean).join(" / ") || "………………"}</strong></p>
              <p><span>{t(copy.voucherAmountDigits)}</span><strong dir="ltr">{figures(shownAmount, draft.currency)}</strong></p>
              <p><span>{t(copy.voucherAmountWords)}</span><strong>{draft.amount_words || "………………"}</strong></p>
              {draft.show_remaining ? (
                <p><span>{t(copy.voucherRemaining)}</span><strong dir="ltr">{figures(Number(draft.remaining) || 0, draft.currency)}</strong></p>
              ) : null}
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
            <>
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
              {draft.show_remaining ? (
                <div className="voucher-copy">
                  <p><span>{t(copy.voucherRemaining)}</span><strong dir="ltr">{figures(Number(draft.remaining) || 0, draft.currency)}</strong></p>
                </div>
              ) : null}
            </>
          ) : (
            <div className="voucher-copy">
              <p><span>{partyLabel}</span><strong>{draft.party_name || "………………"}</strong></p>
              <p><span>{t(copy.voucherAmountDigits)}</span><strong dir="ltr">{figures(shownAmount, draft.currency)}</strong></p>
              {draft.amount_words ? <p><span>{t(copy.voucherAmountWords)}</span><strong>{draft.amount_words}</strong></p> : null}
              {draft.show_remaining ? (
                <p><span>{t(copy.voucherRemaining)}</span><strong dir="ltr">{figures(Number(draft.remaining) || 0, draft.currency)}</strong></p>
              ) : null}
              <p><span>{t(copy.voucherFor)}</span><strong>{draft.purpose || "………………"}</strong></p>
            </div>
          )}
          </div>
          <footer className="voucher-signs">
            <div>
              <span>{signLabel(draft.kind, signerWord, t)}</span>
              <strong>{draft.signer_name || (draft.kind === "notes" ? t(copy.voucherCompanyValue) : draft.party_name) || "………………"}</strong>
              {draft.signature ? <img src={draft.signature} alt="" /> : <i />}
            </div>
            <div>
              <span>{signLabel(draft.kind, counterWord, t)}</span>
              <strong>{draft.counter_signer_name || (draft.kind === "notes" ? draft.party_name : "") || "………………"}</strong>
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
        {signsOpen ? (
          <aside id="voucher-signs-panel" className="voucher-signs-panel" aria-label={t(copy.voucherSignaturesPanel)}>
            <div className="voucher-signs-panel-head">
              <h2>{t(copy.voucherSignaturesPanel)}</h2>
              <button type="button" className="btn btn-ghost btn-icon" aria-label={t(copy.voucherSignaturesClose)} onClick={() => setSignsOpen(false)}>
                <X size={17} aria-hidden="true" />
              </button>
            </div>
            <label className="field-label">
              {t(copy.voucherSignatureTarget)}
              <select className="field" value={signTarget} onChange={(event) => setSignTarget(event.target.value as SignTarget)}>
                <option value="signature">{signLabel(draft.kind, signerWord, t)}</option>
                <option value="counter_signature">{signLabel(draft.kind, counterWord, t)}</option>
              </select>
            </label>
            {canWrite ? (
              <>
                <label className="field-label">
                  {t(copy.voucherSignatureName)}
                  <input className="field" value={signName} maxLength={120} disabled={signBusy} placeholder={t(copy.voucherSignatureNamePh)} onChange={(event) => setSignName(event.target.value)} />
                </label>
                <label className="btn btn-primary btn-sm voucher-sign-upload">
                  <Upload size={15} aria-hidden="true" />
                  {t(copy.voucherSignatureUpload)}
                  <input
                    type="file"
                    accept="image/jpeg,image/png,image/webp"
                    hidden
                    disabled={signBusy}
                    onChange={(event) => {
                      const file = event.target.files?.[0];
                      event.target.value = "";
                      void uploadSignature(file);
                    }}
                  />
                </label>
              </>
            ) : null}
            {signsLoading ? <LoadingLottie variant="inline" label={t(copy.loading)} /> : null}
            {!signsLoading && signatures.length === 0 ? <p className="muted">{t(copy.voucherSignatureEmpty)}</p> : null}
            <ul className="voucher-sign-list">
              {signatures.map((row) => (
                <li key={row.id}>
                  <button type="button" className="voucher-sign-card" disabled={!canWrite} onClick={() => applySignature(row.image)} title={t(copy.voucherSignatureUse)}>
                    <img src={row.image} alt="" />
                    <strong>{row.name}</strong>
                  </button>
                  {canAbility(user, "ops.vouchers.delete") ? (
                    <ConfirmAction
                      label={t(copy.delete)}
                      confirmLabel={t(copy.confirmDelete)}
                      yesLabel={t(copy.delete)}
                      noLabel={t(copy.cancel)}
                      onConfirm={() => removeSignature(row.id)}
                    />
                  ) : null}
                </li>
              ))}
            </ul>
          </aside>
        ) : null}
      </div>
    </section>
  );
}
