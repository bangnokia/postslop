# Command line

`server/bin/postslop` runs the core directly, so Raycast, scripts and AI agents need no server running. From the repository root, `composer cli -- COMMAND` runs the same commands; see the [Composer shortcuts](development.md#composer-shortcuts). To call it as `postslop` from anywhere, link it onto your path from the repository root: `ln -s "$PWD/server/bin/postslop" /opt/homebrew/bin/postslop`.

```sh
postslop accounts                          # which accounts are ready
postslop accounts check threads            # recheck just Threads, without contacting other sites
postslop accounts disable bluesky          # stop using one (enable turns it back on, remove forgets it)
postslop post "Hello"                      # save a draft
postslop post "Hello" --now                # publish now
postslop post "Hello" --at="tomorrow 9am"  # schedule (local time)
postslop post "Look" --image=shot.png --to=x,bluesky --now
postslop posts --status=scheduled          # history, newest first
postslop show ID | publish ID | retry ID | schedule ID --at=… | delete ID
postslop dev                               # development with file watching
postslop start --daemon                    # web client, API, schedule and queues in the background
```

**A post is a draft unless you say `--now` or give a time.** So an AI agent can prepare posts safely, and nothing goes public without an explicit flag. `postslop help post` lists every option.

For scripts and agents, every command takes `--json` and prints exactly one JSON document, errors included. `--input=-` reads a whole post as JSON from standard input, with images by path and their descriptions. Exit codes are stable: `0` ok, `2` invalid, `3` partly published, `4` failed, `5` unknown outcome, `6` busy, `7` conflict, `8` not found. `--request-id=UUID` makes a command safe to repeat, and `--dry-run` checks a post and the accounts without saving anything. [agents.md](agents.md) is a guide to hand an AI agent.

`postslop setup` imports your browser sessions; see [accounts](accounts.md). [Scheduling](development.md#scheduling) explains how scheduled posts are sent.
