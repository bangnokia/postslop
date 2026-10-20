#!/usr/bin/env python3
"""Create isolated fake browser sessions for the fake sites in tests/fakes."""
import json
import os
from pathlib import Path

root = Path(__file__).resolve().parents[1]
sessions = root / 'storage/test-sessions'
sessions.mkdir(parents=True, exist_ok=True, mode=0o700)
os.chmod(sessions, 0o700)

def private_json(name, value):
    path = sessions / name
    path.write_text(json.dumps(value))
    os.chmod(path, 0o600)

def cookie(name, value, domain):
    return {'name': name, 'value': value, 'domain': domain, 'path': '/', 'expires': -1, 'secure': False, 'httpOnly': True, 'sameSite': 'Lax'}

private_json('manifest.json', {'accounts': {'x': {'handle': '@tester'}, 'bluesky': {'handle': '@tester.test'}, 'dev-null': {'handle': '@tester'}}})
private_json('x.json', {'cookies': [cookie('auth_token', 'fixture-auth', '.x.com'), cookie('ct0', 'fixture-csrf', '.x.com')], 'origins': []})
account = {'did': 'did:plc:fixture', 'handle': 'tester.test', 'accessJwt': 'fixture-access', 'refreshJwt': 'fixture-refresh', 'pdsUrl': 'https://bsky.social', 'service': 'https://bsky.social'}
private_json('bluesky.json', {'cookies': [], 'origins': [{'origin': 'https://bsky.app', 'localStorage': [{'name': 'BSKY_STORAGE', 'value': json.dumps({'session': {'currentAccount': {'did': account['did']}, 'accounts': [account]}})}]}]})
private_json('dev-null.json', {'cookies': [cookie('dev_null_session', 'fixture-session', '127.0.0.1'), cookie('XSRF-TOKEN', 'fixture-xsrf', '127.0.0.1')], 'origins': []})
token = root / 'storage/test-client-token'
token.write_text('f' * 64)
os.chmod(token, 0o600)
print('Prepared isolated fake sessions in storage/test-sessions.')
