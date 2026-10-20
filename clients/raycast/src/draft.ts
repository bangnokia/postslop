import { randomUUID } from "node:crypto";
import {
  chmodSync,
  existsSync,
  mkdirSync,
  readFileSync,
  readdirSync,
  renameSync,
  statSync,
  unlinkSync,
  writeFileSync,
} from "node:fs";
import { dirname, join, resolve } from "node:path";

export const platforms = ["x", "bluesky", "dev-null", "linkedin"] as const;
export type Platform = (typeof platforms)[number];
export const platformNames: Record<Platform, string> = {
  x: "X",
  bluesky: "Bluesky",
  "dev-null": "dev-null.sh",
  linkedin: "LinkedIn",
};

export interface Attachment {
  path: string;
  filename: string;
  mimeType: string;
  bytes: number;
  alt: string;
}

export interface PostResult {
  requestId: string;
  status: "published" | "partial" | "failed" | "unknown";
  results: {
    platform: Platform;
    status: "published" | "failed" | "unknown";
    url?: string;
    error?: string;
  }[];
}

export interface Draft {
  requestId: string;
  text: string;
  platforms: Platform[];
  images: Attachment[];
  submitted: boolean;
  result?: PostResult;
  uncertainty?: string;
  previouslyPublished: Platform[];
  backgroundTask?: {
    id: string;
    operation: "publish" | "check";
    queuedAt: number;
    claimed?: boolean;
  };
}

export function newDraft(destinations: Platform[] = [...platforms]): Draft {
  return {
    requestId: randomUUID(),
    text: "",
    platforms: destinations,
    images: [],
    submitted: false,
    previouslyPublished: [],
  };
}

export function submissionAction(draft: Draft) {
  if (draft.result) {
    if (draft.result.results.some((item) => item.status === "unknown"))
      return "Check Result";
    if (draft.result.results.some((item) => item.status === "failed"))
      return "Retry Failed";
    return "Check Result";
  }
  return draft.submitted ? "Retry Safely" : "Publish";
}

export function rememberPublished(draft: Draft): Platform[] {
  return [
    ...new Set([
      ...draft.previouslyPublished,
      ...(draft.result?.results
        .filter((item) => item.status === "published")
        .map((item) => item.platform) ?? []),
    ]),
  ];
}

export function retryFailed(draft: Draft): Draft {
  if (submissionAction(draft) !== "Retry Failed")
    throw new Error("Check the previous result before retrying.");
  return {
    ...draft,
    requestId: randomUUID(),
    platforms: draft
      .result!.results.filter((item) => item.status === "failed")
      .map((item) => item.platform),
    submitted: false,
    result: undefined,
    uncertainty: undefined,
    previouslyPublished: rememberPublished(draft),
  };
}

export function validateResult(draft: Draft, result: PostResult) {
  if (
    result.requestId !== draft.requestId ||
    !Array.isArray(result.results) ||
    result.results.length !== draft.platforms.length ||
    new Set(result.results.map((item) => item.platform)).size !==
      draft.platforms.length ||
    !result.results.every(
      (item) =>
        draft.platforms.includes(item.platform) &&
        ["published", "failed", "unknown"].includes(item.status),
    )
  ) {
    throw new Error(
      "The response did not identify every destination for this post. Check Result before retrying.",
    );
  }
}

export function fullyPublished(result: PostResult) {
  return (
    result.status === "published" &&
    result.results.length > 0 &&
    result.results.every((item) => item.status === "published")
  );
}

export function cleanPostURL(value?: string): string | undefined {
  if (!value) return;
  try {
    const url = new URL(value);
    if (
      url.protocol !== "https:" ||
      !["x.com", "bsky.app", "dev-null.sh", "www.linkedin.com"].includes(
        url.hostname,
      )
    )
      return;
    for (const key of [...url.searchParams.keys()])
      if (key.startsWith("utm_")) url.searchParams.delete(key);
    return url.href;
  } catch {
    return;
  }
}

// Keep the existing filename so drafts from the former native composer survive migration.
export class DraftStore {
  readonly directory: string;
  readonly path: string;

  constructor(directory: string) {
    this.directory = directory;
    this.path = join(directory, "native-draft.json");
    mkdirSync(directory, { recursive: true, mode: 0o700 });
    chmodSync(directory, 0o700);
  }

  private write(path: string, value: unknown) {
    const temporary = `${path}.${randomUUID()}.tmp`;
    writeFileSync(temporary, JSON.stringify(value), { mode: 0o600 });
    chmodSync(temporary, 0o600);
    renameSync(temporary, path);
  }

  preferredPlatforms(): Platform[] {
    const path = join(this.directory, "destinations.json");
    if (!existsSync(path)) return [...platforms];
    const value: unknown = JSON.parse(readFileSync(path, "utf8"));
    if (
      !Array.isArray(value) ||
      !value.every((item) => platforms.includes(item))
    )
      throw new Error(
        "Saved destinations could not be read. The original file has been preserved.",
      );
    return value;
  }

  savePlatforms(value: Platform[]) {
    this.write(join(this.directory, "destinations.json"), value);
  }
  save(draft: Draft) {
    this.write(this.path, draft);
  }

  saveTask(draft: Draft) {
    const id = draft.backgroundTask?.id;
    if (!id || !/^[a-f0-9-]{36}$/.test(id))
      throw new Error("Invalid background task.");
    const directory = join(this.directory, "tasks");
    mkdirSync(directory, { recursive: true, mode: 0o700 });
    chmodSync(directory, 0o700);
    this.write(join(directory, `${id}.json`), draft);
  }

  loadTask(id: string): Draft {
    if (!/^[a-f0-9-]{36}$/.test(id))
      throw new Error("Invalid background task.");
    const path = join(this.directory, "tasks", `${id}.json`);
    return existsSync(path)
      ? this.parse(readFileSync(path, "utf8"))
      : this.load();
  }

  saveTaskResult(id: string, draft: Draft) {
    if (!/^[a-f0-9-]{36}$/.test(id))
      throw new Error("Invalid background task.");
    this.write(join(this.directory, "tasks", `${id}.result.json`), draft);
  }

  private withTaskResult(draft: Draft): Draft {
    const id = draft.backgroundTask?.id;
    if (!id || !/^[a-f0-9-]{36}$/.test(id)) return draft;
    const path = join(this.directory, "tasks", `${id}.result.json`);
    return existsSync(path) ? this.parse(readFileSync(path, "utf8")) : draft;
  }

  finishTask(draft: Draft) {
    const id = draft.backgroundTask?.id;
    if (!id || !/^[a-f0-9-]{36}$/.test(id)) return;
    const path = join(this.directory, "tasks", `${id}.json`);
    if (existsSync(path)) unlinkSync(path);
  }

  openNewPost(legacy?: string) {
    const previous = this.load(legacy);
    if (previous.text || previous.images.length || previous.submitted) {
      if (previous.backgroundTask) this.saveTask(previous);
      this.archive(previous);
    }
    const fresh = newDraft(this.preferredPlatforms());
    this.save(fresh);
    return fresh;
  }

  claimTask(id: string) {
    if (!/^[a-f0-9-]{36}$/.test(id))
      throw new Error("Invalid background task.");
    try {
      writeFileSync(join(this.directory, `task-${id}.lock`), "", {
        mode: 0o600,
        flag: "wx",
      });
      return true;
    } catch (error) {
      if ((error as NodeJS.ErrnoException).code === "EEXIST") return false;
      throw error;
    }
  }

  load(legacy?: string): Draft {
    const raw = existsSync(this.path)
      ? readFileSync(this.path, "utf8")
      : legacy;
    if (!raw) return newDraft(this.preferredPlatforms());
    const draft = this.withTaskResult(this.parse(raw));
    if (!existsSync(this.path)) this.save(draft);
    return draft;
  }

  private parse(raw: string): Draft {
    try {
      const value = JSON.parse(raw);
      const draft: Draft = { ...newDraft(this.preferredPlatforms()), ...value };
      if (
        typeof draft.text !== "string" ||
        !/^[a-f0-9-]{36}$/.test(draft.requestId) ||
        !Array.isArray(draft.platforms) ||
        !draft.platforms.every((item) => platforms.includes(item)) ||
        !Array.isArray(draft.images) ||
        !draft.images.every(
          (item) =>
            typeof item.path === "string" &&
            typeof item.filename === "string" &&
            typeof item.mimeType === "string" &&
            typeof item.alt === "string",
        ) ||
        !Array.isArray(draft.previouslyPublished)
      )
        throw new Error("Invalid draft");
      if (draft.result) validateResult(draft, draft.result);
      return draft;
    } catch {
      throw new Error(
        "The saved draft could not be read. It has been preserved; recover native-draft.json in the extension support folder before composing.",
      );
    }
  }

  archive(draft: Draft) {
    if (!/^[a-f0-9-]{36}$/.test(draft.requestId))
      throw new Error("Invalid saved post.");
    const directory = join(this.directory, "saved-posts");
    mkdirSync(directory, { recursive: true, mode: 0o700 });
    chmodSync(directory, 0o700);
    this.write(join(directory, `${draft.requestId}.json`), draft);
  }

  savedPosts(): Draft[] {
    const directory = join(this.directory, "saved-posts");
    if (!existsSync(directory)) return [];
    return readdirSync(directory)
      .filter((name) => /^[a-f0-9-]{36}\.json$/.test(name))
      .map((name) => ({
        path: join(directory, name),
        modified: statSync(join(directory, name)).mtimeMs,
      }))
      .sort((a, b) => b.modified - a.modified)
      .map(({ path }) =>
        this.withTaskResult(this.parse(readFileSync(path, "utf8"))),
      );
  }

  removeImages(images: Attachment[]) {
    const retained = new Set(
      this.savedPosts().flatMap((draft) =>
        draft.images.map((image) => image.path),
      ),
    );
    for (const image of images) {
      if (retained.has(image.path)) continue;
      // Only delete copies owned by this extension, never a user's selected source file.
      if (dirname(resolve(image.path)) !== resolve(this.directory, "images"))
        continue;
      try {
        unlinkSync(image.path);
      } catch {
        /* An unused private copy can be cleaned up later. */
      }
    }
  }
}
