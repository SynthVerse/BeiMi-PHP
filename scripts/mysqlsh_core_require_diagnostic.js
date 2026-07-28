try {
  require('./migrate_probe_core.js');
  print('{"status":"ok","code":"core_require_ok","stage":"core_require","core_loaded":true}');
} catch {
  print('{"status":"blocked","code":"core_require_failed","stage":"core_require","core_loaded":false}');
}
