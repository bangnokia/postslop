#!/usr/bin/env python3
"""Import only Postslop's three sites from an explicitly selected Chrome profile on macOS.

No network requests or browser changes are made. Decrypting cookies asks macOS for
Chrome Safe Storage once. Verify the signed-in identities in Chrome before supplying
their handles. The sessions are written to stdout for `postslop setup`, which saves
them; it refuses to print them to a terminal.
"""

from __future__ import annotations

import argparse
import ctypes
import hashlib
import hmac
import json
import os
from pathlib import Path
import sqlite3
import subprocess
import sys
import time

from import_firefox_sessions import (
    DOMAINS, MAX_LOCAL_STORAGE_BYTES, ImportFailure, bluesky_storage, database_snapshot, decode_snappy, report,
)


CHROME_EPOCH_OFFSET = 11644473600


def chrome_key() -> bytes:
    try:
        result = subprocess.run(
            ["/usr/bin/security", "find-generic-password", "-w", "-s", "Chrome Safe Storage"],
            capture_output=True, timeout=30, check=False,
        )
    except subprocess.TimeoutExpired:
        raise ImportFailure("Chrome Safe Storage access timed out; check the macOS Keychain prompt and retry.") from None
    password = result.stdout.removesuffix(b"\n")
    if result.returncode or not password:
        raise ImportFailure("Chrome Safe Storage was unavailable or access was denied.")
    # Chromium's v10 macOS derivation (not the Linux fallback password):
    # https://chromium.googlesource.com/chromium/src/+/ec82d4ab8/components/os_crypt/os_crypt_mac.mm
    return hashlib.pbkdf2_hmac("sha1", password, b"saltysalt", 1003, dklen=16)


def decrypt_cookie(encrypted: bytes, host: str, key: bytes) -> str:
    if not encrypted.startswith(b"v10") or len(encrypted) <= 3 or (len(encrypted) - 3) % 16:
        raise ImportFailure("Unsupported Chrome cookie encryption.")
    library = ctypes.CDLL("/usr/lib/system/libcommonCrypto.dylib")
    crypt = library.CCCrypt
    crypt.argtypes = [
        ctypes.c_uint32, ctypes.c_uint32, ctypes.c_uint32, ctypes.c_void_p,
        ctypes.c_size_t, ctypes.c_void_p, ctypes.c_void_p, ctypes.c_size_t,
        ctypes.c_void_p, ctypes.c_size_t, ctypes.POINTER(ctypes.c_size_t),
    ]
    crypt.restype = ctypes.c_int32
    ciphertext = encrypted[3:]
    output = ctypes.create_string_buffer(len(ciphertext))
    length = ctypes.c_size_t()
    # kCCDecrypt=1, kCCAlgorithmAES=0, kCCOptionPKCS7Padding=1 (CBC default).
    status = crypt(1, 0, 1, key, len(key), b" " * 16, ciphertext, len(ciphertext),
                   output, len(output), ctypes.byref(length))
    if status:
        raise ImportFailure("Chrome cookie decryption failed.")
    plaintext = output.raw[:length.value]
    # Schema 24 binds each encrypted value to its host via a SHA256 prefix.
    # https://chromium.googlesource.com/chromium/src/+/refs/heads/main/net/extras/sqlite/sqlite_persistent_cookie_store.cc
    if not hmac.compare_digest(plaintext[:32], hashlib.sha256(host.encode()).digest()):
        raise ImportFailure("Chrome cookie host verification failed.")
    try:
        return plaintext[32:].decode("utf-8")
    except UnicodeDecodeError:
        raise ImportFailure("Chrome cookie contains invalid text.") from None


def read_cookies(connection: sqlite3.Connection, key: bytes, domain: str) -> tuple[list[dict], dict]:
    version = connection.execute("SELECT value FROM meta WHERE key = 'version'").fetchone()
    required = {"host_key", "name", "value", "encrypted_value", "path", "expires_utc",
                "is_persistent", "is_secure", "is_httponly", "samesite", "top_frame_site_key"}
    columns = {row[1] for row in connection.execute("PRAGMA table_info(cookies)")}
    if version is None or str(version[0]) != "24" or not required.issubset(columns):
        raise ImportFailure("Unsupported Chrome cookie schema; only version 24 is supported.")
    rows = connection.execute("SELECT * FROM cookies WHERE host_key IN (?, ?)", (domain, "." + domain))
    cookies = []
    skipped = {"partitioned": 0, "multiAccount": 0, "expired": 0}
    now = time.time()
    for row in rows:
        if row["top_frame_site_key"]:
            skipped["partitioned"] += 1
            continue
        if domain == "x.com" and row["name"] == "auth_multi":
            skipped["multiAccount"] += 1
            continue
        expires = row["expires_utc"] / 1_000_000 - CHROME_EPOCH_OFFSET if row["is_persistent"] else -1
        if row["is_persistent"] and expires <= now:
            skipped["expired"] += 1
            continue
        same_site = {-1: "Lax", 0: "None", 1: "Lax", 2: "Strict"}.get(row["samesite"])
        if same_site is None or not all(isinstance(row[name], str) for name in ("host_key", "name", "path")):
            raise ImportFailure("Chrome cookie contains unsupported metadata.")
        if row["value"] or not isinstance(row["encrypted_value"], bytes):
            raise ImportFailure("Chrome cookie has an unsupported value format.")
        cookies.append({
            "name": row["name"], "value": decrypt_cookie(row["encrypted_value"], row["host_key"], key),
            "domain": row["host_key"], "path": row["path"], "expires": expires,
            "httpOnly": bool(row["is_httponly"]), "secure": bool(row["is_secure"]), "sameSite": same_site,
        })
    return cookies, skipped


def require_x_sign_in(cookies: list[dict]) -> None:
    for name in ("auth_token", "ct0", "twid"):
        matching = [cookie for cookie in cookies if cookie["name"] == name]
        if len(matching) != 1 or not matching[0]["value"] or matching[0]["path"] != "/":
            raise ImportFailure("Chrome has no unambiguous persisted X sign-in.")


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
    parser.add_argument("--x-handle", help="X handle already verified in Chrome; leave out to skip this site")
    parser.add_argument("--bluesky-handle", help="Bluesky handle already verified in Chrome; leave out to skip this site")
    parser.add_argument("--dev-null-handle", help="dev-null.sh handle already verified in Chrome; leave out to skip this site")
    arguments = parser.parse_args()
    # Only the sites given a handle are imported, so one account can be added or refreshed on its own.
    given = {"x": arguments.x_handle, "bluesky": arguments.bluesky_handle, "dev-null": arguments.dev_null_handle}
    handles = {platform: handle.lstrip("@") for platform, handle in given.items() if handle is not None}
    if not handles:
        parser.error("Give the handle of at least one site.")
    if not all(handle and all(character.isalnum() or character in "._-" for character in handle) for handle in handles.values()):
        parser.error("Expected handles must contain only letters, numbers, dots, underscores, or hyphens.")
    if sys.platform != "darwin":
        raise ImportFailure("Importing from Chrome is supported only on macOS.")
    profile = arguments.profile or default_profile()
    if not (profile / "Cookies").is_file():
        raise ImportFailure(f"No Chrome profile with cookies at {profile}. Pass --profile=DIR.")
    os.umask(0o077)
    # Read localStorage first, so a missing Bluesky sign-in fails before the Keychain prompt.
    local_storage = read_bluesky_storage(profile, handles["bluesky"]) if "bluesky" in handles else []
    states = {}
    accounts = {}
    with database_snapshot(profile / "Cookies") as connection:
        key = chrome_key()
        for platform, domain in DOMAINS.items():
            if platform not in handles:
                continue
            cookies, skipped = read_cookies(connection, key, domain)
            states[platform] = {"cookies": cookies, "origins": []}
            accounts[platform] = {
                "handle": handles[platform], "cookieCount": len(cookies), "skippedCookies": skipped,
                "localStorageCount": 0,
            }
    if "x" in handles:
        require_x_sign_in(states["x"]["cookies"])
    if "dev-null" in handles and not any(cookie["name"] == "devnull-session" or cookie["name"].startswith("remember_web_") for cookie in states["dev-null"]["cookies"]):
        raise ImportFailure("The selected Chrome profile has no usable persisted dev-null.sh sign-in.")
    if "bluesky" in handles:
        states["bluesky"]["origins"] = [{"origin": "https://bsky.app", "localStorage": local_storage}]
        accounts["bluesky"]["localStorageCount"] = len(local_storage)
    report("chrome", profile, states, accounts)
    return 0


if __name__ == "__main__":
    try:
        raise SystemExit(main())
    except ImportFailure as error:
        print(f"Import failed: {str(error).replace('Firefox', 'Chrome')}", file=sys.stderr)
        raise SystemExit(1)
    except (OSError, sqlite3.Error, subprocess.TimeoutExpired, ValueError, TypeError, IndexError):
        # Do not emit exception payloads, which could include paths or row data.
        print("Import failed: unable to read Chrome data.", file=sys.stderr)
        raise SystemExit(1)
