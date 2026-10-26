const {test} = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs'), path = require('node:path'), vm = require('node:vm');
const ts = require('typescript');

const exportsObject = {};
vm.runInNewContext(ts.transpileModule(fs.readFileSync(path.join(__dirname, '../src/post-fields-save.ts'), 'utf8'), {
    compilerOptions: {module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2020},
}).outputText, {exports: exportsObject});
const {registerPostFieldsSave, fieldsRoute} = exportsObject;

test('actual touches survive equal defaults; removed rows drop only their pending child edits', () => {
    const {mergeFieldIntent, fieldWritePayload} = exportsObject;
    const fields = [{key: 'group', type: 'group', sub_fields: [{key: 'amount', type: 'number'}, {key: 'title', type: 'text'}]},
        {key: 'rows', type: 'repeater', sub_fields: [{key: 'title', type: 'text'}]}];
    let intent = mergeFieldIntent({}, {group: {amount: true}, rows: {a: {title: true}}});
    intent = mergeFieldIntent(intent, {group: {title: true}, rows: {b: true}});
    const data = fieldWritePayload(fields, {group: {amount: 7, title: ''}, rows: [{id: 'b', values: {title: 'New'}}]}, intent);
    assert.deepEqual(JSON.parse(JSON.stringify(data)), {intentVersion: 1,
        values: {group: {amount: 7, title: ''}, rows: [{id: 'b', values: {title: 'New'}}]},
        intent: {group: {amount: true, title: true}, rows: {b: true}}});
});

test('moving existing rows submits their order without selecting their displayed defaults', () => {
    const {fieldWritePayload} = exportsObject;
    const rows = [{id: 'b', values: {count: 7}}, {id: 'a', values: {count: 7}}];
    const data = fieldWritePayload([{key: 'rows', type: 'repeater', sub_fields: [{key: 'count', type: 'number'}]}], {rows}, {rows: {}});
    assert.deepEqual(JSON.parse(JSON.stringify(data.intent)), {rows: {}});
    assert.deepEqual(JSON.parse(JSON.stringify(data.values)), {rows});
});

function setup(save, dirty = () => true) {
    let filter, removed;
    const hooks = {
        addFilter(name, namespace, callback) { assert.equal(name, 'editor.preSavePost'); filter = callback; },
        removeFilter(name, namespace) { removed = {name, namespace}; },
    };
    const dispose = registerPostFieldsSave(hooks, 42, dirty, save);
    return {filter, dispose, removed: () => removed};
}

test('an explicit save with field changes waits for the fields and keeps the post edits', async () => {
    let finish, completed = false;
    const gate = new Promise(resolve => finish = resolve);
    const {filter} = setup(async () => { await gate; completed = true; });
    const edits = {id: 42, title: 'Post', status: 'publish'};
    let resolved = false;
    const pending = filter(edits, {}).then(result => { resolved = true; assert.equal(result, edits); });
    await Promise.resolve();
    assert.equal(resolved, false);
    finish();
    await pending;
    assert.equal(completed, true);
});

test('without field changes the post saves without touching fields, even if fields are broken', async () => {
    let writes = 0;
    const {filter} = setup(async () => { writes++; throw new Error('fields not loaded'); }, () => false);
    const edits = {id: 42, title: 'Only the title changed'};
    assert.equal(await filter(edits, {}), edits);
    assert.equal(writes, 0);
});

test('autosave and preview never save field drafts', async () => {
    let writes = 0;
    const {filter} = setup(async () => { writes++; });
    for (const options of [{isAutosave: true}, {isPreview: true}]) {
        const edits = {id: 42};
        assert.equal(await filter(edits, options), edits);
    }
    assert.equal(writes, 0);
});

test('a failed field save stops the post save so WordPress cannot report success', async () => {
    const failure = new Error('Client is required');
    const {filter} = setup(async () => { throw failure; });
    await assert.rejects(() => filter({id: 42}, {}), error => error === failure);
});

test('only this post is affected, and unmount removes only its filter', async () => {
    let writes = 0;
    const {filter, dispose, removed} = setup(async () => { writes++; });
    await filter({id: 7}, {});
    await filter({id: '42'});
    assert.equal(writes, 1);
    dispose();
    assert.deepEqual(removed(), {name: 'editor.preSavePost', namespace: 'fabrement/post-fields-42'});
});

test('only touched controls are sent, preserving null, false, zero and atomic values', () => {
    const {fieldWritePayload} = exportsObject;
    const fields = ['untouched', 'n', 'flag', 'zero', 'link', 'rel'].map(key => ({key, type: key === 'link' ? 'link' : 'text'}));
    const values = {untouched: 'Default', n: null, flag: false, zero: 0, link: {url: '', postId: 0}, rel: [6, 5]};
    const data = fieldWritePayload(fields, values, {n: true, flag: true, zero: true, link: true, rel: true});
    assert.deepEqual(JSON.parse(JSON.stringify(data.values)), {n: null, flag: false, zero: 0, link: {url: '', postId: 0}, rel: [6, 5]});
    assert.deepEqual(JSON.parse(JSON.stringify(fieldWritePayload(fields, values, {}).values)), {});
});

test('the form talks to a record route, or to an options page in one language', () => {
    assert.equal(fieldsRoute(42, undefined, ''), '/fabrement/v1/fields/post/42');
    assert.equal(fieldsRoute(42, undefined, 'pl'), '/fabrement/v1/fields/post/42?lang=pl');
    assert.equal(fieldsRoute(0, {slug: 'site', lang: ''}, 'pl'), '/fabrement/v1/fields/options/site');
    assert.equal(fieldsRoute(0, {slug: 'site', lang: 'pt-br'}, 'en'), '/fabrement/v1/fields/options/site?lang=pt-br');
});
