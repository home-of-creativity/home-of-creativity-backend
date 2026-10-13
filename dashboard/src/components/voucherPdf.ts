import { toPng } from "html-to-image";
import { PDFDocument } from "pdf-lib";

const A4 = { width: 595.28, height: 841.89 };

/**
 * The voucher sheet as an A4 PDF, drawn from what the page shows. A sheet taller than one
 * page (a long notes record) continues on the next pages.
 */
export async function sheetToPdf(sheet: HTMLElement): Promise<Blob> {
  const png = await toPng(sheet, { pixelRatio: 2, backgroundColor: "#ffffff", cacheBust: true });
  const pdf = await PDFDocument.create();
  const image = await pdf.embedPng(png);
  const scale = A4.width / image.width;
  const height = image.height * scale;
  const pages = Math.max(1, Math.ceil(height / A4.height - 0.02));

  for (let index = 0; index < pages; index += 1) {
    const page = pdf.addPage([A4.width, A4.height]);
    // Each page shows the next A4-tall slice; the rest of the image falls outside the page.
    page.drawImage(image, { x: 0, y: A4.height - height + index * A4.height, width: A4.width, height });
  }

  const bytes = await pdf.save();
  return new Blob([bytes as BlobPart], { type: "application/pdf" });
}
