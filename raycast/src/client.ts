import { execFile } from "node:child_process";
import { constants } from "node:fs";
import { access, readFile } from "node:fs/promises";
import { join } from "node:path";
import { setTimeout } from "node:timers/promises";
import { promisify } from "node:util";
import type { Draft, Platform, PostResult } from "./draft";

const run = promisify(execFile);
export interface ServerStatus {
  publishingEnabled: boolean;
  platforms: {
    id: Platform;
    label: string;
    available: boolean;
    handle?: string;
    reason?: string;
    maxImages?: number;
  }[];
}

export class PostslapClient {
  readonly projectPath: string;
  readonly baseURL: string;
  readonly tokenPath: string;

  constructor(
    projectPath: string,
    baseURL = "http://127.0.0.1:8087",
    tokenPath = join(projectPath, "storage/client-token"),
  ) {
    this.projectPath = projectPath;
    this.baseURL = baseURL;
    this.tokenPath = tokenPath;
  }

  private async request<T>(
    path: string,
    body?: unknown,
    timeout = 120_000,
  ): Promise<T> {
    let token: string;
    try {
      token = (await readFile(this.tokenPath, "utf8")).trim();
    } catch {
      throw new Error(
        "Cannot read the local access token. Check the Postslap Project Folder preference and server setup.",
      );
    }
    if (!token) throw new Error("The local access token is empty.");
    const response = await fetch(this.baseURL + path, {
      method: body === undefined ? "GET" : "POST",
      headers: {
        Authorization: `Bearer ${token}`,
        Accept: "application/json",
        "Content-Type": "application/json",
      },
      body: body === undefined ? undefined : JSON.stringify(body),
      redirect: "error",
      signal: AbortSignal.timeout(timeout),
    });
    const data = await response.json().catch(() => {
      throw new Error(
        `Postslap returned an unreadable response (HTTP ${response.status}).`,
      );
    });
    if (!response.ok)
      throw new Error(
        data.error?.message || `Postslap returned HTTP ${response.status}.`,
      );
    return data as T;
  }

  async status(startIfNeeded = false): Promise<ServerStatus> {
    try {
      return await this.request<ServerStatus>("/api/status", undefined, 45_000);
    } catch (error) {
      const cause =
        error instanceof Error
          ? (error.cause as NodeJS.ErrnoException | undefined)
          : undefined;
      if (!startIfNeeded || cause?.code !== "ECONNREFUSED") throw error;
    }
    await access(join(this.projectPath, "cua"));
    const directories = [
      ...(process.env.PATH || "").split(":"),
      "/opt/homebrew/bin",
      "/usr/local/bin",
      "/usr/bin",
    ];
    let php: string | undefined;
    for (const directory of directories) {
      const candidate = join(directory, "php");
      try {
        await access(candidate, constants.X_OK);
        php = candidate;
        break;
      } catch {
        continue;
      }
    }
    if (!php)
      throw new Error(
        "PHP was not found. Install PHP or add it to Raycast's PATH.",
      );
    try {
      await run(php, ["cua", "serve", "--daemon"], {
        cwd: this.projectPath,
        timeout: 10_000,
        maxBuffer: 8192,
      });
    } catch {
      throw new Error(
        "Could not start Postslap. Check the project folder and PHP installation.",
      );
    }
    const deadline = Date.now() + 30_000;
    while (Date.now() < deadline) {
      try {
        return await this.request<ServerStatus>(
          "/api/status",
          undefined,
          Math.min(10_000, deadline - Date.now()),
        );
      } catch (error) {
        const cause =
          error instanceof Error
            ? (error.cause as NodeJS.ErrnoException | undefined)
            : undefined;
        if (cause?.code !== "ECONNREFUSED") throw error;
        await setTimeout(700);
      }
    }
    throw new Error(
      "Postslap did not become ready. Refresh accounts to try again.",
    );
  }

  async publish(draft: Draft): Promise<PostResult> {
    const images = await Promise.all(
      draft.images.map(async (image) => {
        const data = await readFile(image.path);
        if (data.length > 1_048_576)
          throw new Error(
            "An image exceeds 1 MiB. Remove it and attach it again.",
          );
        return {
          filename: image.filename,
          mimeType: image.mimeType,
          data: data.toString("base64"),
          alt: image.alt,
        };
      }),
    );
    return this.request("/api/posts", {
      requestId: draft.requestId,
      text: draft.text,
      platforms: draft.platforms,
      images,
    });
  }

  check(requestId: string): Promise<PostResult> {
    return this.request(`/api/posts/${encodeURIComponent(requestId)}`);
  }
}
