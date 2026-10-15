/** Which status-bar step is current from Odoo's state (and payment for invoices). */
export function paperStatusIndex(
  kind: "quotation" | "invoice",
  state?: string | null,
  paymentState?: string | null,
): number {
  if (kind === "quotation") {
    return state === "sent" || state === "sale" ? 1 : 0;
  }
  if (paymentState && ["paid", "in_payment", "reversed"].includes(paymentState)) {
    return 2;
  }
  if (state === "posted" || state === "cancel") {
    return 1;
  }

  return 0;
}
