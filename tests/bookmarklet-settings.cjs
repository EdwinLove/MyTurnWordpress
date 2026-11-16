// Run with node tests/bookmarklet-settings.cjs. No npm dependencies required.
const {execFileSync} = require('node:child_process');
const path = require('node:path');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const fixtures = JSON.parse(execFileSync('php', [path.join(__dirname, 'bookmarklet-fixtures.php')], {encoding:'utf8'}));
const html = fixtures.settingsHtml;
const script = html.match(/<script>([\s\S]*?)<\/script>/)[1];
let count = 0;
(async () => {
    for (const clipboard of ['ok', 'denied', 'missing']) {
        const elements = {};
        const buttons = [], links = [], copied = [];
        for (const type of ['thing', 'search']) {
            assert.match(html, new RegExp('<button type="button"[^>]*myturn-copy-bookmarklet[^>]*data-bookmarklet="'+type+'"'));
            const field = {
                value: html.match(new RegExp('<textarea id="myturn-'+type+'-bookmarklet"[^>]*>([^<]*)</textarea>'))[1],
                focus() { this.focused = true; }, select() { this.selected = true; },
            };
            assert.equal(field.value, fixtures.multiple[type]);
            elements['myturn-'+type+'-bookmarklet'] = field;
            elements['myturn-'+type+'-bookmarklet-status'] = {};
            for (const list of [buttons, links]) list.push({dataset:{bookmarklet:type}, addEventListener(event, handler) { this[event] = handler; }});
        }
        vm.runInNewContext(script, {
            document: {
                getElementById: id => elements[id],
                querySelectorAll: selector => selector === '.myturn-copy-bookmarklet' ? buttons : links,
            },
            navigator: clipboard === 'missing' ? {} : {clipboard:{writeText:async value=>{
                if (clipboard === 'denied') throw Error('Denied');
                copied.push(value);
            }}},
        });
        for (const button of buttons) {
            const type = button.dataset.bookmarklet;
            await button.click();
            const field = elements['myturn-'+type+'-bookmarklet'];
            const status = elements['myturn-'+type+'-bookmarklet-status'];
            if (clipboard === 'ok') {
                assert.equal(copied.at(-1), field.value);
                assert.match(status.textContent, /Bookmarklet copied/);
            } else {
                assert.ok(field.focused && field.selected);
                assert.match(status.textContent, /Copy the selected URL manually/);
            }
            count++;
        }
        for (const link of links) {
            let prevented = false;
            link.click({preventDefault() { prevented = true; }});
            assert.ok(prevented, 'Clicking a drag link must never execute its JavaScript URL');
            assert.match(elements['myturn-'+link.dataset.bookmarklet+'-bookmarklet-status'].textContent, /Drag this link/);
            count++;
        }
    }
    console.log(`Passed ${count} settings-page copy and drag-link checks.`);
})().catch(error=>{console.error(error);process.exitCode=1;});
