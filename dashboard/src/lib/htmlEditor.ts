export type HtmlToolbarAction = {
  id: string;
  label: string;
  title?: string;
  open: string;
  close: string;
  group?: "block" | "inline" | "list" | "align" | "media" | "history";
};

export const HTML_EDITOR_ACTIONS: HtmlToolbarAction[] = [
  { id: "section", label: "section", open: "<section>", close: "</section>", group: "block" },
  { id: "h1", label: "H1", open: "<h1>", close: "</h1>", group: "block" },
  { id: "h2", label: "H2", open: "<h2>", close: "</h2>", group: "block" },
  { id: "h3", label: "H3", open: "<h3>", close: "</h3>", group: "block" },
  { id: "p", label: "P", open: "<p>", close: "</p>", group: "block" },
  { id: "pre", label: "pre", open: "<pre>", close: "</pre>", group: "block" },
  { id: "blockquote", label: "❞", title: "Blockquote", open: "<blockquote>", close: "</blockquote>", group: "block" },
  { id: "strong", label: "B", title: "Bold", open: "<strong>", close: "</strong>", group: "inline" },
  { id: "em", label: "I", title: "Italic", open: "<em>", close: "</em>", group: "inline" },
  { id: "u", label: "U", title: "Underline", open: "<u>", close: "</u>", group: "inline" },
  { id: "ul", label: "•", title: "Bullet list", open: "<ul>\n<li>", close: "</li>\n</ul>", group: "list" },
  { id: "ol", label: "1.", title: "Numbered list", open: "<ol>\n<li>", close: "</li>\n</ol>", group: "list" },
  { id: "li", label: "li", open: "<li>", close: "</li>", group: "list" },
  { id: "left", label: "⬅", title: "Align left", open: '<p style="text-align:left">', close: "</p>", group: "align" },
  { id: "center", label: "↔", title: "Align center", open: '<p style="text-align:center">', close: "</p>", group: "align" },
  { id: "right", label: "➡", title: "Align right", open: '<p style="text-align:right">', close: "</p>", group: "align" },
  { id: "link", label: "🔗", title: "Link", open: '<a href="https://">', close: "</a>", group: "media" },
  { id: "img", label: "🖼", title: "Image", open: '<img src="" alt="" />', close: "", group: "media" },
  { id: "br", label: "br", open: "<br>", close: "", group: "inline" },
];

export function insertAroundSelection(el: HTMLTextAreaElement, open: string, close: string) {
  const start = el.selectionStart;
  const end = el.selectionEnd;
  const selected = el.value.slice(start, end) || (close ? "…" : "");
  const next = `${el.value.slice(0, start)}${open}${selected}${close}${el.value.slice(end)}`;
  const caret = start + open.length + selected.length + close.length;
  return { next, caret };
}

export function stripHtmlTags(value: string) {
  return value.replace(/<\/?[^>]+>/g, "");
}

export function unwrapAnchor(value: string) {
  return value.replace(/<a\b[^>]*>([\s\S]*?)<\/a>/gi, "$1");
}
