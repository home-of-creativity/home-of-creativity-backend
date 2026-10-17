/** Longest side kept in a report picture, in pixels. */
const MAX_EDGE = 2000;
const QUALITY = 0.85;
const TIMEOUT_MS = 8000;

/**
 * Shrink a picture before it enters the document. A small picture is returned as-is.
 * The result is JPEG at 85%, which the editor can place. Throws when the browser cannot finish in time.
 */
export async function shrinkImage(bytes: Uint8Array): Promise<Uint8Array> {
  const work = shrink(bytes);
  let timer = 0;
  const timeout = new Promise<never>((_, reject) => {
    timer = window.setTimeout(() => reject(new Error("image-timeout")), TIMEOUT_MS);
  });
  try {
    return await Promise.race([work, timeout]);
  } finally {
    window.clearTimeout(timer);
  }
}

async function shrink(bytes: Uint8Array): Promise<Uint8Array> {
  const blob = new Blob([bytes as BlobPart]);
  const bitmap = await createImageBitmap(blob);
  try {
    const edge = Math.max(bitmap.width, bitmap.height);
    if (edge <= MAX_EDGE) return bytes;
    const scale = MAX_EDGE / edge;
    const canvas = document.createElement("canvas");
    canvas.width = Math.max(1, Math.round(bitmap.width * scale));
    canvas.height = Math.max(1, Math.round(bitmap.height * scale));
    const context = canvas.getContext("2d");
    if (!context) return bytes;
    context.drawImage(bitmap, 0, 0, canvas.width, canvas.height);
    const out = await new Promise<Blob | null>((resolve) => canvas.toBlob(resolve, "image/jpeg", QUALITY));
    if (!out) return bytes;
    return new Uint8Array(await out.arrayBuffer());
  } finally {
    bitmap.close();
  }
}
