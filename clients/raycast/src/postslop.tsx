import {
  Action,
  ActionPanel,
  Detail,
  launchCommand,
  LaunchType,
  PopToRootType,
  environment,
  Form,
  getPreferenceValues,
  Icon,
  Keyboard,
  List,
  LocalStorage,
  showToast,
  showHUD,
  Toast,
  useNavigation,
} from "@raycast/api";
import { useEffect, useRef, useState } from "react";
import { join } from "node:path";
import { clientFor, Preferences, ServerStatus } from "./client";
import {
  Attachment,
  cleanPostURL,
  Draft,
  DraftStore,
  fullyPublished,
  newDraft,
  Platform,
  platformNames,
  platforms,
  rememberPublished,
  retryFailed,
  submissionAction,
} from "./draft";
import { importImage } from "./images";
import { queueTask, taskIsActive } from "./background";

function message(error: unknown) {
  return error instanceof Error
    ? error.message
    : "Something went wrong. Your draft is kept.";
}

function SavedPosts({
  posts,
  onRestore,
}: {
  posts: Draft[];
  onRestore: (draft: Draft) => void;
}) {
  const { pop } = useNavigation();
  return (
    <List navigationTitle="Saved Posts">
      <List.EmptyView
        title="No Saved Posts"
        description="New Post keeps your previous draft or result here."
      />
      {posts.map((post) => (
        <List.Item
          key={post.requestId}
          title={post.text.trim().split("\n")[0] || "Image post"}
          subtitle={
            post.result?.status || (post.submitted ? "Unconfirmed" : "Draft")
          }
          accessories={[
            { text: post.platforms.map((id) => platformNames[id]).join(", ") },
          ]}
          actions={
            <ActionPanel>
              <Action
                title="Open Saved Post"
                icon={Icon.Document}
                onAction={() => {
                  onRestore(post);
                  pop();
                }}
              />
            </ActionPanel>
          }
        />
      ))}
    </List>
  );
}

export default function Postslop() {
  const { push } = useNavigation();
  const [client] = useState(() =>
    clientFor(getPreferenceValues<Preferences>()),
  );
  const store = useRef<DraftStore | undefined>(undefined);
  const current = useRef<Draft | undefined>(undefined);
  const busyRef = useRef(false);
  const [draft, setDraft] = useState<Draft>();
  const [server, setServer] = useState<ServerStatus>();
  const [loadingError, setLoadingError] = useState<string>();
  const [refreshing, setRefreshing] = useState(false);
  const [busy, setBusy] = useState<string>();
  const [notice, setNotice] = useState("Connecting to Postslop…");
  const [formVersion, setFormVersion] = useState(0);

  async function refreshAccounts() {
    setRefreshing(true);
    try {
      const status = await client.status();
      setServer(status);
      setNotice(
        status.publishingEnabled
          ? current.current?.submitted
            ? "Previous post kept. Check its result before retrying."
            : "Ready to publish. Drafts are saved automatically."
          : "Publishing is disabled in Postslop.",
      );
    } catch (error) {
      setServer(undefined);
      setNotice(message(error));
    } finally {
      setRefreshing(false);
    }
  }

  useEffect(() => {
    let active = true;
    void (async () => {
      try {
        const saved = new DraftStore(environment.supportPath);
        const legacy = await LocalStorage.getItem<string>("postslap-draft-v1");
        if (!active) return;
        const initial = saved.openNewPost(legacy);
        store.current = saved;
        current.current = initial;
        setDraft(initial);
        await refreshAccounts();
      } catch (error) {
        if (active) setLoadingError(message(error));
      }
    })();
    return () => {
      active = false;
    };
    // The client and extension support directory are fixed for this command's lifetime.
  }, []);

  useEffect(() => {
    if (!draft?.backgroundTask) return;
    const timer = setInterval(() => {
      try {
        const saved = store.current!.load();
        if (saved.result && fullyPublished(saved.result)) {
          store.current!.archive(saved);
          commit(newDraft(store.current!.preferredPlatforms()));
          setNotice("Published. Ready for your next post.");
          setFormVersion((value) => value + 1);
          return;
        }
        current.current = saved;
        setDraft(saved);
        if (!saved.backgroundTask && !saved.text && !saved.images.length) {
          setNotice("Published. Ready for your next post.");
          setFormVersion((value) => value + 1);
        }
      } catch {
        setNotice(
          "Could not read the background result. Reopen Postslop to recover it.",
        );
      }
    }, 1000);
    return () => clearInterval(timer);
  }, [draft?.backgroundTask?.id]);

  function commit(next: Draft) {
    store.current!.save(next);
    current.current = next;
    setDraft(next);
  }

  function edit(patch: Partial<Pick<Draft, "text" | "platforms" | "images">>) {
    if (!current.current || current.current.submitted) return;
    const next = { ...current.current, ...patch };
    try {
      commit(next);
      if (patch.platforms) store.current!.savePlatforms(patch.platforms);
      setNotice("Draft saved on this Mac.");
    } catch {
      current.current = next;
      setDraft(next);
      setNotice(
        "Could not save the draft. Keep Raycast open and check disk permissions before publishing.",
      );
    }
  }

  // Only accounts that are set up and turned on are offered.
  const offered = platforms.filter((id) => {
    const account = server?.platforms.find((item) => item.id === id);
    return (
      !account || (account.connected !== false && account.enabled !== false)
    );
  });
  useEffect(() => {
    const value = current.current;
    if (!server || !value || value.submitted) return;
    const kept = value.platforms.filter((id) => offered.includes(id));
    if (kept.length !== value.platforms.length)
      commit({ ...value, platforms: kept });
  }, [server, draft?.requestId]);

  function imageLimit(value: Draft) {
    return Math.min(
      4,
      ...value.platforms.map(
        (id) =>
          server?.platforms.find((item) => item.id === id)?.maxImages ??
          (id === "dev-null" ? 3 : 4),
      ),
    );
  }

  async function changeImages(paths: (string | undefined)[]) {
    const previous = current.current;
    if (!previous || previous.submitted || busyRef.current) return;
    if (paths.length > imageLimit(previous)) {
      await showToast({
        style: Toast.Style.Failure,
        title: `Selected destinations allow ${imageLimit(previous)} images`,
      });
      return;
    }
    busyRef.current = true;
    setBusy("Preparing images…");
    const imported: Attachment[] = [];
    try {
      const retained = previous.images.filter((image) =>
        paths.includes(image.path),
      );
      for (const path of paths) {
        if (path && previous.images.some((image) => image.path === path))
          continue;
        imported.push(
          await importImage(
            join(environment.assetsPath, "image-helper"),
            environment.supportPath,
            path,
          ),
        );
      }
      commit({ ...current.current!, images: [...retained, ...imported] });
      store.current!.removeImages(
        previous.images.filter((image) => !paths.includes(image.path)),
      );
      setNotice("Images attached and saved on this Mac.");
    } catch (error) {
      store.current!.removeImages(
        imported.filter(
          (image) =>
            !current.current?.images.some((item) => item.path === image.path),
        ),
      );
      setNotice(message(error));
      await showToast({
        style: Toast.Style.Failure,
        title: "Could Not Attach Image",
        message: message(error),
      });
    } finally {
      busyRef.current = false;
      setBusy(undefined);
    }
  }

  async function publish(forceCheck = false) {
    let submitted = current.current;
    if (!submitted) return;
    if (taskIsActive(submitted)) {
      await showHUD("Posting…", { popToRootType: PopToRootType.Immediate });
      return;
    }
    if (busyRef.current) return;
    busyRef.current = true;
    const checking =
      forceCheck || submissionAction(submitted) === "Check Result";
    let queued: Draft | undefined;
    try {
      if (!checking) {
        if (submissionAction(submitted) === "Retry Failed")
          submitted = retryFailed(submitted);
        if (!submitted.text.trim() && !submitted.images.length)
          throw new Error("Write something or attach an image first.");
        if (!submitted.platforms.length)
          throw new Error("Choose at least one destination.");
        if (submitted.images.length > imageLimit(submitted))
          throw new Error(
            `Selected destinations allow ${imageLimit(submitted)} images.`,
          );
        if (
          Array.from(submitted.text).length > 3000 ||
          submitted.images.some((image) => Array.from(image.alt).length > 1000)
        )
          throw new Error(
            "Use up to 3,000 characters of text and 1,000 characters of image description.",
          );
      }
      queued = queueTask(
        store.current!,
        submitted,
        checking ? "check" : "publish",
      );
      current.current = queued;
      setDraft(queued);
      await launchCommand({
        name: "publish-background",
        type: LaunchType.UserInitiated,
        context: { taskId: queued.backgroundTask!.id },
      });
    } catch (error) {
      if (
        queued &&
        store.current!.load().backgroundTask?.id === queued.backgroundTask?.id
      ) {
        // A launch failure must keep the same request ID for safe recovery.
        commit({
          ...queued,
          backgroundTask: undefined,
          uncertainty:
            "Background launch was not confirmed. Use Check Result or Retry Safely.",
        });
      }
      setNotice(message(error));
      const toast = await showToast({
        style: Toast.Style.Failure,
        title: "Could Not Queue Post",
        message: message(error),
      });
      setTimeout(() => void toast.hide(), 2000);
    } finally {
      busyRef.current = false;
    }
  }

  function clearDraft() {
    if (busyRef.current || !current.current) return;
    const previous = current.current;
    try {
      commit(newDraft(store.current!.preferredPlatforms()));
      store.current!.removeImages(previous.images);
      setFormVersion((value) => value + 1);
      setNotice("Ready for your next post.");
    } catch {
      setNotice("Could not clear the saved draft. Check disk permissions.");
    }
  }

  function startNewPost() {
    const previous = current.current;
    if (!previous || busyRef.current || taskIsActive(previous)) return;
    try {
      if (previous.text || previous.images.length || previous.submitted)
        store.current!.archive(previous);
      commit(newDraft(store.current!.preferredPlatforms()));
      setFormVersion((value) => value + 1);
      setNotice("Ready for a new post. Your previous post is in Saved Posts.");
    } catch (error) {
      setNotice(message(error));
    }
  }

  function showSavedPosts() {
    if (busyRef.current || !current.current || taskIsActive(current.current))
      return;
    try {
      push(
        <SavedPosts
          posts={store.current!.savedPosts()}
          onRestore={(saved) => {
            const previous = current.current!;
            if (previous.text || previous.images.length || previous.submitted)
              store.current!.archive(previous);
            commit(saved);
            setFormVersion((value) => value + 1);
            setNotice("Saved post restored.");
          }}
        />,
      );
    } catch (error) {
      setNotice(message(error));
    }
  }

  function editAsNewPost() {
    if (!current.current?.result || busyRef.current) return;
    const previous = current.current;
    try {
      commit({
        ...newDraft(previous.platforms),
        text: previous.text,
        images: previous.images,
        previouslyPublished: rememberPublished(previous),
      });
      setFormVersion((value) => value + 1);
      setNotice(
        "Editing a new post. Publishing sends it again to the selected destinations.",
      );
    } catch {
      setNotice("Could not save the draft. Check disk permissions.");
    }
  }

  if (loadingError)
    return <Detail navigationTitle="Postslop" markdown={loadingError} />;
  if (!draft)
    return (
      <Detail
        navigationTitle="Postslop"
        isLoading
        markdown={
          busy ? `${busy}\n\nYour draft is saved.` : "Loading your saved draft…"
        }
      />
    );
  const inBackground = taskIsActive(draft);
  const action = inBackground ? "Posting…" : submissionAction(draft);
  const locked = draft.submitted;
  const canEditResult = Boolean(
    !inBackground &&
    draft.result &&
    !draft.result.results.some((item) => item.status === "unknown"),
  );
  const warning =
    !locked && draft.previouslyPublished.length
      ? `A previous version was published to ${draft.previouslyPublished.map((id) => platformNames[id]).join(", ")}. This is a new post.`
      : undefined;
  return (
    <Form
      key={formVersion}
      navigationTitle="Postslop"
      isLoading={refreshing || Boolean(busy)}
      actions={
        <ActionPanel>
          <Action.SubmitForm
            title={action}
            icon={
              action === "Check Result" ? Icon.ArrowClockwise : Icon.ArrowUp
            }
            shortcut={{ modifiers: ["cmd"], key: "return" }}
            onSubmit={() => publish()}
          />
          {!locked && (
            <Action
              title="Paste Image"
              icon={Icon.Clipboard}
              shortcut={{ modifiers: ["ctrl", "shift"], key: "v" }}
              onAction={() =>
                changeImages([
                  ...draft.images.map((image) => image.path),
                  undefined,
                ])
              }
            />
          )}
          {!inBackground && (
            <Action
              title="New Post"
              icon={Icon.Plus}
              shortcut={Keyboard.Shortcut.Common.New}
              onAction={startNewPost}
            />
          )}
          {!inBackground && (
            <Action
              title="Saved Posts"
              icon={Icon.Clock}
              onAction={showSavedPosts}
            />
          )}
          {locked && !inBackground && action !== "Check Result" && (
            <Action
              title="Check Result"
              icon={Icon.ArrowClockwise}
              onAction={() => publish(true)}
            />
          )}
          {canEditResult && (
            <Action
              title="Edit as New Post"
              icon={Icon.Pencil}
              onAction={editAsNewPost}
            />
          )}
          <Action
            title="Refresh Accounts"
            icon={Icon.ArrowClockwise}
            shortcut={Keyboard.Shortcut.Common.Refresh}
            onAction={refreshAccounts}
          />
          {draft.result?.results.map((item) => {
            const url = cleanPostURL(item.url);
            return url ? (
              <Action.OpenInBrowser
                key={item.platform}
                title={`View ${platformNames[item.platform]} Post`}
                url={url}
              />
            ) : null;
          })}
          {(!locked || canEditResult) && (
            <Action
              title="Clear Draft"
              icon={Icon.Trash}
              style={Action.Style.Destructive}
              onAction={clearDraft}
            />
          )}
        </ActionPanel>
      }
    >
      {locked ? (
        <Form.Description title="Post" text={draft.text || "Images only"} />
      ) : (
        <Form.TextArea
          id="text"
          title="Post"
          placeholder="What’s on your mind?"
          value={draft.text}
          onChange={(text) => edit({ text })}
          autoFocus
        />
      )}
      {!locked && (
        <Form.FilePicker
          id="images"
          title="Images"
          value={draft.images.map((image) => image.path)}
          canChooseDirectories={false}
          allowMultipleSelection
          onChange={changeImages}
          info="Choose image files, or paste an image with ⌃⇧V. Remove an attachment using its × button."
        />
      )}
      <Form.Description
        text={
          busy ||
          `${Array.from(draft.text).length} characters · ${draft.images.length}/${imageLimit(draft)} images`
        }
      />
      {locked ? (
        <Form.Description
          title="Destinations"
          text={draft.platforms.map((id) => platformNames[id]).join(", ")}
        />
      ) : (
        <Form.TagPicker
          id="destinations"
          title="Destinations"
          value={draft.platforms}
          onChange={(value) => edit({ platforms: value as Platform[] })}
        >
          {offered.map((id) => (
            <Form.TagPicker.Item
              key={id}
              value={id}
              title={platformNames[id]}
            />
          ))}
        </Form.TagPicker>
      )}
      {!locked &&
        draft.images.map((image, index) => (
          <Form.TextField
            key={image.path}
            id={`alt-${index}`}
            title={`Image ${index + 1} Description`}
            placeholder="Optional alt text"
            value={image.alt}
            onChange={(alt) =>
              edit({
                images: draft.images.map((item) =>
                  item.path === image.path ? { ...item, alt } : item,
                ),
              })
            }
          />
        ))}
      <Form.Separator />
      <Form.Description
        title="Status"
        text={
          inBackground
            ? "Publishing in the background. You can close Raycast; a brief notification will show the result."
            : draft.uncertainty || notice
        }
      />
      {warning && <Form.Description title="New Post" text={warning} />}
      {draft.result?.results.map((item) => (
        <Form.Description
          key={item.platform}
          title={platformNames[item.platform]}
          text={
            item.status === "published"
              ? "Published ✓"
              : item.error || item.status
          }
        />
      ))}
      {locked && (
        <Form.Description
          text={
            action === "Check Result"
              ? "Check Result recovers the previous attempt without sending it again."
              : "Retry Safely reuses the same request ID. Retry Failed sends only to destinations that definitely failed."
          }
        />
      )}
      <Form.Separator />
      {platforms.map((id) => {
        const account = server?.platforms.find((item) => item.id === id);
        return (
          <Form.Description
            key={id}
            title={platformNames[id]}
            text={
              account
                ? `${account.handle ? `@${account.handle.replace(/^@/, "")}` : "No account"} · ${account.available ? "Ready" : account.reason || "Unavailable"}`
                : "Checking account…"
            }
          />
        );
      })}
      {!locked && (
        <Form.Description text="⌘↵ publish in background · ⌃⇧V paste image · ⌘V paste text." />
      )}
    </Form>
  );
}
