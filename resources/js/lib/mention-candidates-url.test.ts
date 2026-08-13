import assert from 'node:assert/strict';
import { test } from 'node:test';
import { candidatesUrlFor } from './mention-candidates-url.ts';

test('a plain endpoint opens its query string', () => {
    assert.equal(candidatesUrlFor('/timeline/mention-candidates', 'bo'), '/timeline/mention-candidates?q=bo');
});

/** The case the old groupId prop got wrong: a scoped endpoint must keep the scope it arrived with. */
test('an endpoint that already carries a parameter keeps it', () => {
    assert.equal(candidatesUrlFor('/x?scope=7', 'bo'), '/x?scope=7&q=bo');
});

test('a talk endpoint needs no scope parameter at all', () => {
    assert.equal(candidatesUrlFor('/groups/7/talk/mention-candidates', 'bo'), '/groups/7/talk/mention-candidates?q=bo');
});

test('the term is encoded, so a wildcard or a space cannot reshape the query', () => {
    assert.equal(candidatesUrlFor('/x', 'a b&c=%'), '/x?q=a%20b%26c%3D%25');
});

test('an empty term still names the parameter', () => {
    assert.equal(candidatesUrlFor('/x', ''), '/x?q=');
});
