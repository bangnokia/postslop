import { execFile } from "node:child_process";
import { constants } from "node:fs";
import { access } from "node:fs/promises";
import { join } from "node:path";
import type { Draft, Platform, PostResult } from "./draft";

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

interface Post {
  id: string;
  status: string;
  results: PostResult["results"];
}

// Postslop's command line runs the core in its own process: no server to start or wait for.
export class PostslopClient {
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
