from pathlib import Path
text = Path(r"node_modules/@docx-editor.dev/react/dist/index.js").read_text(encoding="utf-8", errors="ignore")
i = text.find("toggleHeaderRow")
print(text[i:i+1800])
