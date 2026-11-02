import assert from 'node:assert/strict';
import { randomBytes } from 'node:crypto';
import { createServer, request } from 'node:http';
import { lstat, mkdir, readFile, readdir, rename, rm, writeFile } from 'node:fs/promises';
import { dirname, join, resolve, sep } from 'node:path';
import { fileURLToPath } from 'node:url';
import { PassThrough } from 'node:stream';
import { gzipSync } from 'node:zlib';
import { spawn } from 'node:child_process';
import { EventEmitter } from 'node:events';
import test from 'node:test';
import * as brokerModule from '../fixtures/preparation_native_runtime_broker.mjs';

const { validateRuntimeContext } = brokerModule;
const localRoot = resolve(dirname(fileURLToPath(import.meta.url)), '../../.local');
const consumer = 'wechat-devtools-approved-project';

async function waitFor(predicate) {
  for (let index = 0; index < 100; index++) {
    if (await predicate()) return;
    await new Promise(resolveWait => setTimeout(resolveWait, 10));
  }
  assert.fail('public lifecycle did not settle');
}

async function fixture(t, handler, { oldControl = false, pollIntervalMs = 10 } = {}) {
  await mkdir(localRoot, { recursive: true });
  const directory = join(localRoot, 'preparation-native-runtime-broker-test-' + randomBytes(10).toString('hex'));
  await mkdir(directory);
  const observed = [];
  const upstream = createServer(async (req, res) => {
    const chunks = [];
    for await (const chunk of req) chunks.push(chunk);
    observed.push({ method: req.method, url: req.url, headers: req.headers, body: Buffer.concat(chunks) });
    if (handler) return handler(req, res);
    res.writeHead(200, { 'content-type': 'application/json', 'set-cookie': 'must-not-forward=1' });
    res.end('{"code":1,"data":{"test":true}}');
  });
  await new Promise(resolveListen => upstream.listen(0, '127.0.0.1', resolveListen));
  const input = context(`http://127.0.0.1:${upstream.address().port}/api/`);
  let currentTime = Date.now();
  let broker;
  t.after(async () => {
    await broker?.close();
    upstream.closeAllConnections();
    await new Promise(resolveClose => upstream.close(resolveClose));
    const absolute = resolve(directory);
    const allowedRoot = resolve(localRoot);
    assert.equal(dirname(absolute), allowedRoot);
    assert.ok(absolute.startsWith(allowedRoot + sep + 'preparation-native-runtime-broker-test-'));
    assert.equal((await lstat(allowedRoot)).isSymbolicLink(), false);
    assert.equal((await lstat(absolute)).isSymbolicLink(), false);
    assert.equal((await lstat(absolute)).isDirectory(), true);
    await rm(absolute, { recursive: true });
  });
  if (oldControl) await writeFile(join(directory, 'control.json'), JSON.stringify({ phase: 'released', leaseId: 'old-lease' }));
  broker = await brokerModule.startRuntimeBroker(input, { directory, now: () => currentTime, pollIntervalMs });
  async function control(phase, extra = {}) {
    const previous = JSON.parse(await readFile(join(directory, 'control.json'), 'utf8'));
    const temporary = join(directory, 'control.command.tmp');
    await writeFile(temporary, JSON.stringify({ ...previous, phase, sequence: previous.sequence + 1, ...extra }));
    await rename(temporary, join(directory, 'control.json'));
    // Module exposes only public health/lifecycle; polling does not consume a claim.
    if (phase !== 'stop') await waitFor(async () => {
      const state = JSON.parse(await readFile(join(directory, 'state.json'), 'utf8'));
      return state.phase === phase || state.phase === 'stop';
    });
  }
  async function call(path, { method = 'GET', headers = {}, body } = {}) {
    return new Promise((resolveResponse, reject) => {
      const target = new URL(broker.baseUrl);
      const req = request({ hostname: '127.0.0.1', port: target.port, path, method, headers }, res => {
        const chunks = [];
        res.on('data', chunk => chunks.push(chunk));
        res.on('end', () => resolveResponse({ status: res.statusCode, headers: res.headers, text: Buffer.concat(chunks).toString('utf8') }));
        res.on('error', () => reject(new Error('test_transport_closed')));
      });
      req.on('error', () => reject(new Error('test_transport_closed')));
      req.end(body);
    });
  }
  async function claim() {
    const result = await call('/__runtime/claim', { method: 'POST', headers: { 'content-type': 'application/json', 'x-beimi-runtime-consumer': consumer },
      body: JSON.stringify({ runId: input.runId, consumer }) });
    return { ...result, data: JSON.parse(result.text) };
  }
  function containsCredential(value) { return Object.values(input.actors).some(actor => String(value).includes(actor.token)); }
  return { broker, input, observed, directory, control, call, claim, containsCredential,
    advance: milliseconds => { currentTime += milliseconds; } };
}

function context(upstreamBaseUrl = 'http://127.0.0.1:32109/api/') {
  return {
    protocol: 'beimi-preparation-native-runtime/v1',
    runId: 'preparation-development-20261024-01',
    dispatchId: 'takeover-20261027-native-lease-03',
    upstreamBaseUrl,
    actors: Object.fromEntries([
      ['owner', 'user', 992641, 882641], ['peer', 'user', 992642, 882641],
      ['otherStore', 'user', 992643, 882642], ['admin', 'tenant_admin', 992641, 882641]
    ].map(([name, actorType, actorId, tenantId]) => [name, { actorType, actorId, tenantId, token: randomBytes(32).toString('hex') }]))
  };
}

async function holdWindowsStateReader(path) {
  const reader = spawn('powershell.exe', ['-NoLogo', '-NoProfile', '-NonInteractive', '-Command',
    "$ErrorActionPreference='Stop'; $taskHandle=[IO.File]::Open($env:BEIMI_LIFECYCLE_STATE_READER,[IO.FileMode]::Open,[IO.FileAccess]::Read,[IO.FileShare]::ReadWrite); [Console]::Out.WriteLine('reader_locked'); [Console]::Out.Flush(); [Console]::In.ReadLine()|Out-Null; $taskHandle.Dispose()"], {
    windowsHide: true, stdio: ['pipe', 'pipe', 'ignore'], env: { ...process.env, BEIMI_LIFECYCLE_STATE_READER: path }
  });
  const exited = new Promise(accept => reader.once('close', accept));
  try {
    await new Promise((accept, reject) => {
      const timeout = setTimeout(() => reject(new Error('test_reader_not_ready')), 5000);
      reader.stdout.on('data', chunk => {
        if (chunk.toString('utf8').includes('reader_locked')) { clearTimeout(timeout); accept(); }
      });
      reader.once('error', () => { clearTimeout(timeout); reject(new Error('test_reader_not_available')); });
    });
    let released = false;
    return { release: async () => { if (!released) { released = true; reader.stdin.end(); } await exited; } };
  } catch (error) { reader.stdin.end(); await exited; throw error; }
}

test('stdin context admits only the fixed protocol, identities and explicit loopback API', () => {
  const input = context();
  assert.doesNotThrow(() => validateRuntimeContext(input));
  const invalid = [
    { ...input, path: 'arbitrary' }, { ...input, protocol: 'other' }, { ...input, runId: 'other' },
    { ...input, dispatchId: 'other' }, { ...input, dispatchId: 'takeover-20261026-final-runtime-backend-01' },
    { ...input, dispatchId: 'takeover-20261027-native-lease-02' },
    { ...input, upstreamBaseUrl: 'http://localhost:32109/api/' },
    { ...input, upstreamBaseUrl: 'http://127.0.0.1/api/' }, { ...input, upstreamBaseUrl: 'https://127.0.0.1:32109/api/' },
    { ...input, upstreamBaseUrl: 'http://127.0.0.1:32109/api/?x=1' }, { ...input, upstreamBaseUrl: 'http://127.0.0.1:99999/api/' },
    { ...input, upstreamBaseUrl: 'http://user@127.0.0.1:32109/api/' },
    { ...input, actors: { ...input.actors, owner: { ...input.actors.owner, actorId: 1 } } },
    { ...input, actors: { ...input.actors, peer: { ...input.actors.peer, token: input.actors.owner.token } } },
    { ...input, actors: { ...input.actors, extra: input.actors.owner } }
  ];
  for (const value of invalid) assert.throws(() => validateRuntimeContext(value), /^Error: runtime_context_invalid$/);
});

test('single-line initialization keeps its parent pipe live and EOF or extra bytes revoke it', async () => {
  for (const action of ['eof', 'extra-input']) {
    const stream = new PassThrough();
    const initialization = brokerModule.readRuntimeInitialization(stream);
    let disconnected = false;
    initialization.disconnected.then(() => { disconnected = true; });
    stream.write(JSON.stringify(context()) + '\n');
    const input = await initialization.context;
    assert.equal(input.runId, 'preparation-development-20261024-01');
    await new Promise(resolveTurn => setImmediate(resolveTurn));
    assert.equal(disconnected, false);
    if (action === 'eof') stream.end(); else stream.end('{}\n');
    await initialization.disconnected;
    assert.equal(disconnected, true);
  }
  for (const data of ['{invalid}\n', '{}\n{}\n', 'x'.repeat(65537)]) {
    const stream = new PassThrough();
    const initialization = brokerModule.readRuntimeInitialization(stream);
    const rejected = assert.rejects(initialization.context, /^Error: runtime_initialization_invalid$/);
    stream.end(data);
    await rejected; await initialization.disconnected;
  }
});

test('lease resets old controls, claims once, gates writes and rewrites all actor credentials', async t => {
  const f = await fixture(t, null, { oldControl: true });
  const initial = JSON.parse(await readFile(join(f.directory, 'control.json'), 'utf8'));
  assert.equal(initial.phase, 'prepared'); assert.equal(initial.sequence, 0);
  assert.equal(initial.leaseId, f.broker.leaseId); assert.equal(initial.expiresAt, f.broker.expiresAt);
  assert.equal((await f.claim()).status, 403);
  assert.equal((await f.call('/api/finance/opening/item/save', { method: 'POST', body: '{}' })).status, 403);
  const health = await f.call('/__runtime/health');
  assert.equal(health.status, 200); assert.equal(f.containsCredential(health.text), false);
  await f.control('armed');
  const claims = await Promise.all([f.claim(), f.claim()]);
  assert.deepEqual(claims.map(value => value.status).sort(), [200, 409]);
  const claimed = claims.find(value => value.status === 200).data;
  assert.equal(f.containsCredential(JSON.stringify(claimed)), false);
  assert.equal(claimed.baseUrl, f.broker.baseUrl);
  assert.equal(claimed.cutoffDate, '2026-10-16'); assert.equal(claimed.activationDate, '2026-10-17');
  assert.equal(new Set(Object.values(claimed.actors).map(actor => actor.token)).size, 4);
  for (const [name, actor] of Object.entries(claimed.actors)) {
    const response = await f.call('/api/finance/opening/guide', { headers: { token: actor.token, authorization: 'must-not-forward', cookie: 'must-not-forward=1' } });
    assert.equal(response.status, 200); assert.equal(response.headers['set-cookie'], undefined);
    const observed = f.observed.at(-1);
    assert.ok(observed.headers.token === f.input.actors[name].token, 'upstream must receive only the mapped credential');
    assert.equal(observed.headers.authorization, undefined); assert.equal(observed.headers.cookie, undefined);
  }
  const ownerCapability = claimed.actors.owner.token;
  assert.equal((await f.call('/api/finance/opening/item/save', { method: 'POST', headers: { token: ownerCapability }, body: '{}' })).status, 403);
  await f.control('released');
  const body = '{"business_description":"TCP seam","amount":"12.30"}';
  assert.equal((await f.call('/api/finance/opening/item/save', { method: 'POST', headers: { token: ownerCapability, 'content-type': 'application/json' }, body })).status, 200);
  assert.ok(f.observed.at(-1).body.toString('utf8') === body, 'business payload must remain byte-for-byte');
  for (const file of await readdir(f.directory)) {
    const text = await readFile(join(f.directory, file), 'utf8');
    assert.equal(f.containsCredential(text), false);
    assert.ok(Object.values(claimed.actors).every(actor => !text.includes(actor.token)), 'capabilities must not reach disk');
  }
  await f.control('stop');
  const stopped = await f.broker.closed;
  assert.equal(stopped.phase, 'stop');
  assert.equal(stopped.stopReason, 'control_stop'); assert.equal(stopped.phaseBeforeStop, 'released');
  assert.equal(stopped.counts.forwardedGET, 4); assert.equal(stopped.counts.forwardedPOST, 1);
  assert.equal(stopped.terminalPersisted, true); assert.equal(stopped.statePersisted, true);
  const terminalText = await readFile(join(f.directory, 'terminal.json'), 'utf8');
  assert.equal(f.containsCredential(terminalText), false);
  assert.ok(Object.values(claimed.actors).every(actor => !terminalText.includes(actor.token)), 'terminal must not contain proxy capabilities');
  assert.equal(terminalText.includes('TCP seam'), false);
});

test('transient Windows state reader does not terminate a claimed unexpired runtime', { skip: process.platform !== 'win32' }, async t => {
  const f = await fixture(t, null, { pollIntervalMs: 10000 });
  const previous = JSON.parse(await readFile(join(f.directory, 'control.json'), 'utf8'));
  await writeFile(join(f.directory, 'control.command.tmp'), JSON.stringify({ ...previous, phase: 'armed', sequence: 1 }));
  await rename(join(f.directory, 'control.command.tmp'), join(f.directory, 'control.json'));
  assert.equal((await f.call('/__runtime/health')).status, 200);
  await f.claim();
  assert.equal(JSON.parse((await f.call('/__runtime/health')).text).claimed, true);
  const reader = await holdWindowsStateReader(join(f.directory, 'state.json'));
  let release;
  let sharingErrorCode;
  try {
    const probe = join(f.directory, 'reader-probe.tmp');
    await writeFile(probe, await readFile(join(f.directory, 'state.json')));
    await assert.rejects(rename(probe, join(f.directory, 'state.json')), error => {
      sharingErrorCode = error.code;
      return ['EPERM', 'EACCES', 'EBUSY'].includes(error.code);
    });
    release = setTimeout(() => { void reader.release(); }, 80);
    assert.equal((await f.call('/__runtime/health')).status, 200);
    const state = JSON.parse(await readFile(join(f.directory, 'state.json'), 'utf8'));
    assert.equal(state.phase, 'armed'); assert.equal(state.claimed, true);
  } catch (error) {
    const last = JSON.parse(await readFile(join(f.directory, 'state.json'), 'utf8'));
    clearTimeout(release); await reader.release();
    const stopped = await f.broker.close();
    t.diagnostic(JSON.stringify({ sharingErrorCode, closedPhase: stopped.phase, lastPersistedPhase: last.phase,
      diagnosticCloseWasRequested: true, lastPersistedClaimed: last.claimed,
      deadlineStillMoreThan44MinutesAway: Date.parse(f.broker.expiresAt) - Date.now() > 44 * 60 * 1000 }));
    throw error;
  } finally {
    clearTimeout(release); await reader.release();
  }
});

test('persistent state write failure retains an independent terminal reason and final method counts', { skip: process.platform !== 'win32' }, async t => {
  const f = await fixture(t, null, { pollIntervalMs: 10000 });
  const reader = await holdWindowsStateReader(join(f.directory, 'state.json'));
  const request = f.call('/__runtime/health').catch(() => null);
  try {
    const stopped = await f.broker.closed;
    assert.equal(stopped.stopReason, 'state_write_failed');
    assert.equal(stopped.statePersisted, false);
    assert.equal(stopped.terminalPersisted, true);
    const terminal = JSON.parse(await readFile(join(f.directory, 'terminal.json'), 'utf8'));
    assert.equal(terminal.phase, 'stop'); assert.equal(terminal.stopReason, 'state_write_failed');
    assert.equal(terminal.counts.acceptedGET, 1); assert.equal(terminal.counts.forwardedPOST, 0);
    assert.equal(terminal.counts.rejectedGET, 1); assert.equal(terminal.counts.inFlight, 0);
    assert.ok(['EPERM', 'EACCES', 'EBUSY'].includes(terminal.persistence.lastErrorCode));
    assert.equal(f.containsCredential(JSON.stringify(terminal)), false);
  } finally { await reader.release(); await request; }
});

test('parent lifecycle records the actual EOF, input, signal and parent-monitor exit branches', async t => {
  for (const [action, reason] of [['eof', 'parent_stdin_eof'], ['extra', 'parent_stdin_extra_input'],
    ['error', 'parent_stdin_error'], ['close', 'parent_stdin_closed'], ['SIGINT', 'signal_sigint'],
    ['SIGTERM', 'signal_sigterm'], ['missing', 'parent_unavailable'], ['changed', 'parent_changed']]) {
    await t.test(action, async t => {
      const f = await fixture(t);
      const stream = new PassThrough(); const initialization = brokerModule.readRuntimeInitialization(stream);
      stream.write(JSON.stringify(f.input) + '\n'); await initialization.context;
      const runtime = new EventEmitter(); runtime.ppid = 12345; runtime.kill = () => {};
      brokerModule.attachRuntimeLifecycle(f.broker, initialization, runtime, 10);
      if (action === 'eof') stream.end();
      else if (action === 'extra') stream.write('extra');
      else if (action === 'error') stream.emit('error', new Error('inert-test-error'));
      else if (action === 'close') stream.destroy();
      else if (action === 'missing') runtime.kill = () => { const error = new Error('inert'); error.code = 'ESRCH'; throw error; };
      else if (action === 'changed') runtime.ppid = 54321;
      else runtime.emit(action);
      const stopped = await f.broker.closed;
      assert.equal(stopped.stopReason, reason);
      assert.equal(JSON.parse(await readFile(join(f.directory, 'terminal.json'), 'utf8')).stopReason, reason);
      stream.destroy();
    });
  }
  await t.test('EPERM probing parent is not evidence of its death', async t => {
    const f = await fixture(t); const stream = new PassThrough();
    const initialization = brokerModule.readRuntimeInitialization(stream);
    stream.write(JSON.stringify(f.input) + '\n'); await initialization.context;
    const runtime = new EventEmitter(); runtime.ppid = 12345;
    runtime.kill = () => { const error = new Error('inert'); error.code = 'EPERM'; throw error; };
    brokerModule.attachRuntimeLifecycle(f.broker, initialization, runtime, 10);
    await new Promise(accept => setTimeout(accept, 40));
    assert.equal((await f.call('/__runtime/health')).status, 200);
    stream.end(); await f.broker.closed;
  });
});

test('HTTP boundary rejects origins, hosts, methods, path aliases, oversized bodies and unknown capabilities', async t => {
  const f = await fixture(t);
  await f.control('armed'); const { data } = await f.claim(); await f.control('released');
  const token = data.actors.owner.token;
  const checks = [
    ['/api/finance/opening/guide', { headers: { token, origin: 'https://untrusted.invalid' } }, 403],
    ['/api/finance/opening/guide', { headers: { token, host: 'untrusted.invalid' } }, 403],
    ['/api/finance/opening/guide', { headers: { token, 'x-forwarded-host': 'untrusted.invalid' } }, 403],
    ['/api/finance/opening/guide', { method: 'OPTIONS', headers: { token } }, 405],
    ['/api/finance/opening/item/save', { method: 'DELETE', headers: { token } }, 405],
    ['/api/finance/opening/confirm', { method: 'POST', headers: { token }, body: '{}' }, 404],
    ['/api//finance/opening/guide', { headers: { token } }, 404],
    ['/api/finance/opening/%67uide', { headers: { token } }, 404],
    ['/api/../api/finance/opening/guide', { headers: { token } }, 404],
    ['/api/finance/opening/guide', { headers: { token: randomBytes(32).toString('hex') } }, 401],
    ['/api/finance/opening/item/save', { method: 'GET', headers: { token } }, 405],
    ['/api/finance/opening/item/save', { method: 'POST', headers: { token }, body: 'x'.repeat(65537) }, 413]
  ];
  for (const [path, options, expected] of checks) {
    const result = await f.call(path, options);
    assert.equal(result.status, expected); assert.equal(result.headers['access-control-allow-origin'], undefined);
    assert.equal(f.containsCredential(result.text), false);
  }
  assert.equal(f.observed.length, 0);
});

test('claim schema and repeated armed controls cannot bypass the original claim window', async t => {
  const f = await fixture(t);
  await f.control('armed');
  const invalid = await f.call('/__runtime/claim', { method: 'POST', headers: { 'x-beimi-runtime-consumer': consumer, 'content-type': 'application/json' },
    body: JSON.stringify({ runId: f.input.runId, consumer, extra: true }) });
  assert.equal(invalid.status, 400);
  assert.equal((await f.call('/__runtime/claim', { method: 'POST', body: '{}' })).status, 403);
  f.advance(119000); await f.control('armed');
  f.advance(1001);
  await f.broker.closed;
  const state = JSON.parse(await readFile(join(f.directory, 'state.json'), 'utf8'));
  assert.equal(state.phase, 'stop'); assert.equal(state.claimed, false); assert.equal(state.stopReason, 'claim_timeout');
});

for (const [label, contentType] of [['text/plain', 'text/plain'], ['missing', undefined]]) {
  test(`claim rejects ${label} content type without consuming its only slot`, async t => {
    const f = await fixture(t);
    await f.control('armed');
    const headers = { 'x-beimi-runtime-consumer': consumer };
    if (contentType) headers['content-type'] = contentType;
    const body = JSON.stringify({ runId: f.input.runId, consumer });
    const rejected = await f.call('/__runtime/claim', { method: 'POST', headers, body });
    assert.equal(rejected.status, 415);
    assert.equal(JSON.parse((await f.call('/__runtime/health')).text).claimed, false);
    const accepted = await f.call('/__runtime/claim', { method: 'POST', headers: {
      'x-beimi-runtime-consumer': consumer, 'content-type': 'application/json; charset=utf-8'
    }, body });
    assert.equal(accepted.status, 200);
    assert.equal((await f.claim()).status, 409);
    assert.equal(f.observed.length, 0);
  });
}

test('disconnect after a successful claim does not release or reissue its capability slot', async t => {
  const f = await fixture(t);
  await f.control('armed');
  const target = new URL(f.broker.baseUrl);
  await new Promise((accept, reject) => {
    const req = request({ hostname: '127.0.0.1', port: target.port, path: '/__runtime/claim', method: 'POST',
      headers: { 'x-beimi-runtime-consumer': consumer, 'content-type': 'application/json' } }, response => {
      if (response.statusCode !== 200) { reject(new Error('claim_not_admitted')); return; }
      response.destroy(); req.destroy(); accept();
    });
    req.on('error', () => reject(new Error('test_transport_closed')));
    req.end(JSON.stringify({ runId: f.input.runId, consumer }));
  });
  assert.equal((await f.claim()).status, 409);
  assert.equal(JSON.parse(await readFile(join(f.directory, 'state.json'), 'utf8')).claimed, true);
});

test('control schema, lease, deadline and phase regressions stop rather than extend the lease', async t => {
  for (const mutation of [
    { phase: 'released' }, { leaseId: 'stale-lease' }, { expiresAt: '2099-01-01T00:00:00.000Z' },
    { extra: true }, { sequence: -1 }, { runId: 'other' }
  ]) {
    await t.test('invalid control is fail closed', async subtest => {
      const f = await fixture(subtest);
      const control = JSON.parse(await readFile(join(f.directory, 'control.json'), 'utf8'));
      await writeFile(join(f.directory, 'control.json'), JSON.stringify({ ...control, sequence: 1, ...mutation }));
      const stopped = await f.broker.closed;
      assert.ok(['control_contract_invalid', 'control_transition_invalid'].includes(stopped.stopReason));
      assert.equal(JSON.parse(await readFile(join(f.directory, 'state.json'), 'utf8')).phase, 'stop');
    });
  }
  await t.test('fixed absolute deadline expires', async subtest => {
    const f = await fixture(subtest);
    f.advance(45 * 60 * 1000 + 1);
    assert.equal((await f.broker.closed).stopReason, 'lease_expired');
  });
});

test('upstream secrets in response headers or decoded JSON fail closed and redirects never follow', async t => {
  for (const kind of ['body', 'escaped-body', 'encoded-body', 'header', 'redirect']) {
    await t.test(kind, async subtest => {
      let f;
      f = await fixture(subtest, (_req, res) => {
        const secret = f.input.actors.peer.token;
        if (kind === 'header') res.setHeader('x-upstream-diagnostic', secret);
        if (kind === 'redirect') { res.writeHead(302, { location: '/api/not-allowlisted' }); res.end(); return; }
        res.setHeader('content-type', 'application/json');
        if (kind === 'encoded-body') { res.setHeader('content-encoding', 'gzip'); res.end(gzipSync(JSON.stringify({ data: secret }))); return; }
        const encoded = kind === 'escaped-body' ? [...secret].map(character => '\\u' + character.charCodeAt(0).toString(16).padStart(4, '0')).join('') : secret;
        res.end(kind === 'header' ? '{"code":1}' : '{"data":"' + encoded + '"}');
      });
      await f.control('armed'); const { data } = await f.claim();
      const result = await f.call('/api/finance/opening/guide', { headers: { token: data.actors.owner.token } });
      assert.equal(result.status, 502); assert.equal(f.containsCredential(result.text), false);
      assert.equal(f.observed.length, 1); assert.equal(result.headers.location, undefined);
      if (kind !== 'redirect') assert.equal((await f.broker.closed).stopReason, 'upstream_response_rejected');
    });
  }
});

test('unreadable and malformed control files produce distinct sanitized terminal reasons', async t => {
  for (const [kind, reason] of [['missing', 'control_read_failed'], ['invalid-json', 'control_json_invalid']]) {
    await t.test(kind, async t => {
      const f = await fixture(t, null, { pollIntervalMs: 10000 });
      if (kind === 'missing') await rename(join(f.directory, 'control.json'), join(f.directory, 'control.unavailable.json'));
      else await writeFile(join(f.directory, 'control.json'), '{invalid');
      const request = f.call('/__runtime/health').catch(() => null);
      const stopped = await f.broker.closed; await request;
      assert.equal(stopped.stopReason, reason); assert.equal(stopped.terminalPersisted, true);
      if (kind === 'missing') assert.equal(stopped.persistence.lastErrorCode, 'ENOENT');
    });
  }
});

test('a deadline crossed during a Windows state retry never admits the waiting request', { skip: process.platform !== 'win32' }, async t => {
  const f = await fixture(t, null, { pollIntervalMs: 10000 });
  const reader = await holdWindowsStateReader(join(f.directory, 'state.json'));
  const expiry = f.broker.expiresAt;
  const request = f.call('/__runtime/health').catch(() => null);
  const advance = setTimeout(() => f.advance(45 * 60 * 1000 + 1), 20);
  const release = setTimeout(() => { void reader.release(); }, 80);
  try {
    const stopped = await f.broker.closed; await request;
    assert.equal(stopped.stopReason, 'lease_expired'); assert.equal(stopped.expiresAt, expiry);
    assert.equal(stopped.counts.forwarded, 0); assert.equal(stopped.counts.inFlight, 0);
  } finally { clearTimeout(advance); clearTimeout(release); await reader.release(); }
});

test('EOF during the final state write cannot create a late upstream GET', { skip: process.platform !== 'win32' }, async t => {
  const f = await fixture(t, null, { pollIntervalMs: 10000 });
  const previous = JSON.parse(await readFile(join(f.directory, 'control.json'), 'utf8'));
  await writeFile(join(f.directory, 'control.command.tmp'), JSON.stringify({ ...previous, phase: 'armed', sequence: 1 }));
  await rename(join(f.directory, 'control.command.tmp'), join(f.directory, 'control.json'));
  await f.call('/__runtime/health'); const { data } = await f.claim();
  await f.call('/__runtime/health');
  const accepted = JSON.parse(await readFile(join(f.directory, 'state.json'), 'utf8')).counts.accepted;
  const stream = new PassThrough(); const initialization = brokerModule.readRuntimeInitialization(stream);
  stream.write(JSON.stringify(f.input) + '\n'); await initialization.context;
  const runtime = new EventEmitter(); runtime.ppid = 12345; runtime.kill = () => {};
  brokerModule.attachRuntimeLifecycle(f.broker, initialization, runtime, 10);
  let pendingRequest;
  const pending = new Promise(accept => {
    pendingRequest = request({ hostname: '127.0.0.1', port: new URL(f.broker.baseUrl).port,
      method: 'GET', path: '/api/finance/opening/guide',
      headers: { token: data.actors.owner.token, 'transfer-encoding': 'chunked' } }, response => {
      response.resume(); response.on('end', accept); response.on('error', accept);
    });
    pendingRequest.on('error', accept); pendingRequest.flushHeaders();
  });
  await waitFor(async () => JSON.parse(await readFile(join(f.directory, 'state.json'), 'utf8')).counts.accepted === accepted + 1);
  const reader = await holdWindowsStateReader(join(f.directory, 'state.json'));
  pendingRequest.end();
  const stop = setTimeout(() => stream.end(), 20);
  const release = setTimeout(() => { void reader.release(); }, 80);
  try {
    const stopped = await f.broker.closed; await pending;
    assert.equal(stopped.stopReason, 'parent_stdin_eof');
    assert.equal(f.observed.length, 0);
    assert.equal(stopped.counts.forwardedGET, 0); assert.equal(stopped.counts.inFlight, 0);
  } finally {
    clearTimeout(stop); clearTimeout(release); await reader.release(); stream.destroy(); pendingRequest.destroy();
  }
});

test('stop closes admissions and aborts an in-flight upstream request before closure settles', async t => {
  let upstreamClosed = false;
  const f = await fixture(t, (req, res) => { res.on('close', () => { upstreamClosed = true; }); });
  await f.control('armed'); const { data } = await f.claim();
  const pending = f.call('/api/finance/opening/guide', { headers: { token: data.actors.owner.token } }).catch(() => null);
  await waitFor(() => f.observed.length === 1);
  await f.control('stop'); await f.broker.closed; await pending;
  await waitFor(() => upstreamClosed);
});
