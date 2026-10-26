/** media-refs.ts: the media preview cache of the Fabrement fields box. */
const {test} = require('node:test');
const assert = require('node:assert/strict');
const {createSandbox, settle, plain} = require('./support/harness.cjs');

const row = (id) => ({id, url: 'https://x.test/up/img-' + id + '.jpg', thumb: 'https://x.test/up/img-' + id + '-300.jpg', alt: 'Alt ' + id,
    title: 'Image ' + id, filename: 'img-' + id + '.jpg', mimeType: 'image/jpeg', fileSize: 1000 + id});
const idsOf = (path) => new URL(path, 'https://x.test').searchParams.get('ids').split(',').map(Number);

function setup(apiFetch) {
    // The mock server answers /media-refs with a row for every id below 900.
    const box = createSandbox({apiFetch: apiFetch || ((o) => Promise.resolve(idsOf(o.path).filter((id) => id < 900).map(row)))});
    return {box, media: box.load('media-refs')};
}

test('primed rows are returned from the cache without a request', async () => {
    const {box, media} = setup();
    const row = {id: 7, url: 'u', thumb: 't', alt: 'a', title: 'T', filename: 'f.jpg', mimeType: 'image/jpeg', fileSize: 3};
    media.primeMedia([row]);
    media.primeMedia(null); // tolerated
    assert.deepEqual(plain(media.cachedMedia(7)), row);
    assert.deepEqual(plain(await media.loadMedia(7)), row);
    assert.equal(media.cachedMedia(8), undefined);
    assert.equal(box.requests.length, 0);
});

test('toMediaRef turns each outside shape into a MediaRef, by the source it is told', () => {
    const {media} = setup();
    const lib = media.toMediaRef('library', {id: '9', url: 'https://x.test/a/b/photo.png', alt: 'A', title: 'Photo', mime: 'image/png', filesizeInBytes: 55,
        sizes: {thumbnail: {url: 'https://x.test/thumb.png'}}});
    assert.deepEqual(plain(lib), {id: 9, url: 'https://x.test/a/b/photo.png', thumb: 'https://x.test/thumb.png', alt: 'A',
        title: 'Photo', filename: 'photo.png', mimeType: 'image/png', fileSize: 55});
    assert.equal(media.toMediaRef('library', {id: 10, url: 'u', sizes: {medium: {url: 'm'}, thumbnail: {url: 't'}}}).thumb, 'm', 'medium wins');
    assert.equal(media.toMediaRef('library', {id: 11, url: 'https://x.test/raw.gif'}).thumb, 'https://x.test/raw.gif', 'no sizes: the file itself');
    const file = media.toMediaRef('control', {id: 13, url: 'https://x.test/up/b.pdf', filename: 'b.pdf', fileSize: 2048, mimeType: 'application/pdf'});
    assert.equal(file.mimeType, 'application/pdf');
    assert.equal(file.fileSize, 2048);
    assert.equal(file.partial, true, 'a control value carries no thumbnail or title');
    assert.equal(media.toMediaRef('control', {id: 14, url: 'https://x.test/i.jpg', alt: 'I'}).alt, 'I');
    for (const bad of [null, {}, {id: 0}]) assert.equal(media.toMediaRef('library', bad), null);
    media.rememberMedia(null);
    assert.equal(media.cachedMedia(0), undefined);
});

test('ids asked for in the same moment go out as ONE /media-refs request, by id, and are cached', async () => {
    const {box, media} = setup();
    const [a, b] = await Promise.all([media.loadMedia(3), media.loadMedia(4)]);
    assert.deepEqual(box.requests.map((r) => r.path), ['/fabrement/v1/media-refs?ids=3%2C4']);
    for (const r of box.requests) assert.doesNotMatch(r.path, /include=|wp\/v2\/media/);
    assert.deepEqual(plain(a), row(3));
    assert.equal(b.id, 4);
    await media.loadMedia(3);
    assert.equal(box.requests.length, 1);
});

test('an id already being fetched is not asked again', async () => {
    let release;
    const gate = new Promise((r) => { release = r; });
    const {box, media} = setup(async (o) => { await gate; return idsOf(o.path).map(row); });
    const first = media.loadMedia(5);
    await settle();
    const second = media.loadMedia(5);
    release();
    assert.deepEqual(plain(await first), plain(await second));
    assert.equal(box.requests.length, 1);
});

test('an id the server does not return is missing and known; a failed request is not remembered', async () => {
    const {media} = setup();
    const gone = await media.loadMedia(950);
    assert.equal(gone.missing, true);
    assert.equal(media.cachedMedia(950).missing, true);
    assert.equal(media.needsMedia(950), false, 'absent is known: not asked again');
    let fail = true;
    const flaky = setup((o) => fail ? Promise.reject(new Error('network')) : Promise.resolve(idsOf(o.path).map(row)));
    const first = await flaky.media.loadMedia(12);
    assert.equal(first.missing, true, 'shown as missing for now');
    assert.equal(flaky.media.needsMedia(12), true, 'but still unknown');
    fail = false;
    const second = await flaky.media.loadMedia(12);
    assert.equal(second.id, 12);
    assert.ok(!second.missing);
    assert.equal(flaky.box.requests.length, 2);
});

test('what a file control hands back keeps its type and size, and is fetched in full when shown', async () => {
    const {box, media} = setup();
    // DownloadField: {id, url, filename, fileSize, mimeType, label} — no library sizes.
    media.rememberMedia(media.toMediaRef('control', {id: 21, url: 'https://x.test/up/brochure.pdf', filename: 'brochure.pdf', fileSize: 2048, mimeType: 'application/pdf', label: ''}));
    const row = media.cachedMedia(21);
    assert.equal(row.mimeType, 'application/pdf');
    assert.equal(row.fileSize, 2048);
    assert.equal(row.filename, 'brochure.pdf');
    assert.equal(row.partial, true);
    assert.equal(media.needsMedia(21), true);
    await media.loadMedia(21);
    assert.equal(box.requests.length, 1, 'a partial row is fetched once in full');
    assert.match(box.requests[0].path, /media-refs\?ids=21/);
    assert.ok(!media.cachedMedia(21).partial, 'the fetched row is complete');
    assert.equal(media.needsMedia(21), false);
});

test('a partial item never replaces a complete row', () => {
    const {box, media} = setup();
    const full = {id: 30, url: 'https://x.test/up/big.jpg', thumb: 'https://x.test/up/big-300.jpg', alt: 'Alt', title: 'Big', filename: 'big.jpg', mimeType: 'image/jpeg', fileSize: 999};
    media.primeMedia([full]);
    // ImageField hands back only {id, url, alt}.
    media.rememberMedia(media.toMediaRef('control', {id: 30, url: 'https://x.test/up/big.jpg', alt: 'Alt'}));
    assert.equal(media.cachedMedia(30).thumb, 'https://x.test/up/big-300.jpg', 'the gallery keeps the medium size');
    assert.equal(media.cachedMedia(30).title, 'Big');
    assert.equal(media.needsMedia(30), false);
    assert.equal(box.requests.length, 0);
});
