const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

let block;
const competitions = [
    { uuid: 'private-id', name: 'Private competition', public: false, start_date: '2026-01-01' },
    { uuid: 'public-id', name: 'Public competition', public: true, start_date: '' }
];
const selectControl = function SelectControl() {};
const wp = {
    blocks: { registerBlockType: (name, settings) => { block = settings; } },
    blockEditor: { useBlockProps: () => ({}) },
    components: { SelectControl: selectControl },
    element: {
        createElement: (type, props, ...children) => ({ type, props, children }),
        useEffect: () => {},
        useState: () => [{ loading: false, competitions, error: false }, () => {}]
    }
};
const labels = {
    title: 'Results', competition: 'Competition', select: 'Select',
    private: 'private', scope: 'Scope', combined: 'Combined', stage: 'Stage'
};
vm.runInNewContext(fs.readFileSync(require('node:path').join(__dirname, '../assets/editor.js'), 'utf8'), {
    window: { wp, roxxcompResultsLabels: labels }
});
const tree = block.edit({ attributes: { competitionId: '', scope: 'combined' }, setAttributes: () => {} });
const competitionSelect = tree.children.find(child => child && child.type === selectControl);
assert.equal(competitionSelect.props.options[1].label, 'Private competition (private) (2026-01-01)');
assert.equal(competitionSelect.props.options[2].label, 'Public competition');
console.log('Editor visibility checks passed.');
