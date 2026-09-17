import { PageHeader } from "../components/PageHeader";
import { copy, type Locale } from "../i18n";
import { PaymentsQr } from "./PaymentsQr";

export function Payments({ locale, t }: { locale: Locale; t: (c: { ar: string; en: string }) => string }) {
  return (
    <>
      <PageHeader title={t(copy.paymentsTitle)} lede={t(copy.paymentsLede)} />
      <PaymentsQr locale={locale} t={t} />
    </>
  );
}
