/** Turn a Google Docs or Word paste into HTML the editor can keep. */
export function cleanPastedHtml(html: string): string {
  let next = html.replace(/\s(?:id|name)="docs-internal-guid-[^"]*"/gi, "");
  next = next.replace(/href\s*=\s*(['"])\s*javascript:[^'"]*\1/gi, 'href="#"');
  next = next.replace(/href\s*=\s*(['"])\s*data:[^'"]*\1/gi, 'href="#"');
  next = next.replace(/<p\b([^>]*)class=(['"])[^'"]*MsoListParagraph[^'"]*\2([^>]*)>/gi, "<li$1$3>");
  next = next.replace(/<\/p>(?=\s*<li\b)/gi, "</li>");
  if (/[\u0600-\u06FF]/.test(next)) {
    next = next.replace(/<table\b(?![^>]*\bdir\s*=)/gi, '<table dir="rtl"');
  }
  return next;
}
