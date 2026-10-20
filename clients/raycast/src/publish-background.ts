import {
  environment,
  getPreferenceValues,
  LaunchProps,
  showHUD,
  PopToRootType,
} from "@raycast/api";
import { runTask, TaskNotice } from "./background";
import { clientFor, Preferences } from "./client";
import { DraftStore } from "./draft";

export default async function PublishBackground({
  launchContext,
}: LaunchProps<{ launchContext: { taskId?: string } }>) {
  if (!launchContext?.taskId) return;
  // A user-initiated no-view command can keep working after hiding Raycast and can use feedback APIs.
  await showHUD("Posting…", { popToRootType: PopToRootType.Immediate });
  const client = clientFor(getPreferenceValues<Preferences>());
  let notice: TaskNotice | undefined;
  try {
    notice = await runTask(
      new DraftStore(environment.supportPath),
      client,
      launchContext.taskId,
    );
  } catch {
    notice = {
      success: false,
      title: "Postslop: draft kept",
      message: "Open Postslop to recover the saved result.",
    };
  }
  if (!notice) return;
  await showHUD(
    notice.message ? `${notice.title} — ${notice.message}` : notice.title,
  );
}
