import { randomUUID } from "node:crypto";
import type { PostslopClient } from "./client";
import { DraftStore, fullyPublished, validateResult } from "./draft.ts";
import type { Draft } from "./draft";

export function taskIsActive(draft: Draft) {
  return Boolean(
    draft.backgroundTask &&
    Date.now() - draft.backgroundTask.queuedAt < 5 * 60_000,
  );
}

export function queueTask(
  store: DraftStore,
  draft: Draft,
  operation: "publish" | "check",
) {
  const queued: Draft = {
    ...draft,
    submitted: true,
    uncertainty: undefined,
    backgroundTask: { id: randomUUID(), operation, queuedAt: Date.now() },
  };
  store.saveTask(queued);
  store.save(queued);
  return queued;
}

export interface TaskNotice {
  success: boolean;
  title: string;
  message?: string;
}

export async function runTask(
  store: DraftStore,
  client: PostslopClient,
  id: string,
): Promise<TaskNotice | undefined> {
  const draft = store.loadTask(id);
  const task = draft.backgroundTask;
  if (!task || task.id !== id || task.claimed || !store.claimTask(id)) return;
  // Claim the task durably before any side effect, even if it is launched twice.
  store.saveTask({ ...draft, backgroundTask: { ...task, claimed: true } });
  try {
    if (task.operation === "publish") {
      const status = await client.status();
      if (!status.publishingEnabled)
        throw new Error("Publishing is disabled in Postslop.");
      for (const platform of draft.platforms) {
        const account = status.platforms.find((item) => item.id === platform);
        if (!account?.available)
          throw new Error(
            `${platform}: ${account?.reason || "Session unavailable."}`,
          );
      }
    }
    const result =
      task.operation === "check"
        ? await client.check(draft.requestId)
        : await client.publish(draft);
    validateResult(draft, result);
    const received = {
      ...draft,
      result,
      backgroundTask: undefined,
      uncertainty: undefined,
    };
    // The worker owns its result file; it never overwrites the foreground draft.
    store.saveTaskResult(id, received);
    store.finishTask(draft);
    if (fullyPublished(result)) {
      return {
        success: true,
        title: `Postslop: published to ${result.results.length} destination${result.results.length === 1 ? "" : "s"}`,
      };
    }
    const published = result.results.filter(
      (item) => item.status === "published",
    ).length;
    return {
      success: false,
      title: published
        ? `Postslop: published to ${published} destination${published === 1 ? "" : "s"}`
        : "Postslop: post saved",
      message: "Some destinations need attention. Open Saved Posts.",
    };
  } catch (error) {
    const reason =
      error instanceof Error ? error.message : "Publishing was not confirmed.";
    const retained: Draft = {
      ...draft,
      backgroundTask: undefined,
      uncertainty: `${reason} Use Check Result or Retry Safely; your draft is saved.`,
    };
    store.saveTaskResult(id, retained);
    store.finishTask(draft);
    return { success: false, title: "Postslop: draft kept", message: reason };
  }
}
