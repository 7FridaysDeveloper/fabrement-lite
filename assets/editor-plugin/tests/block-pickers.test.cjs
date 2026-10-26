/**
 * Smoke checks that the record pickers of the fields form use the shared cache the same way:
 * PostObjectField / PostObjectsField / LinkField import usePostRefs and call it with one id list,
 * and the two record pickers render titles, share one /post-refs request and reorder or remove
 * without asking again. Also checks the built bundle carries the shared route.
 */
const {test} = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs'), path = require('node:path');
const {createSandbox, findAll, byClass, text, settle} = require('./support/harness.cjs');

const SRC = path.join(__dirname, '../src');
const read = (file) => fs.readFileSync(path.join(SRC, file), 'utf8');

test('record pickers import usePostRefs from the shared module and call it with one id array', () => {
    const calls = {
        'post-object-field.tsx': /usePostRefs\(id \? \[id\] : \[\]\)/,
        'post-objects-field.tsx': /usePostRefs\(ids\)/,
        'link-field.tsx': /usePostRefs\(shownPostId > 0 \? \[shownPostId\] : \[\]\)/,
    };
    for (const [file, call] of Object.entries(calls)) {
        const source = read(file);
        assert.match(source, /import \{[^}]*\busePostRefs\b[^}]*\} from '\.\/post-picker-shared'/, file);
        assert.match(source, call, file);
        assert.match(source, /const \{ refs(?:: \w+)?(?:, loading)? \} = usePostRefs\(/, file + ' destructures {refs, loading}');
    }
    const shared = read('post-picker-shared.tsx');
    assert.match(shared, /export function usePostRefs\(ids: number\[\]\)/);
    assert.match(shared, /return \{ refs(?:: \w+)?, loading(?::[^}]+)? \};/, 'the hook still returns {refs, loading}');
    assert.match(shared, /export function primePostRefs\(rows: RefRow\[\], lang: string, ids: number\[\] = \[\]\): void/);
});

test('the built bundle contains the shared post-refs route and no longer resolves ids via /wp/v2/search include', {skip: !fs.existsSync(path.join(__dirname, '../build/editor-plugin.js')) && 'build/ is not built (npm run build)'}, () => {
    const bundle = path.join(__dirname, '../build/editor-plugin.js');
    const built = fs.readFileSync(bundle, 'utf8');
    assert.ok(built.includes('/fabrement/v1/post-refs?ids='), 'bundle has the batched refs request');
    assert.ok(!/wp\/v2\/search\?include=/.test(built), 'no per-picker include= lookups');
    // The build must be at least as new as the shared module.
    assert.ok(fs.statSync(bundle).mtimeMs >= fs.statSync(path.join(SRC, 'post-picker-shared.tsx')).mtimeMs, 'run npm run build');
});

function setup() {
    const box = createSandbox({
        editor: {currentLanguage: 'en', languages: [{code: 'en', label: 'English', isCurrent: true}, {code: 'pl', label: 'Polski', isCurrent: false}]},
        apiFetch: (o) => {
            const ids = new URL(o.path, 'https://x.test').searchParams.get('ids').split(',').map(Number);
            return Promise.resolve(ids.filter((id) => id !== 404).map((id) => ({id, title: 'Record ' + id, url: 'https://x.test/r' + id, subtype: 'post', languages: ['en']})));
        },
    });
    const single = box.load('post-object-field');
    const multi = box.load('post-objects-field');
    return {box, single, multi};
}

const titleOf = (tree) => text(findAll(tree, byClass('fabrement-post-object-field__trigger-title'))[0]);
const listTitles = (tree) => findAll(tree, byClass('fabrement-post-objects-field__item-title')).map(text);

test('PostObjectField and PostObjectsField mounted together make ONE request and show titles', async () => {
    const {box, single, multi} = setup();
    const changes = [];
    const one = box.mount(single.PostObjectField, {value: 7, postTypes: ['post'], onChange: (v) => changes.push(v)});
    const many = box.mount(multi.PostObjectsField, {value: [3, 1, 2], max: 3, onChange: (v) => { changes.push(v); many.update({...many.props, value: v}); }});
    assert.equal(titleOf(one.output), '#7…');
    assert.deepEqual(listTitles(many.output), ['#3…', '#1…', '#2…']);
    await settle();
    assert.equal(box.requests.length, 1);
    assert.deepEqual(new URL(box.requests[0].path, 'https://x.test').searchParams.get('ids').split(',').map(Number).sort(), [1, 2, 3, 7]);
    assert.equal(titleOf(one.output), 'Record 7');
    assert.deepEqual(listTitles(many.output), ['Record 3', 'Record 1', 'Record 2']);
    assert.equal(text(findAll(many.output, byClass('fabrement-post-objects-field__add'))[0]), 'Maximum 3 reached');

    // Move "Record 1" up, then remove the first row: both re-render from the cache, no request.
    const buttons = (row) => findAll(findAll(many.output, byClass('fabrement-post-objects-field__item'))[row], (el) => el.type === 'button');
    buttons(1)[0].props.onClick();
    assert.deepEqual(changes.at(-1), [1, 3, 2]);
    assert.deepEqual(listTitles(many.output), ['Record 1', 'Record 3', 'Record 2']);
    buttons(0)[2].props.onClick();
    assert.deepEqual(changes.at(-1), [3, 2]);
    assert.deepEqual(listTitles(many.output), ['Record 3', 'Record 2']);
    await settle();
    assert.equal(box.requests.length, 1);
});

test('a record the route does not return reads as unavailable, not loading', async () => {
    const {box, single, multi} = setup();
    const one = box.mount(single.PostObjectField, {value: 404});
    const many = box.mount(multi.PostObjectsField, {value: [404, 5]});
    await settle();
    assert.equal(titleOf(one.output), 'Record #404 is unavailable');
    assert.deepEqual(listTitles(many.output), ['Record #404 is unavailable', 'Record 5']);
    // A later picker for the same id asks nothing.
    box.mount(single.PostObjectField, {value: 404});
    await settle();
    assert.equal(box.requests.length, 1);
});

test('rows primed by the fields box open the record pickers without any request', async () => {
    const {box, single} = setup();
    box.load('post-picker-shared').primePostRefs([{id: 8, title: 'Primed', url: '', subtype: 'page'}], 'en', [8]);
    const one = box.mount(single.PostObjectField, {value: 8});
    assert.equal(titleOf(one.output), 'Primed');
    await settle();
    assert.equal(box.requests.length, 0);
});
