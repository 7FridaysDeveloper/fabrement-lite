/**
 * The shared picker cache in post-picker-shared.tsx: primePostRefs + usePostRefs, as every
 * picker (PostObjectField / PostObjectsField / LinkField, the search modal, the Fabrement
 * fields box) uses it. Each test gets a fresh VM so the module-level cache starts empty.
 */
const {test} = require('node:test');
const assert = require('node:assert/strict');
const {createSandbox, settle, plain} = require('./support/harness.cjs');

const row = (id, extra = {}) => ({id, title: 'Post ' + id, url: 'https://x.test/p' + id, subtype: 'post', ...extra});

/** A server that answers /post-refs with a row for every id except the ones in `gone`. */
function refsServer({gone = [], fail = () => false} = {}) {
    return (options) => {
        const url = new URL(options.path, 'https://x.test');
        assert.equal(url.pathname, '/fabrement/v1/post-refs');
        const ids = url.searchParams.get('ids').split(',').map(Number);
        if (fail(ids, url.searchParams.get('lang'))) return Promise.reject(new Error('500'));
        return Promise.resolve(ids.filter((id) => !gone.includes(id)).map((id) => row(id, {title: 'Post ' + id + ' ' + url.searchParams.get('lang')})));
    };
}

function setup(options = {}) {
    const box = createSandbox({apiFetch: options.apiFetch || refsServer(options), editor: {currentLanguage: options.lang ?? 'en', languages: []}});
    const shared = box.load('post-picker-shared');
    /** Mount a component that calls usePostRefs(ids) exactly like the record fields do. */
    const use = (ids) => box.mount((props) => shared.usePostRefs(props.ids), {ids});
    const refsPaths = () => box.requests.map((r) => r.path);
    const idsOf = (request) => new URL(request.path, 'https://x.test').searchParams.get('ids').split(',').map(Number);
    return {box, shared, use, refsPaths, idsOf};
}

test('primed rows are served without a request, in the field shape', async () => {
    const {box, shared, use} = setup();
    shared.primePostRefs([row(11, {languages: ['en', 'pl'], onPage: {state: 'same', id: 11, title: 'Post 11'}}), row(12, {title: '', url: '', subtype: ''})], 'en', [11, 12]);
    const hook = use([11, 12]);
    assert.equal(hook.output.loading, false);
    assert.deepEqual(plain(hook.output.refs), {
        11: {id: 11, title: 'Post 11', url: 'https://x.test/p11', postType: 'post', languages: ['en', 'pl'], onPage: {state: 'same', id: 11, title: 'Post 11'}},
        12: {id: 12, title: '(no title)', url: '', postType: 'post', languages: []},
    });
    await settle();
    assert.equal(box.requests.length, 0);
});

test('ids listed in primePostRefs but missing from its rows are absent: never requested, not in refs', async () => {
    const {box, shared, use} = setup();
    shared.primePostRefs([row(1)], 'en', [1, 2, 3]);
    const hook = use([1, 2, 3]);
    assert.equal(hook.output.loading, false);
    assert.deepEqual(Object.keys(hook.output.refs), ['1']);
    await settle();
    assert.equal(box.requests.length, 0);
});

test('primePostRefs without ids only fills the cache (nothing is marked absent)', async () => {
    const {box, shared, use} = setup();
    shared.primePostRefs([row(1)], 'en');
    const hook = use([1, 2]);
    assert.equal(hook.output.loading, true);
    await settle();
    assert.equal(box.requests.length, 1);
    assert.deepEqual(Object.keys(hook.output.refs).sort(), ['1', '2']);
});

test('pickers rendering in the same tick share ONE request; each id is sent once', async () => {
    const {box, use, idsOf} = setup();
    const a = use([1, 2]);
    const b = use([2, 3]);
    const c = use([3]);
    assert.equal(a.output.loading, true);
    assert.equal(box.requests.length, 0, 'the request waits for the tick to collect every picker');
    await settle();
    assert.equal(box.requests.length, 1);
    assert.deepEqual(idsOf(box.requests[0]).sort(), [1, 2, 3]);
    assert.match(box.requests[0].path, /&lang=en$/);
    for (const [hook, ids] of [[a, [1, 2]], [b, [2, 3]], [c, [3]]]) {
        assert.equal(hook.output.loading, false);
        assert.deepEqual(Object.keys(hook.output.refs).map(Number).sort(), ids);
    }
});

test('a later picker that needs only cached ids asks nothing', async () => {
    const {box, use} = setup();
    use([1, 2]);
    await settle();
    const later = use([2, 1]);
    assert.equal(later.output.loading, false);
    assert.equal(later.output.refs[1].title, 'Post 1 en');
    await settle();
    assert.equal(box.requests.length, 1);
});

test('more than 100 ids go out in chunks of 100', async () => {
    const {box, use, idsOf} = setup();
    const ids = Array.from({length: 250}, (_, i) => i + 1);
    const hook = use(ids);
    await settle(6);
    assert.deepEqual(box.requests.map((r) => idsOf(r).length), [100, 100, 50]);
    assert.deepEqual(box.requests.flatMap(idsOf), ids);
    assert.equal(hook.output.loading, false);
    assert.equal(Object.keys(hook.output.refs).length, 250);
});

test('ids the route leaves out become absent and are not asked again', async () => {
    const {box, use} = setup({gone: [2]});
    const first = use([1, 2]);
    await settle();
    assert.equal(first.output.loading, false);
    assert.deepEqual(Object.keys(first.output.refs), ['1']);
    const again = use([2]);
    assert.equal(again.output.loading, false, 'an absent id is known, so there is nothing to wait for');
    await settle();
    assert.equal(box.requests.length, 1);
});

test('a failed request leaves its ids unknown: loading ends, and the next picker asks again', async () => {
    let failing = true;
    const {box, use, idsOf} = setup({fail: () => failing});
    const first = use([1, 2]);
    await settle();
    assert.equal(box.requests.length, 1);
    assert.equal(first.output.loading, false);
    assert.deepEqual(plain(first.output.refs), {});
    failing = false;
    const second = use([1, 2]);
    assert.equal(second.output.loading, true);
    await settle();
    assert.equal(box.requests.length, 2);
    assert.deepEqual(idsOf(box.requests[1]), [1, 2]);
    assert.equal(second.output.refs[2].title, 'Post 2 en');
});

test('a failed chunk does not stop the other chunks', async () => {
    const {box, use} = setup({fail: (ids) => ids[0] === 1});
    const hook = use(Array.from({length: 150}, (_, i) => i + 1));
    await settle(6);
    assert.equal(box.requests.length, 2);
    assert.equal(hook.output.loading, false);
    assert.equal(hook.output.refs[1], undefined);
    assert.equal(hook.output.refs[101].id, 101);
});

test('the cache is per language: a row primed for en is fetched again for pl', async () => {
    const {box, shared, use} = setup({lang: 'pl'});
    shared.primePostRefs([row(5)], 'en', [5, 6]);
    const hook = use([5, 6]);
    assert.equal(hook.output.loading, true);
    await settle();
    assert.equal(box.requests.length, 1);
    assert.match(box.requests[0].path, /ids=5%2C6&lang=pl$/);
    assert.equal(hook.output.refs[5].title, 'Post 5 pl');
    assert.equal(hook.output.refs[6].title, 'Post 6 pl');
});

test('two languages requested in the same tick are two requests, one per language', async () => {
    const {box, use} = setup({lang: 'en'});
    const en = use([1]);
    box.window.fabrementEditor.currentLanguage = 'pl';
    const pl = use([1]);
    await settle();
    assert.deepEqual(box.requests.map((r) => new URL(r.path, 'https://x.test').searchParams.get('lang')).sort(), ['en', 'pl']);
    // The page language is read on every render; both rows are now cached side by side.
    assert.equal(pl.output.refs[1].title, 'Post 1 pl');
    box.window.fabrementEditor.currentLanguage = 'en';
    en.render();
    assert.equal(en.output.refs[1].title, 'Post 1 en');
    await settle();
    assert.equal(box.requests.length, 2);
});

test('a single-language site uses an empty language key', async () => {
    const box = createSandbox({apiFetch: refsServer(), editor: undefined});
    const shared = box.load('post-picker-shared');
    shared.primePostRefs([row(3)], '', [3]);
    const hook = box.mount((p) => shared.usePostRefs(p.ids), {ids: [3]});
    assert.equal(hook.output.loading, false);
    assert.equal(hook.output.refs[3].id, 3);
    await settle();
    assert.equal(box.requests.length, 0);
});

test('reordering and removing ids re-reads the cache without a new request', async () => {
    const {box, use} = setup();
    const hook = use([3, 1, 2]);
    await settle();
    assert.equal(box.requests.length, 1);
    // What the fields render: ids.map(id => refs[id]) in the FIELD's order.
    const shown = () => hook.props.ids.map((id) => hook.output.refs[id]?.id);
    assert.deepEqual(shown(), [3, 1, 2]);
    hook.update({ids: [2, 3, 1]});
    assert.deepEqual(shown(), [2, 3, 1]);
    assert.equal(hook.output.loading, false);
    hook.update({ids: [2, 1]});
    assert.deepEqual(shown(), [2, 1]);
    assert.deepEqual(Object.keys(hook.output.refs).map(Number).sort(), [1, 2], 'a removed id is no longer returned');
    await settle();
    assert.equal(box.requests.length, 1);
});

test('adding an id asks only for the new one', async () => {
    const {box, use, idsOf} = setup();
    const hook = use([1, 2]);
    await settle();
    hook.update({ids: [1, 2, 9]});
    assert.equal(hook.output.loading, true);
    await settle();
    assert.equal(box.requests.length, 2);
    assert.deepEqual(idsOf(box.requests[1]), [9]);
    assert.equal(hook.output.loading, false);
    assert.equal(hook.output.refs[9].id, 9);
});

test('an unmounted picker is not updated when its request completes', async () => {
    let release;
    const gate = new Promise((r) => { release = r; });
    const {use} = setup({apiFetch: async (o) => { await gate; return [row(1)]; }});
    const hook = use([1]);
    const renders = hook.renders;
    await settle();
    hook.unmount();
    release();
    await settle();
    assert.equal(hook.renders, renders);
});

test('an empty id list never asks and is not loading', async () => {
    const {box, use} = setup();
    const hook = use([]);
    assert.equal(hook.output.loading, false);
    assert.deepEqual(plain(hook.output.refs), {});
    await settle();
    assert.equal(box.requests.length, 0);
});

test('rows the server sends again replace the cache: a new title shows, a record now gone stops showing', async () => {
    const {box, shared, use} = setup();
    shared.primePostRefs([row(1), row(2)], 'en', [1, 2]);
    const hook = use([1, 2]);
    assert.equal(hook.output.refs[1].title, 'Post 1');
    // After a save or reload: 1 was renamed, 2 was trashed (looked up, not returned).
    shared.primePostRefs([row(1, {title: 'Renamed'})], 'en', [1, 2]);
    hook.update({ids: [1, 2]});
    assert.equal(hook.output.refs[1].title, 'Renamed');
    assert.equal(hook.output.refs[2], undefined);
    assert.equal(hook.output.loading, false, 'a record the server reported gone is not loading');
    // …and one restored comes back.
    shared.primePostRefs([row(2)], 'en', [2]);
    hook.update({ids: [1, 2]});
    assert.equal(hook.output.refs[2].title, 'Post 2');
    await settle();
    assert.equal(box.requests.length, 0);
});

test('an id already being fetched is not asked again by a picker a moment later', async () => {
    let release;
    const gate = new Promise((r) => { release = r; });
    const server = refsServer();
    const {box, use} = setup({apiFetch: async (o) => { await gate; return server(o); }});
    const first = use([1]);
    await settle();                      // the first batch is sent and waits at the gate
    const second = use([1]);             // a later tick: must wait for the same request
    await settle();
    assert.equal(box.requests.length, 1);
    release();
    await settle();
    assert.equal(first.output.refs[1].id, 1);
    assert.equal(second.output.refs[1].id, 1);
    assert.equal(second.output.loading, false);
});

test('a newly added id reads as loading on its first render, never as unavailable', () => {
    const {use} = setup();
    const hook = use([]);
    hook.update({ids: [5]});
    assert.equal(hook.output.refs[5], undefined);
    assert.equal(hook.output.loading, true);
});
