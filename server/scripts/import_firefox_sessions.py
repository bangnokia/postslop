#!/usr/bin/env python3
"""Import only Postslop's three sites from an explicitly selected Firefox profile.

No network requests or browser changes are made. Verify the signed-in identities in
Firefox before supplying their handles. The sessions are written to stdout for
`postslop setup`, which saves them; it refuses to print them to a terminal.
"""

from __future__ import annotations

import argparse
from contextlib import contextmanager
import json
import os
from pathlib import Path
import sqlite3
import sys
import tempfile
import time


DOMAINS = {"x": "x.com", "bluesky": "bsky.app", "dev-null": "dev-null.sh"}
MAX_DATABASE_BYTES = 128 * 1024 * 1024
MAX_LOCAL_STORAGE_BYTES = 8 * 1024 * 1024


class ImportFailure(Exception):
    """An actionable failure whose message never includes credential values."""


def file_signature(path: Path):
    try:
        info = path.stat()
        return info.st_dev, info.st_ino, info.st_size, info.st_mtime_ns, info.st_ctime_ns
    except FileNotFoundError:
        return None


def stable_database_files(path: Path) -> dict[str, bytes]:
    """Capture a quiet DB/WAL pair without opening or changing the live database."""
    paths = {suffix: Path(str(path) + suffix) for suffix in ("", "-wal", "-journal")}
    for _ in range(3):
        before = {suffix: file_signature(file) for suffix, file in paths.items()}
        if before[""] is None:
            raise ImportFailure(f"Required Firefox database is missing: {path.name}.")
        if before["-journal"] is not None and before["-journal"][2] > 0:
            raise ImportFailure("Firefox has a pending rollback journal. Retry when idle.")
        if any(signature and signature[2] > MAX_DATABASE_BYTES for signature in before.values()):
            raise ImportFailure("Firefox database exceeds the importer's size limit.")
        try:
            first = {suffix: file.read_bytes() for suffix, file in paths.items() if before[suffix]}
            middle = {suffix: file_signature(file) for suffix, file in paths.items()}
            second = {suffix: file.read_bytes() for suffix, file in paths.items() if middle[suffix]}
            after = {suffix: file_signature(file) for suffix, file in paths.items()}
        except FileNotFoundError:
            continue
        if before == middle == after and first == second:
            return first
        time.sleep(0.1)
    raise ImportFailure("Firefox changed its database during capture. Retry when idle.")


def write_private_file(path: Path, data: bytes) -> None:
    descriptor = os.open(path, os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600)
    with os.fdopen(descriptor, "wb") as output:
        output.write(data)
        output.flush()
        os.fsync(output.fileno())


@contextmanager
def database_snapshot(path: Path):
    files = stable_database_files(path)
    # SQLite replays committed WAL frames itself. The complete temporary snapshot
    # is private, never enters the repository, and is deleted even on failure.
    with tempfile.TemporaryDirectory(prefix="postslop-firefox-") as temporary:
        os.chmod(temporary, 0o700)
        snapshot = Path(temporary) / "snapshot.sqlite"
        for suffix, data in files.items():
            if suffix != "-journal":
                write_private_file(Path(str(snapshot) + suffix), data)
        connection = sqlite3.connect(snapshot)
        connection.row_factory = sqlite3.Row
        try:
            if [row[0] for row in connection.execute("PRAGMA integrity_check")] != ["ok"]:
                raise ImportFailure("Firefox database snapshot failed its integrity check.")
            connection.execute("PRAGMA query_only = ON")
            yield connection
        finally:
            connection.close()


def read_cookies(connection: sqlite3.Connection, domain: str) -> tuple[list[dict], dict]:
    columns = {row[1] for row in connection.execute("PRAGMA table_info(moz_cookies)")}
    required = {"name", "value", "host", "path", "expiry", "isSecure", "isHttpOnly", "sameSite", "originAttributes"}
    if not required.issubset(columns):
        raise ImportFailure("Unsupported Firefox cookie schema.")
    version = connection.execute("PRAGMA user_version").fetchone()[0]
    if version < 10 or version > 17:
        raise ImportFailure("Unsupported Firefox cookie schema version.")
    # Firefox schema 16 migrated expiry from seconds to milliseconds.
    # https://github.com/mozilla/gecko-dev/blob/master/netwerk/cookie/CookiePersistentStorage.cpp
    expiry_divisor = 1000 if version >= 16 else 1
    rows = connection.execute(
        "SELECT * FROM moz_cookies WHERE host = ? OR host = ? OR host LIKE ?",
        (domain, "." + domain, "%." + domain),
    )
    cookies = []
    skipped = {"partitionedOrContainer": 0, "expired": 0, "multiAccount": 0}
    for row in rows:
        if row["originAttributes"] or ("isPartitionedAttributeSet" in columns and row["isPartitionedAttributeSet"]):
            skipped["partitionedOrContainer"] += 1
            continue
        if domain == "x.com" and row["name"] == "auth_multi":
            skipped["multiAccount"] += 1
            continue
        expiry = row["expiry"] / expiry_divisor
        is_session = "isSession" in columns and bool(row["isSession"])
        if not is_session and expiry > 0 and expiry <= time.time():
            skipped["expired"] += 1
            continue
        same_site = {0: "None", 1: "Lax", 2: "Strict", 256: "Lax"}.get(row["sameSite"])
        if same_site is None:
            raise ImportFailure("Unsupported Firefox SameSite value.")
        if not all(isinstance(row[key], str) for key in ("name", "value", "host", "path")):
            raise ImportFailure("Firefox cookie contains an invalid text field.")
        cookies.append({
            "name": row["name"], "value": row["value"], "domain": row["host"],
            "path": row["path"], "expires": -1 if is_session or expiry <= 0 else expiry,
            "httpOnly": bool(row["isHttpOnly"]), "secure": bool(row["isSecure"]),
            "sameSite": same_site,
        })
    return cookies, skipped


def decode_snappy(data: bytes) -> bytes:
    """Decode Mozilla's raw Snappy block with bounded output and strict offsets.

    Format: https://github.com/google/snappy/blob/main/format_description.txt
    """
    position = 0
    expected = 0
    for shift in range(0, 35, 7):
        if position >= len(data):
            raise ImportFailure("Truncated Firefox localStorage compression header.")
        byte = data[position]
        position += 1
        expected |= (byte & 0x7f) << shift
        if byte < 0x80:
            break
    else:
        raise ImportFailure("Invalid Firefox localStorage compression header.")
    if expected > MAX_LOCAL_STORAGE_BYTES:
        raise ImportFailure("Firefox localStorage value exceeds the importer's size limit.")
    output = bytearray()
    while position < len(data):
        tag = data[position]
        position += 1
        kind = tag & 3
        length = (tag >> 2) + 1
        if kind == 0:
            if length > 60:
                width = length - 60
                if position + width > len(data):
                    raise ImportFailure("Truncated Firefox localStorage literal length.")
                length = int.from_bytes(data[position:position + width], "little") + 1
                position += width
            if position + length > len(data) or len(output) + length > expected:
                raise ImportFailure("Invalid Firefox localStorage literal.")
            output.extend(data[position:position + length])
            position += length
        else:
            width = {1: 1, 2: 2, 3: 4}[kind]
            if position + width > len(data):
                raise ImportFailure("Truncated Firefox localStorage backreference.")
            offset = int.from_bytes(data[position:position + width], "little")
            position += width
            if kind == 1:
                offset |= (tag & 0xe0) << 3
                length = 4 + ((tag >> 2) & 7)
            if offset < 1 or offset > len(output) or len(output) + length > expected:
                raise ImportFailure("Invalid Firefox localStorage backreference.")
            for _ in range(length):
                output.append(output[-offset])
    if len(output) != expected:
        raise ImportFailure("Firefox localStorage decompressed length does not match.")
    return bytes(output)


def read_bluesky_storage(profile: Path, expected_handle: str) -> list[dict]:
    path = profile / "storage/default/https+++bsky.app/ls/data.sqlite"
    with database_snapshot(path) as connection:
        if [row[0] for row in connection.execute("SELECT origin FROM database")] != ["https://bsky.app"]:
            raise ImportFailure("Bluesky localStorage has an unexpected origin.")
        row = connection.execute(
            "SELECT utf16_length, conversion_type, compression_type, value FROM data WHERE key = ?",
            ("BSKY_STORAGE",),
        ).fetchone()
    if row is None:
        raise ImportFailure("Bluesky has no persisted session in the selected profile.")
    if row["compression_type"] not in (0, 1) or row["conversion_type"] not in (0, 1):
        raise ImportFailure("Unsupported Firefox localStorage encoding.")
    data = bytes(row["value"])
    if row["compression_type"] == 1:
        data = decode_snappy(data)
    if len(data) > MAX_LOCAL_STORAGE_BYTES:
        raise ImportFailure("Bluesky localStorage exceeds the importer's size limit.")
    # Mozilla LSValue uses UTF-8 when conversion_type=1, native UTF-16 otherwise.
    encoding = "utf-8" if row["conversion_type"] else "utf-16-" + ("le" if sys.byteorder == "little" else "be")
    try:
        value = data.decode(encoding, errors="surrogatepass")
    except UnicodeError:
        raise ImportFailure("Bluesky localStorage is not valid encoded JSON.") from None
    if len(value.encode("utf-16-le", errors="surrogatepass")) // 2 != row["utf16_length"]:
        raise ImportFailure("Bluesky localStorage text length does not match.")
    return bluesky_storage(value, expected_handle)


def bluesky_storage(value: str, expected_handle: str) -> list[dict]:
    """Keep only the expected active account from Bluesky's BSKY_STORAGE JSON."""
    try:
        state = json.loads(value)
    except json.JSONDecodeError:
        raise ImportFailure("Bluesky localStorage is not valid encoded JSON.") from None
    session = state.get("session") if isinstance(state, dict) else None
    current = session.get("currentAccount") if isinstance(session, dict) else None
    accounts = session.get("accounts") if isinstance(session, dict) else None
    if not isinstance(current, dict) or not isinstance(accounts, list):
        raise ImportFailure("Bluesky has no identifiable active account.")
    matching = [account for account in accounts if isinstance(account, dict) and account.get("did") == current.get("did")]
    if len(matching) != 1 or not current.get("did"):
        raise ImportFailure("Bluesky's active account is ambiguous.")
    account = matching[0]
    if account.get("handle", "").lower() != expected_handle.lower():
        raise ImportFailure("Bluesky's active account does not match the expected handle.")
    if not account.get("accessJwt") or not account.get("refreshJwt"):
        raise ImportFailure("Bluesky's active account is missing session credentials.")
    # Keep the root preferences/schema but remove cached sign-ins for other users.
    session["accounts"] = [account]
    session["currentAccount"] = {"did": account["did"]}
    return [{"name": "BSKY_STORAGE", "value": json.dumps(state, ensure_ascii=True, separators=(",", ":"))}]


def report(source: str, profile: Path, states: dict, accounts: dict) -> None:
    """Hand the sessions to `postslop setup` through a pipe; never show them on a terminal."""
    if sys.stdout.isatty():
        raise ImportFailure("Run this through `postslop setup`, which saves the sessions privately.")
    print(json.dumps({"source": source, "profile": str(profile), "accounts": {
        platform: {**account, "session": states[platform]} for platform, account in accounts.items()
    }}))


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--profile", required=True, type=Path, help="Explicit Firefox profile directory")
    parser.add_argument("--x-handle", required=True, help="X handle already verified in Firefox")
    parser.add_argument("--bluesky-handle", required=True, help="Bluesky handle already verified in Firefox")
    parser.add_argument("--dev-null-handle", required=True, help="dev-null.sh handle already verified in Firefox")
    arguments = parser.parse_args()
    handles = {
        "x": arguments.x_handle.lstrip("@"), "bluesky": arguments.bluesky_handle.lstrip("@"),
        "dev-null": arguments.dev_null_handle.lstrip("@"),
    }
    if not all(handle and all(character.isalnum() or character in "._-" for character in handle) for handle in handles.values()):
        parser.error("Expected handles must contain only letters, numbers, dots, underscores, or hyphens.")
    os.umask(0o077)
    states = {}
    accounts = {}
    with database_snapshot(arguments.profile / "cookies.sqlite") as connection:
        for platform, domain in DOMAINS.items():
            cookies, skipped = read_cookies(connection, domain)
            states[platform] = {"cookies": cookies, "origins": []}
            accounts[platform] = {
                "handle": handles[platform], "cookieCount": len(cookies), "skippedCookies": skipped,
                "localStorageCount": 0,
            }
    if not {"auth_token", "ct0"}.issubset({cookie["name"] for cookie in states["x"]["cookies"]}):
        raise ImportFailure("The selected Firefox profile has no usable persisted X sign-in.")
    if not any(cookie["name"] == "devnull-session" or cookie["name"].startswith("remember_web_") for cookie in states["dev-null"]["cookies"]):
        raise ImportFailure("The selected Firefox profile has no usable persisted dev-null.sh sign-in.")
    local_storage = read_bluesky_storage(arguments.profile, handles["bluesky"])
    states["bluesky"]["origins"] = [{"origin": "https://bsky.app", "localStorage": local_storage}]
    accounts["bluesky"]["localStorageCount"] = len(local_storage)
    report("firefox", arguments.profile, states, accounts)
    return 0


if __name__ == "__main__":
    try:
        raise SystemExit(main())
    except ImportFailure as error:
        print(f"Import failed: {error}", file=sys.stderr)
        raise SystemExit(1)
    except (OSError, sqlite3.Error):
        # Do not emit exception payloads, which could include paths or row data.
        print("Import failed: unable to read Firefox data.", file=sys.stderr)
        raise SystemExit(1)
