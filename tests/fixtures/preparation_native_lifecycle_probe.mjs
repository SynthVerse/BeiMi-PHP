// Offline pipe probe: caller supplies only inert, non-authentication markers.
import { lstat } from 'node:fs/promises';
import { basename, dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { attachRuntimeLifecycle, readRuntimeInitialization, startRuntimeBroker } from './preparation_native_runtime_broker.mjs';

try {
  const root = resolve(dirname(fileURLToPath(import.meta.url)), '../../.local');
  const directory = resolve(process.argv[2] ?? '');
  if (process.argv.length !== 3 || dirname(directory) !== root || !/^native-runtime-lifecycle-probe-[a-f0-9]+$/.test(basename(directory))) throw new Error();
  const stat = await lstat(directory);
  if (!stat.isDirectory() || stat.isSymbolicLink()) throw new Error();
  const initialization = readRuntimeInitialization(process.stdin);
  const context = await initialization.context;
  const broker = await startRuntimeBroker(context, { directory });
  attachRuntimeLifecycle(broker, initialization);
  process.stdout.write(JSON.stringify({ runId: context.runId, dispatchId: context.dispatchId,
    leaseId: broker.leaseId, expiresAt: broker.expiresAt, brokerProcessId: process.pid }) + '\n');
  const stopped = await broker.closed;
  process.stdin.pause(); process.stdin.unref?.();
  process.stdout.write(JSON.stringify({ event: 'stopped', stopReason: stopped.stopReason,
    statePersisted: stopped.statePersisted, terminalPersisted: stopped.terminalPersisted }) + '\n');
  if (!stopped.statePersisted || !stopped.terminalPersisted) process.exitCode = 1;
} catch {
  process.stderr.write('lifecycle_probe_failed\n');
  process.stdin.pause(); process.stdin.unref?.(); process.exitCode = 1;
}
