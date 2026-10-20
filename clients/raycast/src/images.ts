import { execFile } from "node:child_process";
import { randomUUID } from "node:crypto";
import { chmod, mkdir, stat, unlink } from "node:fs/promises";
import { basename, join } from "node:path";
import { promisify } from "node:util";
import type { Attachment } from "./draft";

const run = promisify(execFile);

export async function importImage(
  helper: string,
  support: string,
  source?: string,
): Promise<Attachment> {
  const directory = join(support, "images");
  await mkdir(directory, { recursive: true, mode: 0o700 });
  await chmod(directory, 0o700);
  const destination = join(directory, randomUUID());
  try {
    const { stdout } = await run(
      helper,
      source ? ["--file", source, destination] : ["--clipboard", destination],
      { timeout: 30_000, maxBuffer: 8192 },
    );
    const result = JSON.parse(stdout);
    if (
      result.path !== `${destination}.jpg` ||
      result.mimeType !== "image/jpeg"
    )
      throw new Error(
        result.error ||
          "No supported image found. Copy an image, then use Paste Image.",
      );
    const info = await stat(result.path);
    return {
      path: result.path,
      filename: basename(result.path),
      mimeType: result.mimeType,
      bytes: info.size,
      alt: "",
    };
  } catch (error) {
    await unlink(`${destination}.jpg`).catch(() => {});
    throw error;
  }
}
