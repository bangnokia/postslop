import { randomUUID } from "node:crypto";
import { constants } from "node:fs";
import { chmod, copyFile, mkdir, open, stat, unlink } from "node:fs/promises";
import { basename, join } from "node:path";
import type { Attachment } from "./draft";

// Postslop takes videos up to 20 MiB; servers report their own limit with the accounts.
export const MAX_VIDEO_BYTES = 20 * 1024 * 1024;

export function isVideoPath(path: string) {
  return /\.(mp4|m4v|mov)$/i.test(path);
}

/**
 * Copies a chosen MP4 or MOV into the extension's private folder, unchanged, so the draft keeps it even if the
 * original moves. Its type comes from the file's own header, as Postslop checks it.
 */
export async function importVideo(
  support: string,
  source: string,
  maxBytes = MAX_VIDEO_BYTES,
): Promise<Attachment> {
  const info = await stat(source);
  if (!info.isFile() || info.size === 0)
    throw new Error("Choose an MP4 or MOV video file.");
  if (info.size > maxBytes)
    throw new Error(
      `This video is ${(info.size / 1024 / 1024).toFixed(1)} MiB. Postslop takes videos up to ${Math.floor(maxBytes / 1024 / 1024)} MiB; compress it first.`,
    );
  const header = Buffer.alloc(12);
  const handle = await open(source, "r");
  try {
    await handle.read(header, 0, 12, 0);
  } finally {
    await handle.close();
  }
  if (header.toString("latin1", 4, 8) !== "ftyp")
    throw new Error("Choose an MP4 or MOV video.");
  const quicktime = header.toString("latin1", 8, 12) === "qt  ";
  const directory = join(support, "videos");
  await mkdir(directory, { recursive: true, mode: 0o700 });
  await chmod(directory, 0o700);
  const destination = join(
    directory,
    `${randomUUID()}.${quicktime ? "mov" : "mp4"}`,
  );
  try {
    await copyFile(source, destination, constants.COPYFILE_EXCL);
    await chmod(destination, 0o600);
  } catch (error) {
    await unlink(destination).catch(() => {});
    throw error;
  }
  return {
    path: destination,
    filename: basename(destination),
    mimeType: quicktime ? "video/quicktime" : "video/mp4",
    bytes: info.size,
    alt: "",
  };
}
