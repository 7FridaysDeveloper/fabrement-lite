/**
 * GalleryField, rendered with the harness hook runtime: add / replaceAll / move / remove / clear,
 * the max (<= 50) cap, notices, and dragging inside a disabled fieldset.
 */
const {test} = require('node:test');
const assert = require('node:assert/strict');
const {createSandbox, findAll, byClass, text, settle, plain} = require('./support/harness.cjs');

function MediaUpload() {}
function MediaUploadCheck() {}

/** A wp.media stub: attachments by id (type from `types`), and a frame that fires 'update' on demand. */
function mediaStub(types = {}) {
    const frames = [];
    const media = (options) => {
        const handlers = {};
        const frame = {options, on: (name, fn) => { handlers[name] = fn; }, open: () => { frame.opened = true; }, fire: (name, arg) => handlers[name](arg)};
        frames.push(frame);
        return frame;
    };
    media.attachment = (id) => ({id, fetch: () => Promise.resolve(), get: (key) => key === 'type' ? (types[id] || 'image') : undefined, toJSON: () => ({id})});
    media.model = {Selection: function (models, options) { this.models = models; this.options = options; }};
    media.frames = frames;
    return media;
}

function setup({value = [], max, min, media, cached = true, unique} = {}) {
    const box = createSandbox({
        apiFetch: (o) => Promise.resolve(new URL(o.path, 'https://x.test').searchParams.get('ids').split(',').map(Number).map((id) => ({id, url: 'https://x.test/' + id + '.jpg', thumb: 'https://x.test/' + id + '.jpg', alt: '', title: '', filename: '', mimeType: 'image/jpeg', fileSize: 0}))),
        extraWp: {blockEditor: {MediaUpload, MediaUploadCheck}, ...(media ? {media} : {})},
    });
    const refs = box.load('media-refs');
    if (cached && Array.isArray(value)) refs.primeMedia(value.map((id) => ({id, url: 'u' + id, thumb: 't' + id, alt: 'a', title: 'T' + id, filename: '', mimeType: '', fileSize: 0})));
    const {GalleryField} = box.load('gallery-field');
    const changes = [];
    const props = {value, max, min, unique, onChange: (ids) => { changes.push(plain(ids)); field.update({...field.props, value: ids}); }};
    const field = box.mount(GalleryField, props);
    const out = () => field.output;
    const upload = () => findAll(out(), (el) => el.type === MediaUpload)[0];
    const pick = (...ids) => upload().props.onSelect(ids.map((id) => ({id, url: 'https://x.test/' + id + '.jpg'})));
    const items = () => findAll(out(), byClass('fabrement-gallery__item'));
    const shown = () => items().map((li) => Number(li.props['data-gallery-id']));
    const notice = () => text(findAll(out(), (el) => el.props.role === 'status'));
    const button = (label) => findAll(out(), (el) => el.type === 'button' && text(el) === label)[0];
    const root = findAll(out(), byClass('fabrement-gallery'))[0];
    return {box, field, changes, pick, items, shown, notice, button, upload, root, refs};
}

test('the empty gallery offers the media library; picking adds the images in order', () => {
    const g = setup();
    assert.equal(g.upload().props.multiple, 'add');
    assert.deepEqual(plain(g.upload().props.allowedTypes), ['image']);
    g.pick(5, 3, 9);
    assert.deepEqual(g.changes, [[5, 3, 9]]);
    assert.deepEqual(g.shown(), [5, 3, 9]);
    assert.equal(g.notice(), '');
});

test('a gallery skips ids already present and says how many', () => {
    const g = setup({value: [1, 2]});
    g.pick(2, 3, 1, 4);
    assert.deepEqual(g.changes.at(-1), [1, 2, 3, 4]);
    assert.match(g.notice(), /^2 already in the gallery, skipped/);
});

test('a duplicate inside one pick of a NEW image is not reported as already in the gallery', () => {
    const g = setup({value: []});
    g.pick(7, 7);
    assert.deepEqual(g.changes.at(-1), [7]);
    assert.equal(g.notice(), '');
});

test('adding beyond max cuts the rest off and says so', () => {
    const g = setup({value: [1, 2], max: 4});
    g.pick(3, 4, 5, 6);
    assert.deepEqual(g.changes.at(-1), [1, 2, 3, 4]);
    assert.equal(g.notice(), '2 not added: the gallery holds at most 4.');
    const slot = findAll(g.field.output, byClass('fabrement-gallery__slot'))[0];
    const add = findAll(slot, (el) => el.type === MediaUpload)[0].props.render({open() {}});
    assert.equal(add.props.disabled, true, 'a full gallery disables the add slot');
    assert.match(text(findAll(g.field.output, byClass('fabrement-gallery__bar'))), /4 \/ 4/);
});

test('max is capped at 50 (and defaults to 50)', () => {
    const many = Array.from({length: 60}, (_, i) => i + 1);
    for (const max of [undefined, 0, 80]) {
        const g = setup({max});
        g.pick(...many);
        assert.equal(g.changes.at(-1).length, 50, 'max=' + max);
        assert.equal(g.notice(), '10 not added: the gallery holds at most 50.');
    }
});

test('a stored repeat is shown once, as ACF keeps a gallery', () => {
    const g = setup({value: [1, 2, 1], media: {}});
    assert.deepEqual(g.shown(), [1, 2], 'each image once');
    g.pick(2);
    assert.match(g.notice(), /already/);
});

test('skipped and cut-off notices combine', () => {
    const g = setup({value: [1], max: 2});
    g.pick(1, 2, 3);
    assert.deepEqual(g.changes.at(-1), [1, 2]);
    assert.equal(g.notice(), '1 already in the gallery, skipped (an image appears once). 1 not added: the gallery holds at most 2.');
});

test('picked items are remembered in the media cache', () => {
    const g = setup();
    g.pick(33);
    assert.equal(g.refs.cachedMedia(33).url, 'https://x.test/33.jpg');
});

test('invalid values are dropped from what is shown', () => {
    const g = setup({value: [3, 0, -1, 'x', 4]});
    assert.deepEqual(g.shown(), [3, 4]);
    const g2 = setup({value: 'nope'});
    assert.deepEqual(g2.shown(), []);
});

test('arrow buttons move one step; the ends are disabled', () => {
    const g = setup({value: [1, 2, 3]});
    const actions = (i) => findAll(g.items()[i], (el) => el.type === 'button');
    assert.equal(actions(0)[0].props.disabled, true);
    assert.equal(actions(2)[2].props.disabled, true);
    actions(1)[0].props.onClick();
    assert.deepEqual(g.changes.at(-1), [2, 1, 3]);
    actions(1)[2].props.onClick();
    assert.deepEqual(g.changes.at(-1), [2, 3, 1]);
});

test('remove takes out one image', () => {
    const g = setup({value: [1, 2, 3]});
    findAll(g.items()[1], byClass('fabrement-gallery__remove'))[0].props.onClick();
    assert.deepEqual(g.changes.at(-1), [1, 3]);
});

test('clear asks first; cancelling keeps the images', () => {
    const g = setup({value: [1, 2]});
    g.box.window.confirm = () => false;
    g.button('Clear all').props.onClick();
    assert.deepEqual(g.changes, []);
    g.box.window.confirm = () => true;
    g.button('Clear all').props.onClick();
    assert.deepEqual(g.changes, [[]]);
});

function dragEvent() {
    const event = {prevented: false, data: {}, preventDefault() { event.prevented = true; }, dataTransfer: {setData(k, v) { event.data[k] = v; }}};
    return event;
}

test('drag and drop reorders', () => {
    const g = setup({value: [1, 2, 3]});
    g.root.props.ref.current = {closest: () => null};
    const start = dragEvent();
    g.items()[0].props.onDragStart(start);
    assert.equal(start.prevented, false);
    assert.equal(start.data['application/x-fabrement-gallery'], '0');
    assert.equal(start.data['text/plain'], undefined, 'a text field cannot receive the index');
    const over = dragEvent();
    g.items()[2].props.onDragOver(over);
    assert.equal(over.prevented, true);
    assert.match(g.items()[2].props.className, /is-target/);
    g.items()[2].props.onDrop(dragEvent());
    assert.deepEqual(g.changes.at(-1), [2, 3, 1]);
    assert.doesNotMatch(g.items()[0].props.className, /is-dragging/);
});

test('inside a disabled fieldset dragging does not start and a drop changes nothing', () => {
    const g = setup({value: [1, 2, 3]});
    let asked;
    g.root.props.ref.current = {closest: (selector) => { asked = selector; return {}; }};
    const start = dragEvent();
    g.items()[0].props.onDragStart(start);
    assert.equal(asked, 'fieldset:disabled');
    assert.equal(start.prevented, true);
    const over = dragEvent();
    g.items()[2].props.onDragOver(over);
    assert.equal(over.prevented, false, 'no drop target without a drag');
    g.items()[2].props.onDrop(dragEvent());
    assert.deepEqual(g.changes, []);
});

test('min shows as short until reached', () => {
    const g = setup({value: [1], min: 2});
    const bar = () => findAll(g.field.output, byClass('fabrement-gallery__bar'))[0];
    assert.match(text(bar()), /1 \/ 50 · at least 2/);
    assert.ok(findAll(bar(), byClass('is-short')).length > 0);
    g.pick(2);
    assert.doesNotMatch(text(bar()), /at least/);
});

test('uncached ids are loaded in ONE request, then shown', async () => {
    const g = setup({value: [41, 42], cached: false});
    await settle();
    assert.equal(g.box.requests.length, 1);
    assert.match(g.box.requests[0].path, /media-refs\?ids=41%2C42/);
    await settle();
    assert.deepEqual(findAll(g.field.output, (el) => el.type === 'img').map((img) => img.props.src), ['https://x.test/41.jpg', 'https://x.test/42.jpg']);
});

test('"Edit in media library" keeps images it could not open, in place, and says so', async () => {
    const media = mediaStub({3: 'application'});
    const g = setup({value: [1, 2, 3], max: 3, media});
    g.root.props.ref.current = {closest: () => null};
    await g.button('Edit in media library').props.onClick();
    await settle();
    const frame = media.frames[0];
    assert.equal(frame.opened, true);
    assert.equal(frame.options.state, 'gallery-edit');
    assert.deepEqual(plain(frame.options.selection.models.map((m) => m.id)), [1, 2], 'only images are handed to the gallery screen');
    // The user reverses the two images it showed.
    frame.fire('update', {models: [2, 1].map((id) => ({toJSON: () => ({id, url: 'x/' + id + '.jpg'})}))});
    assert.deepEqual(g.changes.at(-1), [2, 1, 3], 'id 3 stays, at its old position');
    assert.match(g.notice(), /^1 could not be opened in the media library and were kept/);
});

test('"Edit in media library" de-duplicates and caps what it returns', async () => {
    const media = mediaStub({});
    const g = setup({value: [1, 2], max: 3, media});
    g.root.props.ref.current = {closest: () => null};
    await g.button('Edit in media library').props.onClick();
    await settle();
    media.frames[0].fire('update', {models: [5, 5, 1, 2, 7].map((id) => ({toJSON: () => ({id, url: 'x/' + id + '.jpg'})}))});
    assert.deepEqual(g.changes.at(-1), [5, 1, 2]);
    assert.match(g.notice(), /1 not added: the gallery holds at most 3/);
});

test('"Edit in media library" does nothing inside a disabled fieldset', async () => {
    const media = mediaStub();
    const g = setup({value: [1, 2], media});
    g.root.props.ref.current = {closest: () => ({})};
    await g.button('Edit in media library').props.onClick();
    await settle();
    assert.equal(media.frames.length, 0);
});

test('galleryIds / addPicked / mergeEdited: the list rules in one place', () => {
    const box = createSandbox({extraWp: {blockEditor: {MediaUpload, MediaUploadCheck}}});
    const g = box.load('gallery-field');
    assert.deepEqual(plain(g.galleryIds([5, '5', 3, 0, -1, 'x', 3.5, 7])), [5, 3, 7], 'numbers, once each, positive whole ids only');
    assert.deepEqual(plain(g.galleryIds(null)), []);
    assert.deepEqual(plain(g.addPicked([1, 2], [2, 3, 3, 4], 3)), {ids: [1, 2, 3], already: 1, repeated: 1, cut: 1});
    assert.deepEqual(plain(g.mergeEdited([1, 2, 3, 4], [4, 1], [2], 10)), {ids: [4, 2, 1], kept: 1, cut: 0}, 'an unopened id returns to its old place');
    assert.deepEqual(plain(g.mergeEdited([9], [], [9], 10)), {ids: [9], kept: 1, cut: 0}, 'nothing opened: nothing lost');
    assert.deepEqual(plain(g.mergeEdited([1], [1, 2, 3], [], 2)), {ids: [1, 2], kept: 0, cut: 1});
});
