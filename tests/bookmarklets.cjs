// Run with node tests/bookmarklets.cjs. No npm dependencies required.
const { execFileSync } = require('node:child_process');
const path = require('node:path');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const fixtures = JSON.parse(execFileSync('php', [path.join(__dirname, 'bookmarklet-fixtures.php')], { encoding: 'utf8' }));
let checks = 0;
async function run(bookmarklet, href, { choice = null, clipboard = 'ok' } = {}) {
    assert.ok(bookmarklet.startsWith('javascript:'));
    const events = [];
    const context = {
        URL,
        window: {
            location: new URL(href),
            alert: message => events.push(['alert', message]),
            prompt: (message, url) => {
                events.push(url === undefined ? ['choose', message] : ['manual', url]);
                return choice;
            },
        },
        navigator: clipboard === 'missing' ? {} : {
            clipboard: { writeText: async url => {
                if (clipboard === 'denied') throw Error('denied');
                events.push(['copy', url]);
            } },
        },
    };
    await vm.runInNewContext(decodeURIComponent(bookmarklet.slice('javascript:'.length)), context);
    checks++;
    return events;
}
const source = 'https://library.myturn.com/library/inventory/browse/';
const target = 'https://example.org/wordpress/';
(async () => {
    assert.deepEqual(await run(fixtures.multiple.search, source + '?location=7&q=tent+%26+tools#results'), [['copy', target + 'borrow/things/north-branch/search/tent%20%26%20tools/']]);
    assert.deepEqual(await run(fixtures.multiple.search, source + '?q=drill&location=0'), [['copy', target + 'borrow/things/south/search/drill/']]);
    assert.deepEqual(await run(fixtures.multiple.search, source + '?location=007&q=drill'), [['copy', target + 'borrow/things/north-branch/search/drill/']]);
    assert.deepEqual(await run(fixtures.noSlash.search, source + '?q=drill&location=7'), [['copy', target + 'borrow/things/north-branch/search/drill']]);
    for (const suffix of ['?q=drill', '?q=drill&location=99']) {
        const events = await run(fixtures.multiple.search, source + suffix, { choice: '2' });
        assert.match(events[0][1], /1\. North Branch \(7\)/);
        assert.deepEqual(events[1], ['copy', target + 'borrow/things/south/search/drill/']);
    }
    const cancelled = await run(fixtures.multiple.search, source + '?q=drill');
    assert.equal(cancelled.length, 1);
    assert.equal(cancelled[0][0], 'choose');
    for (const choice of ['0', '3', '1.5', 'abc', '']) {
        assert.deepEqual((await run(fixtures.multiple.search, source + '?q=drill', { choice })).map(event => event[0]), ['choose', 'alert']);
    }
    for (const suffix of ['', '?q=', '?q=+++']) {
        assert.deepEqual((await run(fixtures.multiple.search, source + suffix)).map(event => event[0]), ['alert']);
    }
    assert.deepEqual(await run(fixtures.single.search, source + '?q=drill'), [['copy', target + 'borrow/things/search/drill/']]);
    assert.deepEqual(await run(fixtures.legacy.search, source + '?q=drill'), [['copy', target + 'borrow/things/search/drill/']]);
    assert.deepEqual(await run(fixtures.none.search, source + '?q=caf%C3%A9+%2F+tools'), [['copy', target + 'find/caf%C3%A9%20%2F%20tools/']]);
    assert.equal(fixtures.disabled.search, null);
    for (const clipboard of ['missing', 'denied']) {
        assert.deepEqual(await run(fixtures.multiple.search, source + '?q=drill&location=7', { clipboard }), [['manual', target + 'borrow/things/north-branch/search/drill/']]);
    }
    for (const suffix of ['/42', '/42/', '/42?view=admin#details']) {
        assert.deepEqual(await run(fixtures.multiple.thing, source + suffix), [['copy', target + 'items/42/']]);
    }
    assert.deepEqual((await run(fixtures.multiple.thing, source + '/edit')).map(event => event[0]), ['alert']);
    console.log(`Passed ${checks} generated bookmarklet behavior checks.`);
})().catch(error => { console.error(error); process.exitCode = 1; });
