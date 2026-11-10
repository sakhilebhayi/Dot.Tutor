import test from 'node:test';
import assert from 'node:assert/strict';
import { resumeAt } from '../../resources/js/student/player.js';

test('resumes at a saved position inside the video', () => {
    assert.equal(resumeAt('95', 300), 95);
});

test('starts from the beginning with no saved position, a bad value or a position past the end', () => {
    assert.equal(resumeAt('0', 300), null);
    assert.equal(resumeAt(undefined, 300), null);
    assert.equal(resumeAt('abc', 300), null);
    assert.equal(resumeAt('400', 300), null);
});

test('resumes before the duration is known', () => {
    assert.equal(resumeAt('30', Number.NaN), 30);
});
