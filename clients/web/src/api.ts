// The types of /api/v1, as docs/api.md describes them.

export type Platform = "x" | "bluesky" | "dev-null";
export const PLATFORMS: Platform[] = ["x", "bluesky", "dev-null"];
export const PLATFORM_NAMES: Record<Platform, string> = { x: "X", bluesky: "Bluesky", "dev-null": "dev-null.sh" };

export type PostStatus = "draft" | "scheduled" | "publishing" | "published" | "partial" | "failed" | "unknown";

export interface Account {
  id: Platform;
  label: string;
  available: boolean;
  handle?: string;
  reason?: string;
  maxImages: number;
  textLimit: number;
}

export interface Accounts {
  platforms: Account[];
  limits: { maxImages: number; maxImageBytes: number };
  publishingEnabled: boolean;
}

export interface Result {
  platform: Platform;
  status: "published" | "failed" | "unknown";
  url?: string;
  error?: string;
}

export interface StoredImage {
  id: string;
  mimeType: string;
  width: number;
  height: number;
  alt: string;
}

export interface Post {
  id: string;
  status: PostStatus;
  text: string;
  platforms: Platform[];
  images: StoredImage[];
  results: Result[];
  scheduledAt: string | null;
  publishedAt: string | null;
  createdAt: string;
  updatedAt: string;
}

export interface NewImage {
  data: string;
  mimeType: string;
  alt: string;
}

export interface PostInput {
  requestId?: string;
  text: string;
  platforms: Platform[];
  images: NewImage[];
  mode: "now" | "draft" | "schedule";
  scheduledAt?: string;
}

export class ApiError extends Error {
  constructor(
    message: string,
    readonly code: string,
    readonly status: number,
  ) {
    super(message);
  }
}

const token = document.querySelector<HTMLMetaElement>('meta[name="postslop-token"]')?.content ?? "";

async function call<T>(method: string, path: string, body?: unknown): Promise<T> {
  let response: Response;
  try {
    response = await fetch(`/api/v1${path}`, {
      method,
      headers: {
        Authorization: `Bearer ${token}`,
        Accept: "application/json",
        ...(body === undefined ? {} : { "Content-Type": "application/json" }),
      },
      body: body === undefined ? undefined : JSON.stringify(body),
    });
  } catch {
    throw new ApiError("Postslop is not reachable. Start it with: postslop serve", "offline", 0);
  }
  if (response.status === 204) return undefined as T;
  const data = await response.json().catch(() => null);
  if (!response.ok) {
    throw new ApiError(data?.error?.message ?? `Request failed (HTTP ${response.status}).`, data?.error?.code ?? "error", response.status);
  }
  return data as T;
}

export const api = {
  accounts: (refresh = false) => call<Accounts>("GET", `/accounts${refresh ? "?refresh=1" : ""}`),
  posts: (status: PostStatus[] = [], before?: string) => {
    const query = new URLSearchParams({ limit: "20" });
    if (status.length) query.set("status", status.join(","));
    if (before) query.set("before", before);
    return call<{ posts: Post[]; next: string | null }>("GET", `/posts?${query}`);
  },
  post: (id: string) => call<Post>("GET", `/posts/${id}`),
  create: (input: PostInput) => call<Post>("POST", "/posts", input),
  update: (id: string, input: PostInput) => call<Post>("PUT", `/posts/${id}`, input),
  publish: (id: string) => call<Post>("POST", `/posts/${id}/publish`, {}),
  retry: (id: string) => call<Post>("POST", `/posts/${id}/retry`, {}),
  schedule: (id: string, scheduledAt: string | null) => call<Post>("POST", `/posts/${id}/schedule`, { scheduledAt }),
  remove: (id: string) => call<void>("DELETE", `/posts/${id}`),
  // Images need the token too, so they are fetched rather than linked.
  media: async (id: string) => {
    const response = await fetch(`/api/v1/media/${id}`, { headers: { Authorization: `Bearer ${token}` } });
    if (!response.ok) throw new ApiError("The image could not be loaded.", "error", response.status);
    return response.blob();
  },
};

// Share clean destination URLs only: https on the three sites, without tracking parameters.
export function cleanUrl(value?: string): string | undefined {
  if (!value) return;
  try {
    const url = new URL(value);
    if (url.protocol !== "https:" || !["x.com", "bsky.app", "dev-null.sh"].includes(url.hostname)) return;
    for (const key of [...url.searchParams.keys()]) if (key.startsWith("utm_")) url.searchParams.delete(key);
    return url.href;
  } catch {
    return;
  }
}
