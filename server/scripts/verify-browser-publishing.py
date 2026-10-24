#!/usr/bin/env python3
"""Verify the real PHP -> background browser flow against the local website mock."""
import json
import os
import time
from pathlib import Path
import urllib.request
import uuid

root = Path(__file__).resolve().parents[1]
base = os.environ.get('POSTSLOP_TEST_URL', 'http://127.0.0.1:8091')
upstream = os.environ.get('POSTSLOP_SIMULATION_URL', 'http://127.0.0.1:4902')
headers = {'Authorization': 'Bearer ' + os.environ.get('POSTSLOP_TOKEN', 'test-token'), 'Content-Type': 'application/json'}

def call(path, data=None):
    request = urllib.request.Request(base + path, data=None if data is None else json.dumps(data).encode(), headers=headers)
    with urllib.request.urlopen(request, timeout=110) as response:
        return json.load(response)

def posts():
    with urllib.request.urlopen(upstream + '/x/__stats') as response:
        return json.load(response)['posts']

status = call('/api/v1/accounts')
account = next(item for item in status['platforms'] if item['id'] == 'x')
assert account['available'] and account['handle'] == 'tester', 'Refusing to publish to non-fixture account'
request = {'requestId': str(uuid.uuid4()), 'text': 'Postslop background browser integration fixture', 'platforms': ['x'], 'images': [{
    'filename': 'fixture.png', 'mimeType': 'image/png', 'alt': 'A tiny generated fixture image',
    'data': 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Wl2nWQAAAAASUVORK5CYII='
}]}
before = len(posts())
result = call('/api/v1/posts', request)
assert result['status'] == 'publishing', result
deadline = time.monotonic() + 120
while result['status'] in ('queued', 'publishing'):
    assert time.monotonic() < deadline, ('Queue workers did not finish the post', result)
    time.sleep(0.1)
    result = call('/api/v1/posts/' + result['id'])
assert result['status'] == 'published', result
created = posts()
assert len(created) == before + 1, 'The browser submitted more than once'
assert created[-1]['text'] == request['text'] and len(created[-1]['images']) == 1
assert created[-1]['images'][0]['alt'] == request['images'][0]['alt']
assert created[-1]['images'][0]['bytes'] > 0
assert call('/api/v1/posts', request) == result and len(posts()) == before + 1
assert call('/api/v1/posts/' + request['requestId']) == result
print('PASS: real Crab server -> Node process -> headless website UI -> image/alt upload and confirmed result; repeated request made no new post.')
print('This is local simulated evidence, not a live X post.')
