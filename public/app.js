"use strict";

const text = document.querySelector('#post-text');
const destinations = [...document.querySelectorAll('input[name="destination"]')];
const status = document.querySelector('#draft-status');
const publishButton = document.querySelector('#publish');
const clearButton = document.querySelector('#clear-draft');
const token = document.querySelector('meta[name="postslop-token"]').content;
const imageInput = document.querySelector('#image-files');
const attachments = document.querySelector('#attachments');
const results = document.querySelector('#publish-results');
let images = [];
let requestId = crypto.randomUUID();
let accounts = [];
let sending = false;
let processing = 0;
let completed = false;
let lastResult = null;
let restoring = true;
let saveChain = Promise.resolve();
const database = new Promise((resolve, reject) => {
    const request = indexedDB.open('postslop', 1);
    request.onupgradeneeded = () => request.result.createObjectStore('drafts');
    request.onsuccess = () => resolve(request.result);
    request.onerror = () => reject(new Error('Local draft storage is unavailable.'));
});
database.catch(() => {});

function showStatus(message, error = false) {
    status.textContent = message;
    status.dataset.error = String(error);
}

function selected() {
    return destinations.filter(item => item.checked).map(item => item.value);
}

function updateComposer() {
    const count = Array.from(text.value).length;
    document.querySelector('#character-count').textContent = `${count} characters`;
    document.querySelector('#destination-count').textContent = `${selected().length} destinations`;
    const ready = selected().length > 0 && selected().every(id => accounts.some(account => account.id === id && account.available));
    publishButton.disabled = sending || processing > 0 || completed || !ready || (!text.value.trim() && images.length === 0);
    publishButton.textContent = sending ? 'Working…' : completed ? 'Published' : lastResult?.results.some(item => item.status === 'unknown') ? 'Check result' : lastResult?.results.some(item => item.status === 'failed') ? 'Retry failed' : 'Publish ↗';
    clearButton.disabled = sending || processing > 0 || (!text.value && images.length === 0 && !completed);
    clearButton.textContent = completed ? 'New post' : 'Clear draft';
    text.disabled = sending;
    imageInput.disabled = sending;
    document.querySelector('#add-images').disabled = sending || processing > 0;
    for (const destination of destinations) destination.disabled = sending;
}

function writeDraft(value) {
    saveChain = saveChain.catch(() => {}).then(async () => {
        const db = await database;
        await new Promise((resolve, reject) => {
            const transaction = db.transaction('drafts', 'readwrite');
            if (value) transaction.objectStore('drafts').put(value, 'current');
            else transaction.objectStore('drafts').delete('current');
            transaction.oncomplete = resolve;
            transaction.onerror = () => reject(new Error('Your draft could not be saved.'));
        });
    });
    return saveChain;
}

function saveDraft() {
    if (restoring) return;
    return writeDraft({text: text.value, platforms: selected(), images, requestId, completed, result: lastResult}).then(
        () => { if (!sending) showStatus('Draft saved on this Mac.'); },
        () => showStatus('Draft storage is unavailable. Keep this window open.', true),
    );
}

function edited() {
    requestId = crypto.randomUUID();
    completed = false;
    lastResult = null;
    results.replaceChildren();
    updateComposer();
    saveDraft();
}

function resetComposer() {
    text.value = '';
    images = [];
    imageInput.value = '';
    completed = false;
    lastResult = null;
    requestId = crypto.randomUUID();
    results.replaceChildren();
    renderImages();
    updateComposer();
}

function renderImages() {
    attachments.replaceChildren();
    images.forEach((image, index) => {
        const figure = document.createElement('figure');
        const preview = document.createElement('img');
        preview.src = `data:${image.mimeType};base64,${image.data}`;
        preview.alt = image.alt || `Attachment ${index + 1}`;
        const remove = document.createElement('button');
        remove.type = 'button';
        remove.textContent = '×';
        remove.setAttribute('aria-label', `Remove image ${index + 1}`);
        remove.disabled = sending;
        remove.onclick = () => { images.splice(index, 1); renderImages(); edited(); };
        const alt = document.createElement('input');
        alt.placeholder = 'Describe image (optional)';
        alt.setAttribute('aria-label', `Description for image ${index + 1}`);
        alt.maxLength = 1000;
        alt.value = image.alt;
        alt.disabled = sending;
        alt.oninput = () => { image.alt = alt.value; edited(); };
        figure.append(preview, remove, alt);
        attachments.append(figure);
    });
}

async function normalizeImage(file) {
    if (!['image/png', 'image/jpeg', 'image/webp'].includes(file.type)) throw new Error('Choose a PNG, JPEG, or WebP image.');
    if (file.size > 30 * 1024 * 1024) throw new Error('Choose an image smaller than 30 MB.');
    const bitmap = await createImageBitmap(file);
    try {
        const scale = Math.min(1, 2000 / Math.max(bitmap.width, bitmap.height));
        const canvas = document.createElement('canvas');
        canvas.width = Math.max(1, Math.round(bitmap.width * scale));
        canvas.height = Math.max(1, Math.round(bitmap.height * scale));
        const context = canvas.getContext('2d');
        context.fillStyle = '#ffffff';
        context.fillRect(0, 0, canvas.width, canvas.height);
        context.drawImage(bitmap, 0, 0, canvas.width, canvas.height);
        let data;
        for (let quality = 0.9; quality >= 0.3; quality -= 0.1) {
            data = canvas.toDataURL('image/jpeg', quality).split(',')[1];
            if (data.length <= 1398100) return {filename: 'image.jpg', mimeType: 'image/jpeg', data, alt: ''};
        }
        throw new Error('This image is too detailed. Resize it before attaching.');
    } finally {
        bitmap.close();
    }
}

async function addImages(files) {
    if (sending || processing > 0) return;
    const limit = selected().includes('dev-null') ? 3 : 4;
    if (images.length + files.length > limit) {
        showStatus(`Your selected destinations allow ${limit} images.`, true);
        return;
    }
    processing++;
    updateComposer();
    showStatus('Preparing images…');
    try {
        for (const file of files) images.push(await normalizeImage(file));
        renderImages();
        edited();
    } catch (error) {
        renderImages();
        if (images.length) edited();
        showStatus(error.message || 'Could not read the image.', true);
    } finally {
        processing--;
        updateComposer();
    }
}

async function api(path, options = {}) {
    const response = await fetch(path, {...options, headers: {Authorization: `Bearer ${token}`, Accept: 'application/json', ...(options.headers || {})}});
    const data = await response.json();
    if (!response.ok) throw new Error(data.error?.message || `Request failed (${response.status}).`);
    return data;
}

async function refreshAccounts() {
    const connection = document.querySelector('#connection-status');
    connection.textContent = 'Checking signed-in accounts…';
    try {
        const data = await api('/api/status');
        accounts = data.platforms;
        for (const account of accounts) {
            const label = document.querySelector(`#account-${account.id}`);
            label.textContent = account.available ? `@${account.handle}` : account.handle ? `@${account.handle} · unavailable` : 'Session unavailable';
            label.closest('label').title = account.reason || 'Signed-in account verified';
        }
        const unavailable = accounts.filter(account => !account.available);
        connection.textContent = unavailable.length ? unavailable.map(account => `${account.label}: ${account.reason}`).join(' ') : 'Your signed-in accounts are ready.';
    } catch (error) {
        connection.textContent = error.message;
        for (const destination of destinations) document.querySelector(`#account-${destination.value}`).textContent = 'Could not check session';
    }
    updateComposer();
}

function showResults(data) {
    results.replaceChildren();
    for (const result of data.results) {
        const row = document.createElement('p');
        row.dataset.state = result.status;
        row.textContent = `${result.platform}: ${result.status === 'published' ? 'Published' : result.error || result.status}`;
        if (result.url) {
            try {
                const url = new URL(result.url);
                if (url.protocol === 'https:' && ['x.com', 'bsky.app', 'dev-null.sh'].includes(url.hostname)) {
                    for (const key of [...url.searchParams.keys()]) if (key.startsWith('utm_')) url.searchParams.delete(key);
                    const link = document.createElement('a');
                    link.href = url.href;
                    link.target = '_blank';
                    link.rel = 'noopener noreferrer';
                    link.textContent = 'View post ↗';
                    row.append(' ', link);
                }
            } catch { /* A malformed result URL must not become a link. */ }
        }
        results.append(row);
    }
}

async function publish() {
    if (publishButton.disabled) return;
    let platforms = selected();
    const checking = lastResult?.results.some(item => item.status === 'unknown');
    if (lastResult && !checking) {
        platforms = lastResult.results.filter(item => item.status === 'failed').map(item => item.platform);
        for (const item of destinations) item.checked = platforms.includes(item.value);
        requestId = crypto.randomUUID();
        lastResult = null;
    }
    if (platforms.includes('dev-null') && images.length > 3) { showStatus('dev-null.sh accepts up to 3 images.', true); return; }
    sending = true;
    updateComposer();
    renderImages();
    results.replaceChildren();
    showStatus('Publishing to your selected accounts…');
    await saveDraft();
    try {
        const data = checking ? await api(`/api/posts/${requestId}`) : await api('/api/posts', {method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify({requestId, text: text.value, platforms, images})});
        lastResult = data;
        showResults(data);
        completed = data.status === 'published';
        if (completed) {
            await writeDraft(null);
            resetComposer();
            showStatus('Published. Ready for your next post.');
        } else {
            await saveDraft();
            showStatus('Draft kept. Check the results before creating another post.', true);
        }
    } catch (error) {
        showStatus(`${error.message} Draft kept; retrying this draft uses the same request ID.`, true);
    } finally {
        sending = false;
        renderImages();
        updateComposer();
    }
}

text.addEventListener('input', edited);
for (const destination of destinations) destination.addEventListener('change', edited);
document.querySelector('#add-images').onclick = () => imageInput.click();
imageInput.onchange = () => { addImages([...imageInput.files]); imageInput.value = ''; };
document.addEventListener('paste', event => {
    const files = [...(event.clipboardData?.items || [])].filter(item => item.kind === 'file' && item.type.startsWith('image/')).map(item => item.getAsFile()).filter(Boolean);
    if (files.length) { event.preventDefault(); addImages(files); }
});
document.addEventListener('keydown', event => {
    if (event.key === 'Enter' && (event.metaKey || event.ctrlKey)) { event.preventDefault(); publish(); }
});
publishButton.onclick = publish;
document.querySelector('#refresh-accounts').onclick = refreshAccounts;
clearButton.onclick = async () => {
    resetComposer();
    try { await writeDraft(null); showStatus('Ready for your next post.'); }
    catch { showStatus('Could not remove the saved draft.', true); }
    text.focus();
};

(async () => {
    try {
        const db = await database;
        const draft = await new Promise((resolve, reject) => {
            const request = db.transaction('drafts').objectStore('drafts').get('current');
            request.onsuccess = () => resolve(request.result);
            request.onerror = reject;
        });
        if (draft && typeof draft.text === 'string') {
            text.value = draft.text;
            images = Array.isArray(draft.images) ? draft.images : [];
            requestId = draft.requestId || crypto.randomUUID();
            completed = Boolean(draft.completed);
            lastResult = draft.result || null;
            if (lastResult) showResults(lastResult);
            for (const item of destinations) item.checked = draft.platforms?.includes(item.value) || false;
            showStatus('Draft restored, including images.');
        } else {
            for (const item of destinations) item.checked = true;
        }
    } catch {
        showStatus('Draft storage is unavailable. Keep this window open.', true);
    } finally {
        restoring = false;
        renderImages();
        updateComposer();
        refreshAccounts();
    }
})();
