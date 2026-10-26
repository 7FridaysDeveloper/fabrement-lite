const {test} = require('node:test');
const assert = require('node:assert/strict');
const {createSandbox, findAll, settle, plain} = require('./support/harness.cjs');

const state = (values = {title: 'Before', count: null}) => ({
    owner: 'options:site', values, exists: {},
    fields: [{key: 'title', type: 'text'}, {key: 'count', type: 'number'}],
    layout: {tabs: []},
});
const deferred = () => {
    let resolve, reject;
    const promise = new Promise((yes, no) => {resolve = yes; reject = no;});
    return {promise, resolve, reject};
};

function setup({initial = state(), options = {slug: 'site', lang: 'pl'}, apiFetch} = {}) {
    const filters = new Map(), listeners = new Map();
    // What FieldsAdmin::print_state hands the page: the post's state, or the options page's.
    const printed = {};
    if (initial) printed[options ? 'options-' + options.slug : '42'] = initial;
    const box = createSandbox({apiFetch,
        stubs: {'decode-entities': {decodeEntities: value => value}},
        extraWp: {hooks: {
            addFilter: (name, namespace, callback) => filters.set(namespace, callback),
            removeFilter: (name, namespace) => filters.delete(namespace),
        }},
        window: {
            addEventListener: (name, callback) => listeners.set(name, callback),
            removeEventListener: name => listeners.delete(name),
            fabrementFieldsState: printed,
        },
    });
    const {useFieldsForm} = box.load('post-fields/use-fields-form');
    const form = box.mount(() => useFieldsForm(42, options || undefined));
    return {box, form, filters, listeners};
}

test('printed state opens without a fetch; options share one pending save and only send touched fields', async () => {
    const gate = deferred();
    const {box, form, filters, listeners} = setup({apiFetch: () => gate.promise});
    await settle();
    assert.equal(box.requests.length, 0);
    assert.equal(filters.size, 0, 'options have their own button, no post-save hook');
    form.output.change({...form.output.values, title: 'After'}, {title: true});
    assert.equal(listeners.has('beforeunload'), true);
    const first = form.output.save(), second = form.output.save();
    assert.equal(box.requests.length, 1);
    assert.equal(box.requests[0].path, '/fabrement/v1/fields/options/site?lang=pl');
    assert.deepEqual(plain(box.requests[0].data), {values: {title: 'After'}, intentVersion: 1, intent: {title: true}});
    gate.resolve(state({title: 'After', count: null}));
    await Promise.all([first, second]);
    assert.equal(form.output.dirty, false);
    assert.equal(form.output.saved, true);
    assert.equal(listeners.has('beforeunload'), false);
    form.unmount();
});

test('post save flushes held drafts in order and releases its registration on unmount', async () => {
    const {box, form: post, filters} = setup({options: null, apiFetch: o => Promise.resolve(state(o.data.values))});
    await settle();
    const release = post.output.draftApi.hold(() => post.output.change({...post.output.values, title: 'Draft'}, {title: true}));
    post.output.draftApi.hold(() => post.output.change({...post.output.values, count: 5000}, {count: true}));
    post.output.draftApi.touch();
    const savePost = filters.get('fabrement/post-fields-42');
    const edits = {id: 42, title: 'Post title'};
    assert.equal(await savePost(edits, {isAutosave: true}), edits);
    assert.equal(box.requests.length, 0);
    assert.equal(await savePost(edits), edits);
    assert.deepEqual(plain(box.requests[0].data.values), {title: 'Draft', count: 5000});
    release();
    post.unmount();
    assert.equal(filters.size, 0);
});

test('a failed save retains intent and an edit arriving during a retry is never discarded', async () => {
    let gate = deferred();
    const {box, form} = setup({apiFetch: () => gate.promise});
    await settle();
    form.output.change({...form.output.values, title: 'First'}, {title: true});
    const failed = form.output.save();
    gate.reject(new Error('Required field'));
    await assert.rejects(failed, /Required field/);
    assert.equal(form.output.dirty, true);
    gate = deferred();
    const retry = form.output.save();
    assert.deepEqual(plain(box.requests[1].data), plain(box.requests[0].data));
    form.output.change({...form.output.values, title: 'Newer'}, {title: true});
    gate.resolve(state({title: 'First', count: null}));
    await assert.rejects(retry, /Fields changed while saving/);
    assert.equal(form.output.values.title, 'Newer');
    assert.equal(form.output.dirty, true);
    assert.equal(form.output.saved, false);
    form.unmount();
});

test('cancelled reload keeps edits; confirmed reload fetches and clears intent', async () => {
    const {box, form} = setup({apiFetch: () => Promise.resolve(state())});
    await settle();
    form.output.change({...form.output.values, title: 'Draft'}, {title: true});
    box.window.confirm = () => false;
    form.output.reload();
    assert.equal(form.output.values.title, 'Draft');
    assert.equal(box.requests.length, 0);
    box.window.confirm = () => true;
    form.output.reload();
    await settle();
    assert.equal(box.requests.length, 1);
    assert.equal(form.output.values.title, 'Before');
    assert.equal(form.output.dirty, false);
    form.unmount();
});

test('layout keyboard navigation keeps every panel mounted, supports RTL and retains unplaced fields', () => {
    const box = createSandbox({stubs: {'post-fields/controls': {FieldsGrid() {}}}});
    const {FieldsLayout} = box.load('post-fields/layout');
    const model = state();
    model.layout.tabs = [{label: 'Text', items: [{field: 'title'}]}, {label: 'Number', items: [{field: 'count'}]}];
    model.fields.push({key: 'extra', type: 'text'});
    const layout = box.mount(FieldsLayout, {id: 'options-site', state: model, values: model.values, onChange() {}});
    const tabs = () => findAll(layout.output, el => el.props.role === 'tab');
    const panels = () => findAll(layout.output, el => el.props.role === 'tabpanel');
    const ids = panels().map(el => el.props.id);
    let focus = -1, prevented = false;
    findAll(layout.output, el => el.props.role === 'tablist')[0].props.ref.current = {querySelectorAll: () => tabs().map((_, i) => ({focus() {focus = i;}}))};
    box.sandbox.getComputedStyle = () => ({direction: 'rtl'});
    tabs()[0].props.onKeyDown({key: 'ArrowLeft', preventDefault() {prevented = true;}});
    assert.equal(prevented, true);
    assert.equal(focus, 1);
    assert.deepEqual(panels().map(el => el.props.id), ids);
    assert.deepEqual(panels().map(el => el.props.hidden), [true, false]);
    tabs()[1].props.onKeyDown({key: 'Home', preventDefault() {}});
    assert.equal(tabs()[0].props['aria-selected'], true);
    assert.equal(findAll(layout.output, el => el.props.auto)[0].props.fields[0].key, 'extra');
    layout.unmount();
});
