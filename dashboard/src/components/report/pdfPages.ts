/** How many pages a PDF file declares. Zero when the file has no page objects. */
export function pdfPageCount(bytes: Uint8Array): number {
  const text = new TextDecoder("latin1").decode(bytes);
  return text.match(/\/Type\s*\/Page(?!s)/g)?.length ?? 0;
}
