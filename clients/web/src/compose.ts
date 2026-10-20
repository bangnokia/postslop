import type { NewImage, Platform } from "./api";

// crypto.randomUUID() exists only on https or localhost, and Postslop may be opened over plain http on another machine.
export function uuid(): string {
  const bytes = crypto.getRandomValues(new Uint8Array(16));
  bytes[6] = (bytes[6] & 0x0f) | 0x40;
  bytes[8] = (bytes[8] & 0x3f) | 0x80;
  const hex = [...bytes].map((byte) => byte.toString(16).padStart(2, "0")).join("");
  return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`;
}

const segmenter = new Intl.Segmenter(undefined, { granularity: "grapheme" });
const URL_PATTERN = /https?:\/\/\S+/g;
// X counts these scripts as two characters each.
const WIDE = /[ᄀ-ᅟ⺀-꓏가-힣豈-﫿︰-﹏＀-｠￠-￦]|[\u{1F000}-\u{1FFFF}]/u;

/**
 * Characters as each site counts them, closely enough for a counter. The
 * publishers still enforce each site's exact rule.
 */
export function length(text: string, platform: Platform): number {
  if (platform !== "x") return [...segmenter.segment(text)].length;
  let count = 0;
  const withoutUrls = text.replace(URL_PATTERN, () => {
    count += 23;
    return "";
  });
  for (const { segment } of segmenter.segment(withoutUrls)) count += WIDE.test(segment) ? 2 : 1;
  return count;
}

/**
 * Resizes and recompresses a picked or pasted image to a JPEG of at most 1 MiB,
 * the limit every destination accepts. Transparency becomes white.
 */
export async function prepareImage(file: File): Promise<NewImage> {
  if (!["image/png", "image/jpeg", "image/webp"].includes(file.type)) throw new Error("Choose a PNG, JPEG or WebP image.");
  if (file.size > 30 * 1024 * 1024) throw new Error("Choose an image smaller than 30 MB.");
  const bitmap = await createImageBitmap(file);
  try {
    const scale = Math.min(1, 2000 / Math.max(bitmap.width, bitmap.height));
    const canvas = document.createElement("canvas");
    canvas.width = Math.max(1, Math.round(bitmap.width * scale));
    canvas.height = Math.max(1, Math.round(bitmap.height * scale));
    const context = canvas.getContext("2d")!;
    context.fillStyle = "#ffffff";
    context.fillRect(0, 0, canvas.width, canvas.height);
    context.drawImage(bitmap, 0, 0, canvas.width, canvas.height);
    for (let quality = 0.9; quality >= 0.3; quality -= 0.1) {
      const data = canvas.toDataURL("image/jpeg", quality).split(",")[1];
      if (data.length <= 1_398_100) return { data, mimeType: "image/jpeg", alt: "" };
    }
    throw new Error("This image is too detailed. Resize it before attaching.");
  } finally {
    bitmap.close();
  }
}

export interface LocalDraft {
  requestId: string;
  // Set when the composer edits a draft or scheduled post saved in Postslop.
  postId?: string;
  text: string;
  platforms: Platform[];
  images: NewImage[];
  scheduledAt: string;
}

// The unsent draft lives in this browser, images included, until it is published.
const database = new Promise<IDBDatabase>((resolve, reject) => {
  const request = indexedDB.open("postslop", 1);
  request.onupgradeneeded = () => request.result.createObjectStore("drafts");
  request.onsuccess = () => resolve(request.result);
  request.onerror = () => reject(new Error("Local draft storage is unavailable."));
});
database.catch(() => {});

export async function loadDraft(): Promise<LocalDraft | undefined> {
  const db = await database;
  return new Promise((resolve, reject) => {
    const request = db.transaction("drafts").objectStore("drafts").get("current");
    request.onsuccess = () => resolve(request.result);
    request.onerror = () => reject(request.error);
  });
}

let chain = Promise.resolve();
export function saveDraft(draft: LocalDraft | null): Promise<void> {
  chain = chain
    .catch(() => {})
    .then(async () => {
      const db = await database;
      await new Promise<void>((resolve, reject) => {
        const transaction = db.transaction("drafts", "readwrite");
        if (draft) transaction.objectStore("drafts").put(draft, "current");
        else transaction.objectStore("drafts").delete("current");
        transaction.oncomplete = () => resolve();
        transaction.onerror = () => reject(new Error("Your draft could not be saved."));
      });
    });
  return chain;
}

export function blankDraft(platforms: Platform[]): LocalDraft {
  return { requestId: uuid(), text: "", platforms, images: [], scheduledAt: "" };
}

/** A datetime-local value, which has no zone, read as this browser's local time. */
export function toIso(local: string): string {
  return new Date(local).toISOString();
}

export function toLocalInput(iso: string): string {
  const date = new Date(iso);
  const pad = (value: number) => String(value).padStart(2, "0");
  return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}T${pad(date.getHours())}:${pad(date.getMinutes())}`;
}

export function when(iso: string): string {
  const date = new Date(iso);
  const sameYear = date.getFullYear() === new Date().getFullYear();
  return date.toLocaleString(undefined, {
    weekday: "short",
    day: "numeric",
    month: "short",
    ...(sameYear ? {} : { year: "numeric" }),
    hour: "2-digit",
    minute: "2-digit",
  });
}
