import fs from "node:fs/promises";
import path from "node:path";

const previewDir = new URL("./pdf-images/", import.meta.url);
const out = new URL("./output/lda-ai-cad-building-plan-verification-review.pdf", import.meta.url);

const files = (await fs.readdir(previewDir))
  .filter((file) => /^slide-\d+\.jpg$/.test(file))
  .sort();

function obj(n, body) {
  return `${n} 0 obj\n${body}\nendobj\n`;
}

function pdfString(value) {
  return String(value).replace(/[()\\]/g, "\\$&");
}

const objects = [];
const pages = [];
let next = 1;
const catalogId = next++;
const pagesId = next++;

for (const file of files) {
  const bytes = await fs.readFile(new URL(file, previewDir));
  const imageId = next++;
  const pageId = next++;
  const contentId = next++;
  const width = 1280;
  const height = 720;
  const pageW = 960;
  const pageH = 540;
  const content = `q\n${pageW} 0 0 ${pageH} 0 0 cm\n/Im0 Do\nQ\n`;

  objects[imageId] = obj(
    imageId,
    `<< /Type /XObject /Subtype /Image /Width ${width} /Height ${height} /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length ${bytes.length} >>\nstream\n${bytes.toString("binary")}\nendstream`,
  );
  objects[contentId] = obj(
    contentId,
    `<< /Length ${Buffer.byteLength(content, "binary")} >>\nstream\n${content}endstream`,
  );
  objects[pageId] = obj(
    pageId,
    `<< /Type /Page /Parent ${pagesId} 0 R /MediaBox [0 0 ${pageW} ${pageH}] /Resources << /XObject << /Im0 ${imageId} 0 R >> >> /Contents ${contentId} 0 R >>`,
  );
  pages.push(pageId);
}

objects[catalogId] = obj(catalogId, `<< /Type /Catalog /Pages ${pagesId} 0 R >>`);
objects[pagesId] = obj(pagesId, `<< /Type /Pages /Count ${pages.length} /Kids [${pages.map((id) => `${id} 0 R`).join(" ")}] >>`);

const header = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
let body = "";
const offsets = [0];
for (let i = 1; i < next; i += 1) {
  offsets[i] = Buffer.byteLength(header + body, "binary");
  body += objects[i];
}
const xrefOffset = Buffer.byteLength(header + body, "binary");
let xref = `xref\n0 ${next}\n0000000000 65535 f \n`;
for (let i = 1; i < next; i += 1) {
  xref += `${String(offsets[i]).padStart(10, "0")} 00000 n \n`;
}
const trailer = `trailer\n<< /Size ${next} /Root ${catalogId} 0 R /Info << /Title (${pdfString("AI-Based CAD Building Plan Verification & Pre-Scrutiny System")}) >> >>\nstartxref\n${xrefOffset}\n%%EOF\n`;

await fs.mkdir(path.dirname(out.pathname), { recursive: true });
await fs.writeFile(out, Buffer.from(header + body + xref + trailer, "binary"));
console.log(out.pathname);
