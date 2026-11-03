import type { NewImage, NewVideo, Platform } from "./api";

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

// Postslop takes videos up to 20 MiB; a larger one is refused here, before it is read.
export const MAX_VIDEO_BYTES = 20 * 1024 * 1024;
export const VIDEO_TYPES = ["video/mp4", "video/quicktime"];

export function isVideoFile(file: File): boolean {
  return VIDEO_TYPES.includes(file.type) || /\.(mp4|m4v|mov)$/i.test(file.name);
}

const mebibytes = (bytes: number) => `${(bytes / 1024 / 1024).toFixed(1)} MiB`;

/**
 * Reads a picked or dropped MP4 or MOV as base64, unchanged: videos are never recompressed in the browser. The
 * type comes from the file's own header, as Postslop checks it, not from its name.
 */
export async function prepareVideo(file: File, maxBytes = MAX_VIDEO_BYTES): Promise<NewVideo> {
  if (file.size === 0) throw new Error("This video file is empty.");
  if (file.size > maxBytes) throw new Error(`This video is ${mebibytes(file.size)}. Postslop takes videos up to ${Math.floor(maxBytes / 1024 / 1024)} MiB; compress it first.`);
  const header = new Uint8Array(await file.slice(0, 12).arrayBuffer());
  const text = (from: number, to: number) => String.fromCharCode(...header.slice(from, to));
  if (text(4, 8) !== "ftyp") throw new Error("Choose an MP4 or MOV video.");
  const mimeType = text(8, 12) === "qt  " ? "video/quicktime" : "video/mp4";
  const data = await new Promise<string>((resolve, reject) => {
    const reader = new FileReader();
    reader.onload = () => resolve(String(reader.result).split(",")[1] ?? "");
    reader.onerror = () => reject(new Error("This video could not be read."));
    reader.readAsDataURL(file);
  });
  return { data, mimeType, alt: "" };
}

/** A length in seconds as m:ss. */
export function clock(seconds: number): string {
  const whole = Math.ceil(seconds);
  return `${Math.floor(whole / 60)}:${String(whole % 60).padStart(2, "0")}`;
}

/** Base64 bytes back into a Blob, in slices, so a large video never needs one huge binary string. */
export function base64ToBlob(data: string, mimeType: string): Blob {
  const parts: Uint8Array<ArrayBuffer>[] = [];
  const step = 4 * 1024 * 1024;
  for (let offset = 0; offset < data.length; offset += step) {
    const chunk = atob(data.slice(offset, offset + step));
    const bytes = new Uint8Array(chunk.length);
    for (let index = 0; index < chunk.length; index += 1) bytes[index] = chunk.charCodeAt(index);
    parts.push(bytes);
  }
  return new Blob(parts, { type: mimeType });
}

export interface LocalDraft {
  requestId: string;
  // Set when the composer edits a draft or scheduled post saved in Postslop.
  postId?: string;
  text: string;
  platforms: Platform[];
  images: NewImage[];
  // Never together with images.
  video?: NewVideo;
  scheduledAt: string;
}

// The unsent draft lives in this browser, images and video included, until it is published.
const database = new Promise<IDBDatabase>((resolve, reject) => {
  const request = indexedDB.open("postslop", 1);
  request.onupgradeneeded = () => request.result.createObjectStore("drafts");
  request.onsuccess = () => resolve(request.result);
  request.onerror = () => reject(new Error("Local draft storage is unavailable."));
});
database.catch(() => {});

// A video's bytes are kept under their own key and written only when the video changes, so typing never
// rewrites them; the draft itself keeps the video's type and description.
type StoredDraft = Omit<LocalDraft, "video"> & { video?: Omit<NewVideo, "data"> };
let storedVideoData: string | undefined;

function read<T>(db: IDBDatabase, key: string): Promise<T | undefined> {
  return new Promise((resolve, reject) => {
    const request = db.transaction("drafts").objectStore("drafts").get(key);
    request.onsuccess = () => resolve(request.result);
    request.onerror = () => reject(request.error);
  });
}

export async function loadDraft(): Promise<LocalDraft | undefined> {
  const db = await database;
  const stored = await read<StoredDraft>(db, "current");
  if (!stored) return undefined;
  const { video, ...draft } = stored;
  if (!video) return draft;
  const data = await read<string>(db, "current-video");
  if (typeof data !== "string" || data === "") return draft;
  storedVideoData = data;
  return { ...draft, video: { ...video, data } };
}

let chain = Promise.resolve();
export function saveDraft(draft: LocalDraft | null): Promise<void> {
  chain = chain
    .catch(() => {})
    .then(async () => {
      const db = await database;
      await new Promise<void>((resolve, reject) => {
        const transaction = db.transaction("drafts", "readwrite");
        const store = transaction.objectStore("drafts");
        const data = draft?.video?.data;
        if (draft) {
          const { video, ...rest } = draft;
          const stored: StoredDraft = video ? { ...rest, video: { mimeType: video.mimeType, alt: video.alt } } : rest;
          store.put(stored, "current");
        } else store.delete("current");
        if (data !== storedVideoData) {
          if (data) store.put(data, "current-video");
          else store.delete("current-video");
        }
        transaction.oncomplete = () => {
          storedVideoData = data;
          resolve();
        };
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
