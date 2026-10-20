#!/usr/bin/env python3
"""Import only Postslop's three sites from an explicitly selected Chrome profile on macOS.

No network requests or browser changes are made. Decrypting cookies asks macOS for
Chrome Safe Storage once. Verify the signed-in identities in Chrome before supplying
their handles. Output contains credentials and stays in the project's ignored
storage/sessions directory; stdout contains metadata only.
"""

from __future__ import annotations

import argparse
from datetime import datetime, timezone
import json
import os
from pathlib import Path
import sqlite3
import subprocess
import sys

from import_chrome_x import chrome_key, read_cookies, require_x_sign_in
from import_firefox_sessions import (
    DOMAINS, MAX_LOCAL_STORAGE_BYTES, SESSION_DIRECTORY, ImportFailure, atomic_json, bluesky_storage,
    database_snapshot, decode_snappy,
)


MAX_LEVELDB_BYTES = 256 * 1024 * 1024
# Chromium's localStorage key: "_" + origin + "\0" + a prefixed string.
# https://chromium.googlesource.com/chromium/src/+/main/components/services/storage/dom_storage/local_storage_impl.cc
BLUESKY_KEY = b"_https://bsky.app\x00"


def varint(data: bytes, position: int) -> tuple[int, int]:
    value = 0
    for shift in range(0, 64, 7):
        if position >= len(data):
            raise ImportFailure("Truncated Chrome localStorage record.")
        byte = data[position]
        position += 1
        value |= (byte & 0x7f) << shift
        if byte < 0x80:
            return value, position
    raise ImportFailure("Invalid Chrome localStorage varint.")


def sized(data: bytes, position: int) -> tuple[bytes, int]:
    length, position = varint(data, position)
    if position + length > len(data):
        raise ImportFailure("Truncated Chrome localStorage record.")
    return data[position:position + length], position + length


def log_entries(data: bytes):
    """Yield (sequence, key, value or None) from a LevelDB write-ahead log.

    Format: https://github.com/google/leveldb/blob/main/doc/log_format.md
    A torn final record from a live Chrome is skipped.
    """
    record = b""
    for block in range(0, len(data), 32768):
        position = block
        end = min(block + 32768, len(data))
        while position + 7 <= end:
            length = int.from_bytes(data[position + 4:position + 6], "little")
            kind = data[position + 6]
            payload = data[position + 7:position + 7 + length]
            position += 7 + length
            if kind == 0 or position > end:
                break
            record = payload if kind in (1, 2) else record + payload
            if kind in (1, 4):
                try:
                    yield from batch_entries(record)
                except ImportFailure:
                    return


def batch_entries(batch: bytes):
    if len(batch) < 12:
        raise ImportFailure("Truncated Chrome localStorage batch.")
    sequence = int.from_bytes(batch[:8], "little")
    count = int.from_bytes(batch[8:12], "little")
    position = 12
    for offset in range(count):
        kind = batch[position] if position < len(batch) else -1
        key, position = sized(batch, position + 1)
        if kind == 1:
            value, position = sized(batch, position)
        elif kind == 0:
            value = None
        else:
            raise ImportFailure("Invalid Chrome localStorage batch.")
        yield sequence + offset, key, value


def table_block(data: bytes, offset: int, size: int) -> bytes:
    if offset + size + 5 > len(data):
        raise ImportFailure("Truncated Chrome localStorage table.")
    block = data[offset:offset + size]
    compression = data[offset + size]
    if compression == 1:
        return decode_snappy(block)
    if compression != 0:
        raise ImportFailure("Unsupported Chrome localStorage compression.")
    return block


def block_entries(block: bytes):
    if len(block) < 4:
        raise ImportFailure("Truncated Chrome localStorage block.")
    restarts = int.from_bytes(block[-4:], "little")
    end = len(block) - 4 - 4 * restarts
    position = 0
    key = b""
    while position < end:
        shared, position = varint(block, position)
        unshared, position = varint(block, position)
        length, position = varint(block, position)
        key = key[:shared] + block[position:position + unshared]
        position += unshared
        yield key, block[position:position + length]
        position += length


def table_entries(data: bytes):
    """Yield (sequence, key, value or None) from a LevelDB table.

    Format: https://github.com/google/leveldb/blob/main/doc/table_format.md
    """
    if len(data) < 48 or data[-8:] != bytes.fromhex("57fb808b247547db"):
        raise ImportFailure("Invalid Chrome localStorage table.")
    footer = data[-48:]
    _, position = varint(footer, varint(footer, 0)[1])
    index_offset, position = varint(footer, position)
    index_size, _ = varint(footer, position)
    for _, handle in block_entries(table_block(data, index_offset, index_size)):
        offset, position = varint(handle, 0)
        size, _ = varint(handle, position)
        for internal, value in block_entries(table_block(data, offset, size)):
            trailer = int.from_bytes(internal[-8:], "little")
            yield trailer >> 8, internal[:-8], value if trailer & 0xff == 1 else None


def decode_storage_string(data: bytes) -> str:
    # Chromium prefixes stored strings with 0 for UTF-16LE or 1 for Latin-1.
    if data[:1] == b"\x00" and len(data) % 2 == 1:
        return data[1:].decode("utf-16-le", errors="surrogatepass")
    if data[:1] == b"\x01":
        return data[1:].decode("latin-1")
    raise ImportFailure("Unsupported Chrome localStorage encoding.")


def read_bluesky_storage(profile: Path, expected_handle: str) -> list[dict]:
    directory = profile / "Local Storage" / "leveldb"
    files = sorted([*directory.glob("*.log"), *directory.glob("*.ldb")])
    if not files:
        raise ImportFailure("The selected Chrome profile has no localStorage.")
    if sum(file.stat().st_size for file in files) > MAX_LEVELDB_BYTES:
        raise ImportFailure("Chrome localStorage exceeds the importer's size limit.")
    latest: tuple[int, bytes | None] = (-1, None)
    for file in files:
        try:
            data = file.read_bytes()
        except FileNotFoundError:
            continue  # Compacted away while reading; its newer copy is read instead.
        entries = log_entries(data) if file.suffix == ".log" else table_entries(data)
        for sequence, key, value in entries:
            if key.startswith(BLUESKY_KEY) and decode_storage_string(key[len(BLUESKY_KEY):]) == "BSKY_STORAGE" and sequence > latest[0]:
                latest = (sequence, value)
    if latest[1] is None:
        raise ImportFailure("Bluesky has no persisted session in the selected profile.")
    if len(latest[1]) > MAX_LOCAL_STORAGE_BYTES:
        raise ImportFailure("Bluesky localStorage exceeds the importer's size limit.")
    try:
        value = decode_storage_string(latest[1])
    except UnicodeError:
        raise ImportFailure("Bluesky localStorage is not valid encoded JSON.") from None
    return bluesky_storage(value, expected_handle)


def default_profile() -> Path:
    base = Path.home() / "Library/Application Support/Google/Chrome"
    try:
        name = json.loads((base / "Local State").read_text())["profile"]["last_used"]
    except (OSError, ValueError, KeyError, TypeError):
        name = "Default"
    return base / name


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--profile", type=Path, help="Chrome profile directory (default: the last used profile)")
    parser.add_argument("--x-handle", required=True, help="X handle already verified in Chrome")
    parser.add_argument("--bluesky-handle", required=True, help="Bluesky handle already verified in Chrome")
    parser.add_argument("--dev-null-handle", required=True, help="dev-null.sh handle already verified in Chrome")
    parser.add_argument("--check-only", action="store_true", help="Validate capture; print metadata without exporting")
    arguments = parser.parse_args()
    handles = {
        "x": arguments.x_handle.lstrip("@"), "bluesky": arguments.bluesky_handle.lstrip("@"),
        "dev-null": arguments.dev_null_handle.lstrip("@"),
    }
    if not all(handle and all(character.isalnum() or character in "._-" for character in handle) for handle in handles.values()):
        parser.error("Expected handles must contain only letters, numbers, dots, underscores, or hyphens.")
    if sys.platform != "darwin":
        raise ImportFailure("Importing from Chrome is supported only on macOS.")
    profile = arguments.profile or default_profile()
    if not (profile / "Cookies").is_file():
        raise ImportFailure(f"No Chrome profile with cookies at {profile}. Pass --profile=DIR.")
    os.umask(0o077)
    # Read localStorage first, so a missing Bluesky sign-in fails before the Keychain prompt.
    local_storage = read_bluesky_storage(profile, handles["bluesky"])
    states = {}
    accounts = {}
    with database_snapshot(profile / "Cookies") as connection:
        key = chrome_key()
        for platform, domain in DOMAINS.items():
            cookies, skipped = read_cookies(connection, key, domain)
            states[platform] = {"cookies": cookies, "origins": []}
            accounts[platform] = {
                "handle": handles[platform], "cookieCount": len(cookies), "skippedCookies": skipped,
                "localStorageCount": 0, "file": platform + ".json", "importedSessionVerified": False,
            }
    require_x_sign_in(states["x"]["cookies"])
    if not any(cookie["name"] == "devnull-session" or cookie["name"].startswith("remember_web_") for cookie in states["dev-null"]["cookies"]):
        raise ImportFailure("The selected Chrome profile has no usable persisted dev-null.sh sign-in.")
    states["bluesky"]["origins"] = [{"origin": "https://bsky.app", "localStorage": local_storage}]
    accounts["bluesky"]["localStorageCount"] = len(local_storage)
    manifest = {
        "importedAt": datetime.now(timezone.utc).isoformat(), "source": "chrome",
        "accounts": accounts, "note": "Source account handles were supplied after browser verification; imported session replay is not yet verified.",
    }
    if not arguments.check_only:
        if SESSION_DIRECTORY.is_symlink():
            raise ImportFailure("Session directory must not be a symbolic link.")
        SESSION_DIRECTORY.mkdir(mode=0o700, parents=True, exist_ok=True)
        os.chmod(SESSION_DIRECTORY, 0o700)
        for platform, state in states.items():
            atomic_json(SESSION_DIRECTORY / (platform + ".json"), state)
        atomic_json(SESSION_DIRECTORY / "manifest.json", manifest)
    print(json.dumps({"exported": not arguments.check_only, "profile": str(profile), **manifest}, indent=2))
    return 0


if __name__ == "__main__":
    try:
        raise SystemExit(main())
    except ImportFailure as error:
        print(f"Import failed: {str(error).replace('Firefox', 'Chrome')}", file=sys.stderr)
        raise SystemExit(1)
    except (OSError, sqlite3.Error, subprocess.TimeoutExpired, ValueError, TypeError, IndexError):
        # Do not emit exception payloads, which could include paths or row data.
        print("Import failed: unable to read Chrome data or write private session files.", file=sys.stderr)
        raise SystemExit(1)
