// TEMPORARY test page for the report editor. Delete after testing.
import { useRef, useState } from "react";
import { createRoot } from "react-dom/client";
import { strFromU8, strToU8, unzipSync, zipSync } from "fflate";
import "./styles.css";
import { ReportDocEditor, type ReportDocHandle } from "./components/report/ReportDocEditor";
import { buildReportDocx } from "./components/report/docxTemplate";

const bytes = buildReportDocx("social", { title: "تقرير تجربة", header: "رأس", footer: "تذييل", client: "عميل", date: "اليوم" });

async function png(): Promise<Uint8Array> {
  const canvas = document.createElement("canvas");
  canvas.width = 800;
  canvas.height = 500;
  const ctx = canvas.getContext("2d")!;
  ctx.fillStyle = "#f59e0b";
  ctx.fillRect(0, 0, 800, 500);
  ctx.fillStyle = "#1e3a8a";
  ctx.fillRect(100, 100, 600, 300);
  const blob: Blob = await new Promise((resolve) => canvas.toBlob((b) => resolve(b!), "image/png"));
  return new Uint8Array(await blob.arrayBuffer());
}

async function textPng(text: string): Promise<Uint8Array> {
  const canvas = document.createElement("canvas");
  canvas.width = 1400;
  canvas.height = 1400;
  const ctx = canvas.getContext("2d")!;
  ctx.translate(700, 700);
  ctx.rotate(-Math.PI / 4);
  ctx.fillStyle = "rgba(120, 120, 120, 0.22)";
  ctx.font = "700 220px 'IBM Plex Sans Arabic', sans-serif";
  ctx.textAlign = "center";
  ctx.textBaseline = "middle";
  ctx.direction = "rtl";
  ctx.fillText(text, 0, 0);
  const blob: Blob = await new Promise((resolve) => canvas.toBlob((b) => resolve(b!), "image/png"));
  return new Uint8Array(await blob.arrayBuffer());
}

const WP = "http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing";
const A = "http://schemas.openxmlformats.org/drawingml/2006/main";
const PIC = "http://schemas.openxmlformats.org/drawingml/2006/picture";

function withWatermark(docx: Uint8Array, image: Uint8Array): Uint8Array {
  const files = unzipSync(docx);
  const size = 12 * 360000; // 12 cm square
  const pageW = 11906 * 635; // twips -> EMU
  const pageH = 16838 * 635;
  const run = `<w:r><w:drawing><wp:anchor xmlns:wp="${WP}" distT="0" distB="0" distL="0" distR="0" simplePos="0" relativeHeight="0" behindDoc="1" locked="0" layoutInCell="1" allowOverlap="1"><wp:simplePos x="0" y="0"/><wp:positionH relativeFrom="page"><wp:posOffset>${Math.round((pageW - size) / 2)}</wp:posOffset></wp:positionH><wp:positionV relativeFrom="page"><wp:posOffset>${Math.round((pageH - size) / 2)}</wp:posOffset></wp:positionV><wp:extent cx="${size}" cy="${size}"/><wp:effectExtent l="0" t="0" r="0" b="0"/><wp:wrapNone/><wp:docPr id="9001" name="HOC Watermark"/><wp:cNvGraphicFramePr><a:graphicFrameLocks xmlns:a="${A}" noChangeAspect="1"/></wp:cNvGraphicFramePr><a:graphic xmlns:a="${A}"><a:graphicData uri="${PIC}"><pic:pic xmlns:pic="${PIC}"><pic:nvPicPr><pic:cNvPr id="9001" name="watermark.png"/><pic:cNvPicPr/></pic:nvPicPr><pic:blipFill><a:blip r:embed="rIdWatermark"/><a:stretch><a:fillRect/></a:stretch></pic:blipFill><pic:spPr><a:xfrm><a:off x="0" y="0"/><a:ext cx="${size}" cy="${size}"/></a:xfrm><a:prstGeom prst="rect"><a:avLst/></a:prstGeom></pic:spPr></pic:pic></a:graphicData></a:graphic></wp:anchor></w:drawing></w:r>`;
  const header = strFromU8(files["word/header1.xml"]);
  files["word/header1.xml"] = strToU8(header.replace(/(<w:p>(?:<w:pPr>.*?<\/w:pPr>)?)/, `$1${run}`));
  files["word/_rels/header1.xml.rels"] = strToU8(`<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rIdWatermark" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="media/watermark.png"/></Relationships>`);
  files["word/media/watermark.png"] = image;
  const types = strFromU8(files["[Content_Types].xml"]);
  files["[Content_Types].xml"] = strToU8(types.replace("<Default Extension=\"xml\"", "<Default Extension=\"png\" ContentType=\"image/png\"/><Default Extension=\"xml\""));
  return zipSync(files);
}

function Harness() {
  const ref = useRef<ReportDocHandle>(null);
  const [doc, setDoc] = useState(bytes);
  const w = window as unknown as Record<string, unknown>;
  w.__editor = ref;
  w.__png = png;
  w.__watermark = async (text?: string) => {
    const image = text ? await textPng(text) : await png();
    setDoc(withWatermark(bytes, image));
  };
  w.__save = async () => Array.from(await ref.current!.save());
  return (
    <div style={{ position: "fixed", inset: 0 }}>
      <ReportDocEditor
        ref={ref}
        document={doc}
        title="t"
        locale="ar"
        onTitleChange={() => {}}
        onChange={() => {}}
        onSave={() => {}}
      />
    </div>
  );
}

createRoot(document.getElementById("root")!).render(<Harness />);
