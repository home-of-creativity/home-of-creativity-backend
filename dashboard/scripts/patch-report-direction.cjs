/**
 * The editor's Format menu calls setRtl and setLtr, and the handler ignores both.
 * This teaches that handler to set the paragraph's bidi flag. Safe to run twice.
 */
const fs = require("fs");
const path = require("path");

const file = path.join(__dirname, "..", "node_modules", "@docx-editor.dev", "react", "dist", "index.js");
if (!fs.existsSync(file)) {
  process.exit(0);
}

let source = fs.readFileSync(file, "utf8");
const directionFrom = 'case "subscript":return R.setRunProperty("vertAlign",{val:"subscript"});case "clearFormatting":';
const directionTo = 'case "subscript":return R.setRunProperty("vertAlign",{val:"subscript"});case "setRtl":return R.setParagraphProperty("bidi",{val:"1"});case "setLtr":return R.setParagraphProperty("bidi",{val:"0"});case "clearFormatting":';
const sizesFrom = "pa=[8,9,10,11,12,14,16,18,20,24,28,36,48,72]";
const sizesTo = "pa=[8,9,10,11,12,14,16,18,20,22,24,26,28,36,48,72]";

if (source.includes(directionFrom)) source = source.replace(directionFrom, directionTo);
else if (!source.includes(directionTo)) console.warn("report direction patch: direction handler did not match");

if (source.includes(sizesFrom)) source = source.replace(sizesFrom, sizesTo);

fs.writeFileSync(file, source);
