import { randomBytes, randomUUID } from 'node:crypto';
import { createServer, request } from 'node:http';
import { mkdir, readFile, rename, writeFile } from 'node:fs/promises';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const PROTOCOL = 'beimi-preparation-native-runtime/v1';
const RUN_ID = 'preparation-development-20261024-01';
const DISPATCH_ID = 'takeover-20261027-native-lease-03';
const IDENTITIES = Object.freeze({
  owner: ['user', 992641, 882641], peer: ['user', 992642, 882641],
  otherStore: ['user', 992643, 882642], admin: ['tenant_admin', 992641, 882641]
});
const CONSUMER = 'wechat-devtools-approved-project';
const SERVICE_MS = 45 * 60 * 1000;
const CLAIM_MS = 120000;
const BODY_LIMIT = 65536;
const RESPONSE_LIMIT = 4 * 1024 * 1024;
const STOP_REASONS = new Set(['explicit_close', 'control_stop', 'lease_expired', 'claim_timeout',
  'control_read_failed', 'control_json_invalid', 'control_contract_invalid', 'control_transition_invalid', 'state_write_failed',
  'parent_stdin_eof', 'parent_stdin_closed', 'parent_stdin_error', 'parent_stdin_extra_input', 'parent_unavailable', 'parent_changed',
  'signal_sigterm', 'signal_sigint', 'upstream_response_rejected', 'initialization_failed']);
const IO_CODES = new Set(['EPERM', 'EACCES', 'EBUSY', 'ENOENT', 'ENOSPC', 'EIO', 'EROFS', 'EMFILE', 'ENFILE', 'EINVAL']);
const API = new Map([
  ...['user/store/status', 'user/info', 'finance/opening/guide', 'finance/preparation', 'finance/workbench',
    'finance/opening', 'finance/opening/subjects', 'master-data/creation/result', 'supplier/index', 'supplier/details',
    'customer/index', 'customer/detail', 'jxc/workforce/employees', 'jxc/workforce/employee',
    'jxc/workforce/me/permissions', 'jxc/workforce/permissions'].map(path => ['/api/' + path, 'GET']),
  ...['finance/opening/start', 'finance/opening/guide/card/save', 'finance/opening/guide/evidence/save',
    'finance/opening/item/save', 'finance/opening/item/remove', 'supplier/add', 'customer/add',
    'jxc/workforce/employee/save'].map(path => ['/api/' + path, 'POST'])
]);

function exactKeys(value, keys) {
  return value !== null && typeof value === 'object' && !Array.isArray(value)
    && Object.keys(value).length === keys.length && keys.every(key => Object.hasOwn(value, key));
}

export function validateRuntimeContext(input) {
  const invalid = () => { throw new Error('runtime_context_invalid'); };
  if (!exactKeys(input, ['protocol', 'runId', 'dispatchId', 'upstreamBaseUrl', 'actors'])
    || input.protocol !== PROTOCOL || input.runId !== RUN_ID || input.dispatchId !== DISPATCH_ID
    || typeof input.upstreamBaseUrl !== 'string' || !/^http:\/\/127\.0\.0\.1:([1-9][0-9]{0,4})\/api\/$/.test(input.upstreamBaseUrl)
    || !exactKeys(input.actors, Object.keys(IDENTITIES))) invalid();
  let url;
  try { url = new URL(input.upstreamBaseUrl); } catch { invalid(); }
  if (!url.port || Number(url.port) > 65535 || url.href !== input.upstreamBaseUrl) invalid();
  const tokens = new Set();
  for (const [name, [actorType, actorId, tenantId]] of Object.entries(IDENTITIES)) {
    const actor = input.actors[name];
    if (!exactKeys(actor, ['actorType', 'actorId', 'tenantId', 'token']) || actor.actorType !== actorType
      || actor.actorId !== actorId || actor.tenantId !== tenantId || typeof actor.token !== 'string'
      || !/^[A-Za-z0-9._~-]{16,512}$/.test(actor.token) || tokens.has(actor.token)) invalid();
    tokens.add(actor.token);
  }
  return structuredClone(input);
}

/** Single initialization line; the parent retains the pipe as a non-disk lease. */
export function readRuntimeInitialization(stream) {
  let resolveContext, rejectContext, resolveDisconnect;
  const context = new Promise((accept, reject) => { resolveContext = accept; rejectContext = reject; });
  const disconnected = new Promise(accept => { resolveDisconnect = accept; });
  let buffer = Buffer.alloc(0), initialized = false, ended = false;
  const fail = reason => {
    if (ended) return;
    ended = true;
    clearTimeout(timeout);
    if (!initialized) rejectContext(new Error('runtime_initialization_invalid'));
    resolveDisconnect(reason);
  };
  const timeout = setTimeout(() => fail('initialization_failed'), 10000);
  timeout.unref();
  stream.on('data', chunk => {
    if (ended) return;
    if (initialized) { fail('parent_stdin_extra_input'); return; }
    buffer = Buffer.concat([buffer, Buffer.from(chunk)]);
    if (buffer.length > BODY_LIMIT) { fail('initialization_failed'); return; }
    const newline = buffer.indexOf(10);
    if (newline < 0) return;
    if (newline !== buffer.length - 1) { fail('initialization_failed'); return; }
    try {
      const input = validateRuntimeContext(JSON.parse(buffer.subarray(0, newline).toString('utf8')));
      initialized = true; buffer.fill(0); buffer = Buffer.alloc(0);
      clearTimeout(timeout); resolveContext(input);
    } catch { fail('initialization_failed'); }
  });
  stream.on('end', () => fail('parent_stdin_eof'));
  stream.on('close', () => fail('parent_stdin_closed'));
  stream.on('error', () => fail('parent_stdin_error'));
  return { context, disconnected };
}

export async function startRuntimeBroker(input, options = {}) {
  const context = validateRuntimeContext(input);
  const directory = options.directory ?? resolve(dirname(fileURLToPath(import.meta.url)), '../../.local/final-runtime-backend-20261026/service-attempt-03');
  const now = options.now ?? Date.now;
  const startedAt = now(), deadline = startedAt + SERVICE_MS;
  const monotonicStartedAt = performance.now(), monotonicDeadline = monotonicStartedAt + SERVICE_MS;
  const expiresAt = new Date(deadline).toISOString(), leaseId = randomUUID();
  const upstream = new URL(context.upstreamBaseUrl);
  const secrets = Object.values(context.actors).map(actor => actor.token);
  const capabilities = new Map();
  let phase = 'prepared', claimed = false, sequence = 0, armedUntil = null, armedMonotonicUntil = null, closing = false;
  let baseUrl, timer, controlChain = Promise.resolve(), closePromise, resolveClosed, stopInfo = null;
  const closed = new Promise(accept => { resolveClosed = accept; });
  const counts = { accepted: 0, forwarded: 0, rejected: 0, upstreamFailures: 0, inFlight: 0,
    acceptedGET: 0, acceptedPOST: 0, acceptedOther: 0, forwardedGET: 0, forwardedPOST: 0,
    rejectedGET: 0, rejectedPOST: 0, rejectedOther: 0 };
  const persistence = { retryCount: 0, failedWrites: 0, lastErrorCode: null, lastOperation: null, lastAttemptCount: 0 };
  const sockets = new Set(), outgoing = new Set(), forwarding = new Set(), handlers = new Set();
  const controlPath = join(directory, 'control.json'), statePath = join(directory, 'state.json'), terminalPath = join(directory, 'terminal.json');
  let stateWrites = Promise.resolve();
  const lifetimeStopReason = () => now() >= deadline || performance.now() >= monotonicDeadline ? 'lease_expired'
    : phase === 'armed' && !claimed && (now() >= armedUntil || performance.now() >= armedMonotonicUntil) ? 'claim_timeout' : null;
  const state = () => ({ protocol: PROTOCOL, runId: RUN_ID, dispatchId: DISPATCH_ID, pid: process.pid,
    baseUrl, leaseId, expiresAt, heartbeatAt: new Date(now()).toISOString(), controlSequence: sequence,
    phase, claimed, checks: { fixedContext: true, loopbackOnly: true, fixedApiAllowlist: true, credentialsInMemoryOnly: true },
    counts: { ...counts }, persistence: { ...persistence }, ...(stopInfo ?? {}) });
  async function atomicWrite(path, value) {
    const temporary = path + '.tmp';
    let attempt = 0, operation = path === statePath ? 'state_write' : path === terminalPath ? 'terminal_write' : 'control_write';
    try {
      await writeFile(temporary, JSON.stringify(value) + '\n', { mode: 0o600 });
      operation = operation.replace('_write', '_rename');
      for (attempt = 1; ; attempt++) {
        try { await rename(temporary, path); return; }
        catch (error) {
          persistence.lastErrorCode = IO_CODES.has(error.code) ? error.code : 'OTHER';
          persistence.lastOperation = operation; persistence.lastAttemptCount = attempt;
          if (!['EPERM', 'EACCES', 'EBUSY'].includes(error.code) || attempt >= 8) throw error;
          persistence.retryCount++;
          await new Promise(accept => setTimeout(accept, 20));
        }
      }
    } catch (error) {
      persistence.failedWrites++; persistence.lastOperation = operation;
      persistence.lastErrorCode = IO_CODES.has(error.code) ? error.code : 'OTHER'; persistence.lastAttemptCount = attempt;
      throw new Error('runtime_persistence_failed');
    }
  }
  const persistState = () => {
    const snapshot = state();
    // A failed heartbeat must not poison the later terminal-state write.
    stateWrites = stateWrites.catch(() => {}).then(() => atomicWrite(statePath, snapshot));
    return stateWrites;
  };
  function containsSecret(value) { return secrets.some(secret => String(value).includes(secret)); }
  function sensitiveResponse(buffer, headers) {
    if (headers['content-encoding'] && String(headers['content-encoding']).toLowerCase() !== 'identity') return true;
    const text = buffer.toString('utf8');
    if (containsSecret(text) || Object.values(headers).some(value => containsSecret(value))) return true;
    try { return containsSecret(JSON.stringify(JSON.parse(text))); } catch { return false; }
  }
  function reply(res, status, error) {
    if (res.destroyed || res.writableEnded) return;
    const body = JSON.stringify({ error });
    res.writeHead(status, { 'content-type': 'application/json; charset=utf-8', 'content-length': Buffer.byteLength(body), 'cache-control': 'no-store' });
    res.end(body);
  }
  async function readBody(req) {
    const chunks = []; let size = 0;
    for await (const chunk of req) {
      size += chunk.length;
      if (size > BODY_LIMIT) {
        const error = new Error('runtime_body_limit'); error.status = 413; throw error;
      }
      chunks.push(chunk);
    }
    return Buffer.concat(chunks);
  }
  const server = createServer((req, res) => {
    const task = handle(req, res).catch(() => reply(res, 400, 'runtime_request_rejected')).finally(() => handlers.delete(task));
    handlers.add(task);
  });
  server.requestTimeout = 15000; server.headersTimeout = 10000; server.keepAliveTimeout = 1000;
  server.on('connection', socket => { sockets.add(socket); socket.on('close', () => sockets.delete(socket)); });
  server.on('clientError', (_error, socket) => { socket.end('HTTP/1.1 400 Bad Request\r\nConnection: close\r\nContent-Length: 0\r\n\r\n'); });
  async function close(reason = 'explicit_close') {
    if (closePromise) return closePromise;
    stopInfo = { stopReason: STOP_REASONS.has(reason) ? reason : 'explicit_close',
      stopRequestedAt: new Date(now()).toISOString(), phaseBeforeStop: phase, stoppedAt: null,
      monotonicElapsedMs: Math.floor(performance.now() - monotonicStartedAt),
      claimExpiresAt: armedUntil === null ? null : new Date(armedUntil).toISOString(),
      claimElapsedMs: armedMonotonicUntil === null ? null : Math.floor(performance.now() - (armedMonotonicUntil - CLAIM_MS)) };
    closing = true; phase = 'stop'; capabilities.clear(); clearInterval(timer);
    closePromise = (async () => {
      const serverClosed = new Promise(accept => server.close(accept));
      for (const operation of outgoing) operation.destroy();
      for (const socket of sockets) socket.destroy();
      await serverClosed;
      await Promise.allSettled([...forwarding]);
      await Promise.allSettled([...handlers]);
      stopInfo.stoppedAt = new Date(now()).toISOString();
      let statePersisted = false, terminalPersisted = false;
      try { await persistState(); statePersisted = true; } catch { /* Independent terminal evidence remains required. */ }
      const terminal = { ...state(), statePersisted, terminalPersisted: true };
      try { await atomicWrite(terminalPath, terminal); terminalPersisted = true; } catch { /* Parent must reject a missing terminal. */ }
      secrets.fill('');
      for (const actor of Object.values(context.actors)) actor.token = '';
      const stopped = { ...state(), statePersisted, terminalPersisted }; resolveClosed(stopped); return stopped;
    })();
    return closePromise;
  }
  async function loadControl() {
    if (closing) return false;
    const stop = reason => { void close(reason); return false; };
    let expiryReason = lifetimeStopReason();
    if (expiryReason) return stop(expiryReason);
    let text, info;
    try { text = await readFile(controlPath, 'utf8'); }
    catch (error) {
      persistence.lastOperation = 'control_read'; persistence.lastAttemptCount = 1;
      persistence.lastErrorCode = IO_CODES.has(error.code) ? error.code : 'OTHER';
      return stop('control_read_failed');
    }
    if (closing) return false;
    try { info = JSON.parse(text); } catch { return stop('control_json_invalid'); }
      if (!exactKeys(info, ['runId', 'dispatchId', 'leaseId', 'expiresAt', 'sequence', 'phase'])
        || info.runId !== RUN_ID || info.dispatchId !== DISPATCH_ID || info.leaseId !== leaseId || info.expiresAt !== expiresAt
        || !Number.isSafeInteger(info.sequence) || info.sequence < sequence || !['prepared', 'armed', 'released', 'stop'].includes(info.phase)
        || (info.sequence === sequence && info.phase !== phase)) return stop('control_contract_invalid');
      expiryReason = lifetimeStopReason();
      if (expiryReason) return stop(expiryReason);
      if (info.sequence > sequence) {
        const valid = info.phase === phase || info.phase === 'stop' || (phase === 'prepared' && info.phase === 'armed')
          || (phase === 'armed' && info.phase === 'released' && claimed);
        if (!valid) return stop('control_transition_invalid');
        if (phase === 'prepared' && info.phase === 'armed') { armedUntil = now() + CLAIM_MS; armedMonotonicUntil = performance.now() + CLAIM_MS; }
        sequence = info.sequence;
        if (info.phase === 'stop') return stop('control_stop');
        phase = info.phase;
      }
      if (phase === 'stop') return stop('control_stop');
      try { await persistState(); } catch { return stop('state_write_failed'); }
      if (closing) return false;
      expiryReason = lifetimeStopReason();
      if (expiryReason) return stop(expiryReason);
      return true;
  }
  function refreshControl() {
    controlChain = controlChain.then(loadControl, loadControl);
    return controlChain;
  }
  async function forward(req, res, body, actor, rawPath) {
    const expiryReason = lifetimeStopReason();
    if (closing || phase === 'stop' || expiryReason) {
      counts.rejected++; counts['rejected' + req.method]++;
      if (!closing && expiryReason) void close(expiryReason);
      reply(res, 503, 'runtime_request_rejected'); return;
    }
    counts.forwarded++; counts['forwarded' + req.method]++; counts.inFlight++;
    const completion = new Promise(accept => {
      let settled = false;
      const finish = () => { if (settled) return; settled = true; counts.inFlight--; outgoing.delete(operation); accept(); };
      const operation = request({ hostname: '127.0.0.1', port: upstream.port, method: req.method, path: rawPath, agent: false,
        headers: { token: actor.token, accept: 'application/json', 'accept-encoding': 'identity',
          'content-type': req.headers['content-type'] ?? 'application/json', 'content-length': body.length } }, response => {
        const chunks = []; let size = 0;
        response.on('data', chunk => {
          size += chunk.length;
          if (size > RESPONSE_LIMIT) { operation.destroy(); return; }
          chunks.push(chunk);
        });
        response.on('end', () => {
          const output = Buffer.concat(chunks);
          if (sensitiveResponse(output, response.headers)) {
            counts.upstreamFailures++;
            res.once('finish', () => { void close('upstream_response_rejected'); });
            reply(res, 502, 'runtime_upstream_rejected');
          } else if (response.statusCode >= 300 && response.statusCode < 400) {
            counts.upstreamFailures++; reply(res, 502, 'runtime_upstream_rejected');
          } else if (!closing && !res.destroyed) {
            res.writeHead(response.statusCode ?? 502, { 'content-type': response.headers['content-type'] ?? 'application/octet-stream',
              'content-length': output.length, 'cache-control': 'no-store' });
            res.end(output);
          }
          finish();
        });
        response.on('error', () => { counts.upstreamFailures++; reply(res, 502, 'runtime_upstream_unavailable'); finish(); });
      });
      outgoing.add(operation);
      operation.on('error', () => { counts.upstreamFailures++; reply(res, 502, 'runtime_upstream_unavailable'); finish(); });
      operation.setTimeout(15000, () => operation.destroy());
      req.once('aborted', () => operation.destroy());
      res.once('close', () => { if (!res.writableEnded) operation.destroy(); });
      operation.end(body);
    });
    forwarding.add(completion);
    try { await completion; } finally { forwarding.delete(completion); }
  }
  async function handle(req, res) {
    const method = ['GET', 'POST'].includes(req.method) ? req.method : 'Other';
    counts.accepted++; counts['accepted' + method]++;
    const reject = (status, error = 'runtime_request_rejected') => { counts.rejected++; counts['rejected' + method]++; reply(res, status, error); };
    if (closing || !(await refreshControl()) || closing) { reject(503); return; }
    const expectedHost = new URL(baseUrl).host;
    const headerNames = req.rawHeaders.filter((_value, index) => index % 2 === 0).map(name => name.toLowerCase());
    if (req.headers.host !== expectedHost || req.headers.origin !== undefined || req.headers['access-control-request-method'] !== undefined
      || Object.keys(req.headers).some(name => name === 'forwarded' || name.startsWith('x-forwarded-'))
      || headerNames.filter(name => name === 'host').length !== 1 || headerNames.filter(name => name === 'token').length > 1) { reject(403); return; }
    if (!['GET', 'POST'].includes(req.method)) { reject(405); return; }
    const rawPath = req.url ?? '', pathname = rawPath.split('?')[0];
    if (!rawPath.startsWith('/') || rawPath.length > 8192 || rawPath.includes('#') || containsSecret(rawPath)) { reject(404); return; }
    if (pathname === '/__runtime/health' && rawPath === pathname) {
      if (req.method !== 'GET') { reject(405); return; }
      const body = JSON.stringify(state());
      res.writeHead(200, { 'content-type': 'application/json', 'content-length': Buffer.byteLength(body), 'cache-control': 'no-store' });
      res.end(body); return;
    }
    if (pathname === '/__runtime/claim' && rawPath === pathname) {
      if (req.method !== 'POST') { reject(405); return; }
      if (req.headers['x-beimi-runtime-consumer'] !== CONSUMER || phase !== 'armed') { reject(403); return; }
      if (typeof req.headers['content-type'] !== 'string'
        || !/^application\/json(?:;\s*charset=utf-8)?$/i.test(req.headers['content-type'])) { reject(415); return; }
      let claim;
      try { claim = JSON.parse((await readBody(req)).toString('utf8')); } catch (error) { reject(error.status ?? 400); return; }
      if (!exactKeys(claim, ['runId', 'consumer']) || claim.runId !== RUN_ID || claim.consumer !== CONSUMER) { reject(400); return; }
      if (!(await refreshControl()) || closing || phase !== 'armed' || now() >= armedUntil) { reject(403); return; }
      if (claimed) { reject(409); return; }
      // No asynchronous work between the final gate and claiming the single slot.
      claimed = true;
      const actors = {};
      for (const [name, actor] of Object.entries(context.actors)) {
        const capability = randomBytes(32).toString('base64url');
        capabilities.set(capability, name);
        actors[name] = { actorType: actor.actorType, actorId: actor.actorId, tenantId: actor.tenantId, token: capability };
      }
      const output = JSON.stringify({ protocol: PROTOCOL, runId: RUN_ID, dispatchId: DISPATCH_ID, leaseId, expiresAt,
        baseUrl, cutoffDate: '2026-10-16', activationDate: '2026-10-17', actors,
        publicScope: { synthetic: true, tenantIds: [882641, 882642], actorIds: [992641, 992642, 992643] } });
      res.writeHead(200, { 'content-type': 'application/json', 'content-length': Buffer.byteLength(output), 'cache-control': 'no-store' });
      res.end(output);
      try { await persistState(); } catch { void close('state_write_failed'); }
      return;
    }
    if (!API.has(pathname)) { reject(404); return; }
    if (req.method !== API.get(pathname)) { reject(405); return; }
    if (req.method === 'POST' && phase !== 'released') { reject(403); return; }
    const actorName = capabilities.get(req.headers.token);
    if (!claimed || !actorName) { reject(401); return; }
    const contentType = req.headers['content-type'];
    if (contentType && !/^application\/(?:json|x-www-form-urlencoded)(?:;\s*charset=utf-8)?$/i.test(contentType)) { reject(415); return; }
    let body;
    try { body = await readBody(req); } catch (error) { reject(error.status ?? 400); return; }
    if (containsSecret(body.toString('utf8')) || (req.method === 'GET' && body.length)) { reject(400); return; }
    if (!(await refreshControl()) || closing || phase === 'stop' || !claimed || (req.method === 'POST' && phase !== 'released')) { reject(403); return; }
    if (lifetimeStopReason()) { void close(lifetimeStopReason()); reject(403); return; }
    await forward(req, res, body, context.actors[actorName], rawPath);
  }
  await mkdir(directory, { recursive: true });
  await atomicWrite(controlPath, { runId: RUN_ID, dispatchId: DISPATCH_ID, leaseId, expiresAt, sequence: 0, phase: 'prepared' });
  try {
    await new Promise((accept, reject) => { server.once('error', reject); server.listen(0, '127.0.0.1', accept); });
    baseUrl = `http://127.0.0.1:${server.address().port}/api/`;
    await persistState();
  } catch { await close('initialization_failed'); throw new Error('runtime_initialization_failed'); }
  timer = setInterval(() => { void refreshControl(); }, options.pollIntervalMs ?? 500);
  return { baseUrl, leaseId, expiresAt, closed, close };
}

/** Shared by the real CLI and offline lifecycle probes; no new wire input. */
export function attachRuntimeLifecycle(broker, initialization, runtime = process, monitorIntervalMs = 500) {
  initialization.disconnected.then(reason => broker.close(reason)).catch(() => {});
  const parentPid = runtime.ppid;
  const parentMonitor = setInterval(() => {
    if (runtime.ppid !== parentPid) { void broker.close('parent_changed'); return; }
    try { runtime.kill(parentPid, 0); } catch (error) { if (error.code !== 'EPERM') void broker.close('parent_unavailable'); }
  }, monitorIntervalMs);
  const onTerminate = () => { void broker.close('signal_sigterm'); };
  const onInterrupt = () => { void broker.close('signal_sigint'); };
  runtime.once('SIGTERM', onTerminate); runtime.once('SIGINT', onInterrupt);
  const detach = () => {
    clearInterval(parentMonitor); runtime.removeListener('SIGTERM', onTerminate); runtime.removeListener('SIGINT', onInterrupt);
  };
  broker.closed.then(detach);
  return detach;
}

async function main() {
  if (process.argv.length !== 2) throw new Error('runtime_initialization_invalid');
  const initialization = readRuntimeInitialization(process.stdin);
  const context = await initialization.context;
  const broker = await startRuntimeBroker(context);
  const detach = attachRuntimeLifecycle(broker, initialization);
  process.stdout.write(JSON.stringify({ event: 'ready', pid: process.pid, baseUrl: broker.baseUrl, leaseId: broker.leaseId, expiresAt: broker.expiresAt }) + '\n');
  const stopped = await broker.closed;
  detach();
  process.stdin.pause();
  process.stdin.unref?.();
  process.stdout.write(JSON.stringify({ event: 'stopped', pid: process.pid, leaseId: broker.leaseId, phase: stopped.phase,
    stopReason: stopped.stopReason, counts: stopped.counts, statePersisted: stopped.statePersisted, terminalPersisted: stopped.terminalPersisted }) + '\n');
  if (!stopped.statePersisted || !stopped.terminalPersisted
    || !['control_stop', 'lease_expired', 'claim_timeout', 'parent_stdin_eof'].includes(stopped.stopReason)) process.exitCode = 1;
}

if (process.argv[1] && resolve(process.argv[1]) === fileURLToPath(import.meta.url)) {
  main().catch(() => { process.stderr.write('runtime_initialization_failed\n'); process.stdin.pause(); process.stdin.unref?.(); process.exitCode = 1; });
}
