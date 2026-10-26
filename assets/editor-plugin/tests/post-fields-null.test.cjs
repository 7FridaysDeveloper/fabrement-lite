const {test} = require('node:test');
const assert = require('node:assert/strict');
const {createSandbox, plain} = require('./support/harness.cjs');

// Exercise the fields-box adapters without mounting third-party picker implementations.
const box = createSandbox({stubs: {'wysiwyg-field': {WysiwygField() {}}}});
const output = box.load('post-fields/controls');
const control = (type, value, onChange = () => {}) => output.FieldControl({field: {type, label: type}, value, onChange, path: type});

test('a nullable boolean distinguishes unset, false and true and can clear either choice', () => {
    for (const [value, shown] of [[null, ''], [false, 'false'], [true, 'true']]) {
        const changes = [];
        const node = control('true_false', value, value => changes.push(value));
        assert.equal(node.props.value, shown);
        node.props.onChange('false');
        node.props.onChange('true');
        node.props.onChange('');
        assert.deepEqual(changes, [false, true, null]);
    }
});

test('nullable values are adapted for controls without emitting changes on load', () => {
    let changes = 0;
    for (const [type, expected] of [
        ['text', ''], ['textarea', ''], ['wysiwyg', ''], ['select', ''], ['date_picker', ''],
        ['image', 0], ['file', 0], ['post_object', 0], ['gallery', []], ['relationship', []],
    ]) {
        const node = control(type, null, () => {changes++;});
        assert.deepEqual(plain(node.props.value), expected, type);
    }
    assert.equal(changes, 0, 'display adapters must not save their fallback as a field value');
});

test('editing a sibling keeps explicit null instead of the field default', () => {
    const changes = [];
    const intents = [];
    const values = {amount: null, title: 'Before', enabled: false, count: 0};
    const fields = [
        {key: 'amount', type: 'number', default: 7},
        {key: 'title', type: 'text'},
        {key: 'enabled', type: 'true_false'},
        {key: 'count', type: 'number'},
    ];
    const tree = output.FieldsGrid({fields, values, onChange: (value, intent) => {changes.push(plain(value)); intents.push(plain(intent));}});
    const controls = [];
    const visit = node => {
        if (!node) return;
        if (Array.isArray(node)) return node.forEach(visit);
        if (node.type === output.FieldControl) controls.push(node);
        node.props?.children?.forEach(visit);
    };
    visit(tree);
    assert.equal(controls.find(node => node.props.field.key === 'amount').props.value, null);
    controls.find(node => node.props.field.key === 'title').props.onChange('After');
    assert.deepEqual(changes, [{amount: null, title: 'After', enabled: false, count: 0}]);
    assert.deepEqual(intents, [{title: true}]);
    controls.find(node => node.props.field.key === 'count').props.onChange(0);
    assert.deepEqual(intents[1], {count: true}, 'an explicit assignment equal to the displayed value is still a touch');
});
