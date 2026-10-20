import { execFile } from "node:child_process";
import { constants } from "node:fs";
import { access, readFile } from "node:fs/promises";
import { join } from "node:path";
import type { Draft, Platform, PostResult } from "./draft";

export interface ServerStatus {
  publishingEnabled: boolean;
  platforms: {
    id: Platform;
    label: string;
    available: boolean;
    // Set up and turned on in Postslop; older servers leave these out.
    connected?: boolean;
    enabled?: boolean;
    handle?: string;
    reason?: string;
    maxImages?: number;
  }[];
}

interface Post {
  id: string;
  status: string;
  results: PostResult["results"];
}

// What the composer and the background command need from Postslop, local or on a server.
export interface Postslop {
  status(): Promise<ServerStatus>;
  publish(draft: Draft): Promise<PostResult>;
  check(requestId: string): Promise<PostResult>;
}

export interface Preferences {
  projectPath?: string;
  serverUrl?: string;
  token?: string;
}

const DEFAULT_PROJECT = "/Users/daudau/Code/bangnokia/postslop/server";

// A Server URL sends posts to that Postslop's API; without one, the command line in the local checkout runs them.
export function clientFor(preferences: Preferences): Postslop {
  const url = preferences.serverUrl?.trim();
  if (url) return new ApiClient(url, preferences.token?.trim() ?? "");
  return new PostslopClient(preferences.projectPath || DEFAULT_PROJECT);
}

// Postslop's command line runs the core in its own process: no server to start or wait for.
export class PostslopClient implements Postslop {
  readonly projectPath: string;
  readonly env: Record<string, string>;

  constructor(projectPath: string, env: Record<string, string> = {}) {
    this.projectPath = projectPath;
    this.env = env;
  }

  private async php() {
    const directories = [
      ...(process.env.PATH || "").split(":"),
      "/opt/homebrew/bin",
      "/usr/local/bin",
      "/usr/bin",
    ];
    for (const directory of directories) {
      const candidate = join(directory, "php");
      try {
        await access(candidate, constants.X_OK);
        return candidate;
      } catch {
        continue;
      }
    }
    throw new Error(
      "PHP was not found. Install PHP or add it to Raycast's PATH.",
    );
  }

  private async run<T>(args: string[], input?: unknown): Promise<T> {
    const cli = join(this.projectPath, "bin/postslop");
    try {
      await access(cli);
    } catch {
      throw new Error(
        "Postslop was not found. Check the Postslop Server Folder preference.",
      );
    }
    const php = await this.php();
    // A nonzero exit still prints one JSON document: a post that partly failed, or an error.
    const stdout = await new Promise<string>((resolve, reject) => {
      const child = execFile(
        php,
        [cli, ...args, "--json"],
        {
          cwd: this.projectPath,
          env: { ...process.env, ...this.env },
          timeout: 150_000,
          maxBuffer: 1_048_576,
        },
        (error, output) => {
          if (output) resolve(output);
          else reject(error ?? new Error("Postslop printed nothing."));
        },
      );
      child.stdin?.end(input === undefined ? "" : JSON.stringify(input));
    });
    let data: T & { error?: { message?: string } };
    try {
      data = JSON.parse(stdout);
    } catch {
      throw new Error("Postslop returned an unreadable response.");
    }
    if (data.error) throw new Error(data.error.message || "Postslop failed.");
    return data;
  }

  status(): Promise<ServerStatus> {
    return this.run<ServerStatus>(["accounts"]);
  }

  async publish(draft: Draft): Promise<PostResult> {
    return result(
      await this.run<Post>(["post", "--input=-", "--now"], {
        requestId: draft.requestId,
        text: draft.text,
        platforms: draft.platforms,
        images: draft.images.map((image) => ({
          path: image.path,
          alt: image.alt,
        })),
      }),
    );
  }

  async check(requestId: string): Promise<PostResult> {
    return result(await this.run<Post>(["show", requestId]));
  }
}

// Postslop's HTTP API, for a Postslop on another machine. The server queues a post and answers at once;
// this waits for its outcome as the command line would.
export class ApiClient implements Postslop {
  readonly url: string;
  readonly token: string;

  constructor(url: string, token: string) {
    this.url = url.replace(/\/+$/, "");
    this.token = token;
  }

  private async call<T>(
    method: string,
    path: string,
    body?: unknown,
  ): Promise<T> {
    let response: Response;
    try {
      response = await fetch(`${this.url}/api/v1${path}`, {
        method,
        headers: {
          Accept: "application/json",
          ...(this.token ? { Authorization: `Bearer ${this.token}` } : {}),
          ...(body === undefined ? {} : { "Content-Type": "application/json" }),
        },
        body: body === undefined ? undefined : JSON.stringify(body),
        signal: AbortSignal.timeout(60_000),
      });
    } catch {
      throw new Error(
        `Postslop is not reachable at ${this.url}. Check the Server URL preference.`,
      );
    }
    if (response.status === 401)
      throw new Error(
        "Postslop refused the access token. Check the Access Token preference.",
      );
    const data = await response.json().catch(() => null);
    if (!response.ok)
      throw new Error(
        data?.error?.message || `Postslop answered HTTP ${response.status}.`,
      );
    return data as T;
  }

  status(): Promise<ServerStatus> {
    return this.call<ServerStatus>("GET", "/accounts");
  }

  async publish(draft: Draft): Promise<PostResult> {
    const images = await Promise.all(
      draft.images.map(async (image) => ({
        data: (await readFile(image.path)).toString("base64"),
        mimeType: image.mimeType,
        alt: image.alt,
      })),
    );
    // Repeating the request ID returns the post already made; it is never sent twice.
    const post = await this.call<Post>("POST", "/posts", {
      requestId: draft.requestId,
      text: draft.text,
      platforms: draft.platforms,
      images,
      mode: "now",
    });
    return result(await this.settled(post));
  }

  async check(requestId: string): Promise<PostResult> {
    return result(await this.call<Post>("GET", `/posts/${requestId}`));
  }

  // A post still queued or publishing after this long is reported as unknown; Check Result finds it later.
  private async settled(post: Post): Promise<Post> {
    const deadline = Date.now() + 150_000;
    while (
      ["queued", "publishing"].includes(post.status) &&
      Date.now() < deadline
    ) {
      await new Promise((resolve) => setTimeout(resolve, 1500));
      post = await this.call<Post>("GET", `/posts/${post.id}`);
    }
    return post;
  }
}

function result(post: Post): PostResult {
  return {
    requestId: post.id,
    // A post still publishing elsewhere has no outcome yet.
    status: ["published", "partial", "failed"].includes(post.status)
      ? (post.status as PostResult["status"])
      : "unknown",
    results: post.results,
  };
}
