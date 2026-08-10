'use strict';

// Local, disposable-schema migration probe. Static mode deliberately has no
// database client, subprocess, environment-file or network dependency.
const IS_NODE = typeof process !== 'undefined' && typeof require === 'function';
let nodeDependencies = null;
function nodeDeps() {
  if (!IS_NODE) fail('node_runtime_required');
  if (nodeDependencies === null) {
    const fs = require('fs');
    const path = require('path');
    nodeDependencies = { fs, path, crypto: require('crypto'), root: path.resolve(__dirname, '..') };
  }
  return nodeDependencies;
}
const TARGET = 'beimi_r4_probe_20260726_plan020';
const LIKE = 'public/install/db/like.sql';
const JXC = 'database/sql/jxc_phase1_schema.sql';
const DEFAULT_GOODS_CATEGORY_MIGRATION = '20260809_000001_add_default_goods_category.sql';
const PLATFORM_DEFAULT_GOODS_CATEGORY_MIGRATION = '20260810_000001_add_platform_default_goods_category.sql';
const EXPECTED = {
  [LIKE]: '420393F1A9DF5B9CEBC9C7A5BE6815C737B46D9D9B2044F04470895776620A57',
  [JXC]: '55240B8B94175FEFD9748E1B8F81A3FBCB873B9B0700B4342B865ADC621D9934',
};
const EXPECTED_MIGRATION_HASHES = {
  '20260419_000001_add_idempotent_key.sql':'44FB3A5EBED070048A34358C3D3CE786F354DDF0AD43F0E721AC23EC63C75BCB',
  '20260419_000002_create_audit_log.sql':'A3BAE8C87C28C733DE2D7C19BAACF062C11410B16AAB97D812FC6E665F7CE92D',
  '20260419_000003_create_migration_history.sql':'0AEE8C80C7072135AD6B109B816D8E458967F73D8D1FA04EDF6D5E9B93681889',
  '20260501_000001_fix_user_tenant_default.sql':'5C532D692D4023C18F1A52DBC5D7B3F64AC6ED59FCE422C9EB65A3003363E400',
  '20260508_000001_add_tenant_expired_time.sql':'1C5CA300DDB6C2D40D7609681587E4FD4A5B4A8B19397057F11597D11F6A3554',
  '20260521_000001_create_tenant_membership.sql':'114B739F9CFEBB56BADD05ADA9A65A027C643E97181EDA25A1878612A25FA567',
  '20260521_000002_add_tenant_relation_and_invite_types.sql':'EB754EA1E6EE7D34F6AB464855F65354C0DD1132778666379FA7F98549958943',
  '20260524_000001_add_platform_wechat_user_menu.sql':'D7A813A93F1A91CFF8A6F4F69E0392A77A00278D9258A81724526E50A8EB0430',
  '20260527_000001_add_platform_tenant_recycle_menu.sql':'D3317CF0E096EF0437FC03019D09B23DC55B73D3397CD937DD67176B70F7A361',
  '20260530_000001_create_cloud_goods.sql':'4BAE1AF857B94201CBA3A014AD3D311A6AB9C480E78F7F41D6BEC3CDF74B5926',
  '20260602_000001_move_platform_cloud_goods_menu.sql':'69F64298CED9309E7194AF346AD96E629BA061FF063AD88EF64DDA12B2080D8F',
  '20260602_000002_create_tenant_goodscat.sql':'BDD56AEE175CEABAEDA6AD537A73569583C64FD72F9541F9C8CAFB2CA1C693BD',
  '20260602_000003_platform_goodscat_and_cloud_goods_category.sql':'9BA5C1BACE9772A29EA7A7EB1B6B740F08929422F491F436D16AC146EBFD2815',
  '20260603_000001_archive_cloud_goods.sql':'2A1BBE567ADD143CB4608AC7F7811EB62830BDF87CEE340A0A1DCA0B585BAF68',
  '20260603_000002_aquatic_goods_v1.sql':'255C824ED7815DFD8A2BD5F8B85AA2AB25A6C337C34361B4231332A550F84143',
  '20260609_000001_create_goods_units_binding.sql':'3ADB999F67BD2923095652EE7887C172B5CF66BECF8887BBBDA17F03796F9A12',
  '20260613_000001_add_goods_is_archived.sql':'013C7EC9136414F556D7DE16F0FE21195B789FDCD086DEF4A46A0A806A01F063',
  '20260614_000001_quality_spec_separation.sql':'556A4CCDEAB9A2B9D519697ED03013F366F06602C7802BCD3D53DB72611D5723',
  '20260614_000002_spec_value_goods_id.sql':'5DB592DE31CF262EBEB85524D3AA3C38FDBD025058D56E509C3A35DB0F1740D5',
  '20260630_000001_create_purchase_return_order.sql':'772772C427D0387FFD41BD765D7C630B284D5A5E624483E604436A7294EBFADF',
  '20260630_000002_add_sales_return_original_line_to_order_goods.sql':'3E32BC71D3E246211D291C6CF99A5FF1D9DBE5B487EC10CD42D9336C493B8B53',
  '20260729_000001_create_warehouse_goods_balance.sql':'0E29F49C57B3D28F3C5AFB42D777F3F3DE63653377C9698CCC07671E22913D70',
  '20260729_000002_create_customer_report_workflow.sql':'E9848A7AED829E1950A4CEF2D23BC982C08A087E1BD8346BA74FABC8246C65D6',
  '20260730_000001_customer_report_sales_order_bridge.sql':'B676C77B47A69CDF799418C3DB4B897E76BFC287AF86D310A9A7D55412CD5DAB',
  '20260731_000001_add_user_session_create_time.sql':'E1017FAE04C72FD2216AA1512EFCE0C3B82DB90CF0C402248C136F206992B67A',
  '20260805_000001_create_goods_alias.sql':'DD414987D1AC70BE93F200C8902B914E9452D47555ED5C30B5FC47B686FD305B',
  '20260809_000001_add_default_goods_category.sql':'C14DBC9F6E4981D67E413D9ED540CD9294CE7B9F8234EDA2B37E9601593DF289',
  '20260809_000002_add_goods_maintenance_permission.sql':'67BE8670307550EC14B4A3B0B0C0A57146C3724DC0F894264F2FE22FD5F25B8B',
  '20260810_000001_add_platform_default_goods_category.sql':'46CB10B05038FB1AA0BFF79D664677F802337C6437DF2FAE070791AC9C22C414',
};
const POSITIVE = [
  'la_warehouse_goods_balance', 'la_customer_report',
  'la_customer_report_item', 'la_customer_report_reservation',
  'la_customer_goods_report_preference', 'la_sales_order',
  'la_order_goods', 'la_stock_flow', 'la_receivable_flow',
  'la_supply_order', 'la_purchase_return_order',
  'la_purchase_return_order_lists', 'la_audit_log',
  'la_migration_history',
];
const NEGATIVE = [
  'la_purchase_order', 'la_sales_reservation', 'la_sales_reservation_item',
  'la_inventory_reservation', 'la_work_task', 'la_work_task_log',
  'la_task_employee', 'la_task_employee_role', 'la_task_print_log',
  'la_procurement_task', 'la_procurement_task_inbound',
  'la_task_role', 'la_task_type', 'la_task_type_role',
  'la_customer_report_sale', 'la_customer_report_sale_item',
];

function fail(code) { throw { probeCode: code }; }
function safeCode(error, fallback) { return error && Object.prototype.hasOwnProperty.call(error, 'probeCode') ? error.probeCode : fallback; }
function normalizeLineEndings(text) { return text.replace(/\r\n?/g, '\n'); }
function sha(text) { return nodeDeps().crypto.createHash('sha256').update(normalizeLineEndings(text)).digest('hex').toUpperCase(); }
function read(relative) {
  const deps = nodeDeps(), file = deps.path.join(deps.root, relative);
  if (!deps.fs.existsSync(file)) fail('input_missing');
  const text = deps.fs.readFileSync(file, 'utf8');
  if (text.charCodeAt(0) === 0xFEFF) fail('utf8_bom');
  return text;
}

function prepareMigrationSql(sql, prefix) {
  if (!/^[A-Za-z0-9_]*$/.test(prefix)) fail('invalid_database_prefix');
  const prepared = sql.replaceAll('{{prefix}}', prefix);
  if (/\{\{[^{}]+\}\}/.test(prepared)) fail('unresolved_prefix');
  return prepared;
}

// Only split semicolons outside quoted identifiers/strings and comments.
function splitSql(input) {
  const out = []; let buf = ''; let state = 'plain';
  for (let i = 0; i < input.length; i++) {
    const c = input[i], n = input[i + 1]; buf += c;
    if (state === 'plain') {
      if (c === "'" || c === '"' || c === '`') state = c;
      else if (c === '-' && n === '-') { state = 'line'; }
      else if (c === '#') state = 'line';
      else if (c === '/' && n === '*') state = 'block';
      else if (c === ';') { const s = buf.slice(0, -1).trim(); if (s) out.push(s); buf = ''; }
    } else if (state === 'line') {
      if (c === '\n') state = 'plain';
    } else if (state === 'block') {
      if (c === '*' && n === '/') { buf += n; i++; state = 'plain'; }
    } else if (c === '\\') { if (n !== undefined) { buf += n; i++; } }
    else if (c === state) state = 'plain';
  }
  if (state !== 'plain') fail('sql_unclosed_state');
  if (/\bDELIMITER\b|\bCREATE\s+(PROCEDURE|FUNCTION|TRIGGER)\b/i.test(input)) fail('unsupported_sql');
  if (buf.trim()) out.push(buf.trim());
  return out;
}

function stripLeadingSqlComments(statement) {
  let stripped = statement;
  while (true) {
    const line = stripped.match(/^\s*(?:--[ \t][^\r\n]*(?:\r\n|\r|\n|$)|#[^\r\n]*(?:\r\n|\r|\n|$))/);
    if (line) { stripped = stripped.slice(line[0].length); continue; }
    const block = stripped.match(/^\s*\/\*(?!\!)[\s\S]*?\*\//);
    if (block) { stripped = stripped.slice(block[0].length); continue; }
    return stripped;
  }
}

function runStaticProbe() {
  assertMetadataContract();
  const like = prepareMigrationSql(read(LIKE), 'la_');
  const jxc = prepareMigrationSql(read(JXC), 'la_');
  const tenantLike = prepareMigrationSql(read(LIKE), 'tenantx_');
  const tenantJxc = prepareMigrationSql(read(JXC), 'tenantx_');
  if (/\bla_[A-Za-z0-9_]+/.test(tenantLike) || /\bla_[A-Za-z0-9_]+/.test(tenantJxc)) fail('baseline_non_default_prefix_leak');
  const productionRunner = read('scripts/migrate.php');
  if (!productionRunner.includes('MigrationSqlPreprocessor::prepare($sql, $prefix)')) fail('production_preprocessor_not_wired');
  if (sha(like) !== EXPECTED[LIKE] || sha(jxc) !== EXPECTED[JXC]) fail('baseline_hash_mismatch');
  const deps = nodeDeps();
  const names = deps.fs.readdirSync(deps.path.join(deps.root, 'database/migrations')).filter(x => x.endsWith('.sql')).sort();
  if (names.length !== 29) fail('migration_count_mismatch');
  const migrationHashes = {}; let statements = 0; const finalTables = new Set();
  for (const source of [like, jxc]) for (const match of source.matchAll(/CREATE\s+TABLE(?:\s+IF\s+NOT\s+EXISTS)?\s+`?([A-Za-z0-9_]+)/gi)) finalTables.add(match[1]);
  for (const name of names) {
    const raw = read(deps.path.join('database/migrations', name));
    if (!raw.includes('{{prefix}}') || /\bla_[A-Za-z0-9_]+/.test(raw)) fail('migration_source_prefix_contract');
    const tenantText = prepareMigrationSql(raw, 'tenantx_');
    if (/\bla_[A-Za-z0-9_]+/.test(tenantText) || !tenantText.includes('tenantx_')) fail('non_default_prefix_leak');
    const text = prepareMigrationSql(raw, 'la_');
    migrationHashes[name] = sha(text); if (migrationHashes[name] !== EXPECTED_MIGRATION_HASHES[name]) fail('migration_hash_mismatch');
    for (const statement of splitSql(text)) {
      const reducerStatement = stripLeadingSqlComments(statement);
      const create = reducerStatement.match(/^\s*CREATE\s+TABLE(?:\s+IF\s+NOT\s+EXISTS)?\s+`?([A-Za-z0-9_]+)/i);
      const drop = reducerStatement.match(/^\s*DROP\s+TABLE(?:\s+IF\s+EXISTS)?\s+(.+)$/is);
      if (create) finalTables.add(create[1]);
      if (drop) {
        for (const part of drop[1].split(',')) {
          const name = part.trim().match(/^`?([A-Za-z0-9_]+)/);
          if (!name) fail('unsupported_drop_table');
          finalTables.delete(name[1]);
        }
      }
    }
    statements += splitSql(text).length;
  }
  if (statements !== 215) fail('statement_count_mismatch');
  if (finalTables.size !== 99) fail('final_table_count_mismatch');
  if (Object.keys(EXPECTED_MIGRATION_HASHES).length !== names.length) fail('migration_manifest_mismatch');
  for (const table of POSITIVE) if (!finalTables.has(table)) fail('positive_assertion_missing');
  for (const table of NEGATIVE) if (finalTables.has(table)) fail('negative_assertion_failed');
  return { status: 'static_passed', code: 'static_passed', migration_count: names.length, statement_count: statements, baseline_tables: 74, final_tables: finalTables.size };
}

function runtimeSha256(text) {
  text = normalizeLineEndings(text);
  const k = [0x428a2f98,0x71374491,0xb5c0fbcf,0xe9b5dba5,0x3956c25b,0x59f111f1,0x923f82a4,0xab1c5ed5,0xd807aa98,0x12835b01,0x243185be,0x550c7dc3,0x72be5d74,0x80deb1fe,0x9bdc06a7,0xc19bf174,0xe49b69c1,0xefbe4786,0x0fc19dc6,0x240ca1cc,0x2de92c6f,0x4a7484aa,0x5cb0a9dc,0x76f988da,0x983e5152,0xa831c66d,0xb00327c8,0xbf597fc7,0xc6e00bf3,0xd5a79147,0x06ca6351,0x14292967,0x27b70a85,0x2e1b2138,0x4d2c6dfc,0x53380d13,0x650a7354,0x766a0abb,0x81c2c92e,0x92722c85,0xa2bfe8a1,0xa81a664b,0xc24b8b70,0xc76c51a3,0xd192e819,0xd6990624,0xf40e3585,0x106aa070,0x19a4c116,0x1e376c08,0x2748774c,0x34b0bcb5,0x391c0cb3,0x4ed8aa4a,0x5b9cca4f,0x682e6ff3,0x748f82ee,0x78a5636f,0x84c87814,0x8cc70208,0x90befffa,0xa4506ceb,0xbef9a3f7,0xc67178f2];
  const bytes = [];
  for (let i = 0; i < text.length; i++) { let code = text.charCodeAt(i); if (code >= 0xD800 && code <= 0xDBFF && i + 1 < text.length) { const low = text.charCodeAt(i + 1); if (low >= 0xDC00 && low <= 0xDFFF) { code = 0x10000 + ((code - 0xD800) << 10) + low - 0xDC00; i++; } } if (code < 0x80) bytes.push(code); else if (code < 0x800) bytes.push(0xC0 | (code >>> 6), 0x80 | (code & 0x3F)); else if (code < 0x10000) bytes.push(0xE0 | (code >>> 12), 0x80 | ((code >>> 6) & 0x3F), 0x80 | (code & 0x3F)); else bytes.push(0xF0 | (code >>> 18), 0x80 | ((code >>> 12) & 0x3F), 0x80 | ((code >>> 6) & 0x3F), 0x80 | (code & 0x3F)); }
  const length = bytes.length * 8; bytes.push(0x80); while (bytes.length % 64 !== 56) bytes.push(0); for (let shift = 56; shift >= 0; shift -= 8) bytes.push(Math.floor(length / Math.pow(2, shift)) & 0xFF);
  let h0 = 0x6a09e667, h1 = 0xbb67ae85, h2 = 0x3c6ef372, h3 = 0xa54ff53a, h4 = 0x510e527f, h5 = 0x9b05688c, h6 = 0x1f83d9ab, h7 = 0x5be0cd19;
  for (let offset = 0; offset < bytes.length; offset += 64) { const w = []; for (let i = 0; i < 16; i++) w[i] = ((bytes[offset + i * 4] << 24) | (bytes[offset + i * 4 + 1] << 16) | (bytes[offset + i * 4 + 2] << 8) | bytes[offset + i * 4 + 3]) >>> 0; for (let i = 16; i < 64; i++) { const x = w[i - 15], y = w[i - 2]; w[i] = (((x >>> 7) | (x << 25)) ^ ((x >>> 18) | (x << 14)) ^ (x >>> 3)) + w[i - 16] + (((y >>> 17) | (y << 15)) ^ ((y >>> 19) | (y << 13)) ^ (y >>> 10)) + w[i - 7]; }
    let a = h0, b = h1, c = h2, d = h3, e = h4, f = h5, g = h6, h = h7;
    for (let i = 0; i < 64; i++) { const s1 = ((e >>> 6) | (e << 26)) ^ ((e >>> 11) | (e << 21)) ^ ((e >>> 25) | (e << 7)); const t1 = (h + s1 + ((e & f) ^ (~e & g)) + k[i] + w[i]) >>> 0; const s0 = ((a >>> 2) | (a << 30)) ^ ((a >>> 13) | (a << 19)) ^ ((a >>> 22) | (a << 10)); const t2 = (s0 + ((a & b) ^ (a & c) ^ (b & c))) >>> 0; h = g; g = f; f = e; e = (d + t1) >>> 0; d = c; c = b; b = a; a = (t1 + t2) >>> 0; }
    h0 = (h0 + a) >>> 0; h1 = (h1 + b) >>> 0; h2 = (h2 + c) >>> 0; h3 = (h3 + d) >>> 0; h4 = (h4 + e) >>> 0; h5 = (h5 + f) >>> 0; h6 = (h6 + g) >>> 0; h7 = (h7 + h) >>> 0;
  }
  return [h0,h1,h2,h3,h4,h5,h6,h7].map(x => ('00000000' + x.toString(16)).slice(-8)).join('').toUpperCase();
}

function runtimeRead(relative) {
  const text = os.loadTextFile(relative);
  if (text.charCodeAt(0) === 0xFEFF) fail('utf8_bom');
  return text;
}

function runtimeFail(code) { fail(code); }
const METADATA_OPERATIONS = {
  table_exists: {
    sql: 'SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?',
    params: 1,
  },
  column_exists: {
    sql: 'SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?',
    params: 2,
  },
  index_exists: {
    sql: 'SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND INDEX_NAME = ?',
    params: 2,
  },
  target_table_count: {
    sql: 'SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = ?',
    params: 0,
  },
  target_preflight_exists: {
    sql: 'SELECT COUNT(*) FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME = ?',
    params: 0,
  },
  target_cleanup_exists: {
    sql: 'SELECT COUNT(*) FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME = ?',
    params: 0,
  },
};
const METADATA_CONTRACT = {
  table_exists: ['SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?', 1],
  column_exists: ['SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?', 2],
  index_exists: ['SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND INDEX_NAME = ?', 2],
  target_table_count: ['SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = ?', 0],
  target_preflight_exists: ['SELECT COUNT(*) FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME = ?', 0],
  target_cleanup_exists: ['SELECT COUNT(*) FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME = ?', 0],
};
function assertMetadataContract() {
  const names = Object.keys(METADATA_CONTRACT);
  if (Object.keys(METADATA_OPERATIONS).length !== names.length) fail('metadata_contract_invalid');
  for (const operation of names) {
    const query = METADATA_OPERATIONS[operation], expected = METADATA_CONTRACT[operation];
    if (!query || query.sql !== expected[0] || query.params !== expected[1] || !/^SELECT COUNT\(\*\) FROM INFORMATION_SCHEMA\.(TABLES|COLUMNS|STATISTICS|SCHEMATA) WHERE [A-Z_ =?]+(?: AND [A-Z_ =?]+)*$/.test(query.sql)) fail('metadata_contract_invalid');
  }
}
function metadataQuery(operation, params) {
  assertMetadataContract();
  const query = METADATA_OPERATIONS[operation];
  if (!query) fail('metadata_operation_not_allowed');
  if (!Array.isArray(params) || params.length !== query.params || params.some(value => typeof value !== 'string' || !/^[A-Za-z_][A-Za-z0-9_]*$/.test(value))) fail('metadata_params_invalid');
  return { sql: query.sql, params: [TARGET].concat(params) };
}
function runtimeMetadataScalar(operation, params) {
  const query = metadataQuery(operation, params);
  const row = session.runSql(query.sql, query.params).fetchOne();
  return row ? row[0] : null;
}
function runtimeScalar(sql, params) {
  if ((sql !== 'SELECT @@port' && sql !== 'SELECT version FROM la_migration_history ORDER BY version') || (params && (!Array.isArray(params) || params.length !== 0))) runtimeFail('runtime_scalar_not_allowed');
  const row = session.runSql(sql, params || []).fetchOne();
  return row ? row[0] : null;
}
function runtimeExists(table) { return Number(runtimeMetadataScalar('table_exists', [table])) === 1; }
function runtimeMetadataReadSafe(sql) {
  const normalized = sql.replace(/\s+/g, ' ').trim();
  return /^SET @[A-Za-z0-9_]+\s*:=\s*\(\s*SELECT COUNT\((?:1|\*)\) FROM information_schema\.(?:TABLES|COLUMNS|STATISTICS) WHERE TABLE_SCHEMA = DATABASE\(\)(?: AND (?:TABLE_NAME|COLUMN_NAME|INDEX_NAME) = '[A-Za-z0-9_]+')+\s*\)$/i.test(normalized);
}
function runtimeSafeSql(statement) {
  const sql = stripLeadingSqlComments(statement);
  if (!sql) return false;
  if (/\b(CREATE|DROP)\s+DATABASE\b|\bUSE\b|\bSOURCE\b|\b(GRANT|REVOKE)\b|\bLOAD\s+DATA\b|\b(INTO\s+)?(OUTFILE|DUMPFILE)\b|\b(PLUGIN|COMPONENT|PERSIST(?:_ONLY)?)\b|\b(CREATE|DROP|ALTER)\s+(PROCEDURE|FUNCTION|TRIGGER|EVENT)\b|\bEXTERNAL\b/i.test(sql)) runtimeFail('locked_sql_forbidden');
  if (/(?:`[A-Za-z0-9_]+`|[A-Za-z_][A-Za-z0-9_]*)\s*\.\s*(?:`[A-Za-z0-9_]+`|[A-Za-z_][A-Za-z0-9_]*)/.test(sql)
    && !runtimeMetadataReadSafe(sql)
  ) runtimeFail('locked_sql_cross_schema');
  return true;
}
function duplicateAddIsPresent(statement, code) {
  const sql = stripLeadingSqlComments(statement);
  if ((sql.match(/\b(?:ADD|DROP|CHANGE|MODIFY|RENAME)\b/gi) || []).length !== 1) return false;
  const alter = sql.match(/^ALTER\s+TABLE\s+`?([A-Za-z0-9_]+)`?\s+ADD\s+(?:COLUMN\s+)?`?([A-Za-z0-9_]+)`?/i);
  if (code === 1060 && alter) return Number(runtimeMetadataScalar('column_exists', [alter[1], alter[2]])) === 1;
  const index = sql.match(/^ALTER\s+TABLE\s+`?([A-Za-z0-9_]+)`?\s+ADD\s+(?:UNIQUE\s+)?(?:INDEX|KEY)\s+`?([A-Za-z0-9_]+)`?/i);
  return code === 1061 && index && Number(runtimeMetadataScalar('index_exists', [index[1], index[2]])) === 1;
}
function executeLocked(text, migration) {
  const statements = mysql.splitScript(text);
  if (!Array.isArray(statements)) runtimeFail('split_script_contract');
  for (const item of statements) {
    const sql = typeof item === 'string' ? item : item && item.statement;
    if (typeof sql !== 'string') runtimeFail('split_script_contract');
    if (!runtimeSafeSql(sql)) continue;
    try { session.runSql(sql); } catch (error) {
      const code = Number(error && error.code);
      if (!migration || (code !== 1060 && code !== 1061) || !duplicateAddIsPresent(sql, code)) runtimeFail('locked_sql_execution_failed');
    }
  }
  return statements.length;
}
function runtimeAssert(names, createdByThisRun) {
  if (Number(runtimeMetadataScalar('target_table_count', [])) !== 99) runtimeFail('final_table_count_mismatch');
  for (const table of POSITIVE) if (!runtimeExists(table)) runtimeFail('positive_assertion_missing');
  for (const table of NEGATIVE) if (runtimeExists(table)) runtimeFail('negative_assertion_failed');
  const rows = session.runSql('SELECT version FROM la_migration_history ORDER BY version').fetchAll();
  if (!rows || rows.length !== names.length || rows.some((row, index) => row[0] !== names[index])) runtimeFail('history_mismatch');
  return { status: 'runtime_passed', code: 'runtime_passed', stage: 'complete', migration_count: names.length, baseline_tables: 74, final_tables: 99, createdByThisRun: createdByThisRun };
}
function runFixedRuntimeProbe() {
  if (arguments.length !== 0) runtimeFail('runtime_arguments_not_allowed');
  const uri = session.uri || '';
  if (!/^mysql:\/\/(?:[^@:\/]+(?::[^@\/]*)?@)?127\.0\.0\.1:3306(?:\/[^?]*)?$/.test(uri) || Number(runtimeScalar('SELECT @@port')) !== 3306) runtimeFail('session_not_allowed');
  const targetQuoted = mysql.quoteIdentifier(TARGET); let createdByThisRun = false;
  try {
    if (Number(runtimeMetadataScalar('target_preflight_exists', [])) !== 0) return { status: 'blocked', code: 'target_exists_stop', stage: 'preflight', migration_count: 0, createdByThisRun: false };
    session.runSql('CREATE DATABASE ' + targetQuoted); createdByThisRun = true; session.runSql('USE ' + targetQuoted);
    const like = prepareMigrationSql(runtimeRead(LIKE), 'la_');
    const jxc = prepareMigrationSql(runtimeRead(JXC), 'la_');
    if (runtimeSha256(like) !== EXPECTED[LIKE] || runtimeSha256(jxc) !== EXPECTED[JXC]) runtimeFail('baseline_hash_mismatch');
    executeLocked(like, false); executeLocked(jxc, false);
    if (Number(runtimeMetadataScalar('target_table_count', [])) !== 74) runtimeFail('baseline_table_count_mismatch');
    const names = Object.keys(EXPECTED_MIGRATION_HASHES).sort();
  if (names.length !== 29) runtimeFail('migration_manifest_mismatch');
    let migrationStatements = 0;
    for (let index = 0; index < names.length; index++) {
      const text = prepareMigrationSql(runtimeRead('database/migrations/' + names[index]), 'la_');
      if (runtimeSha256(text) !== EXPECTED_MIGRATION_HASHES[names[index]]) runtimeFail('migration_hash_mismatch');
      let existingDefaultNamedCategoryId = 0;
      let existingPlatformDefaultNamedCategoryId = 0;
      if (names[index] === DEFAULT_GOODS_CATEGORY_MIGRATION) {
        session.runSql('ALTER TABLE la_tenant_goodscat DROP INDEX uk_tenant_default_goodscat');
        session.runSql('ALTER TABLE la_tenant_goodscat DROP COLUMN is_default');
        if (Number(runtimeMetadataScalar('column_exists', ['la_tenant_goodscat', 'is_default'])) !== 0
          || Number(runtimeMetadataScalar('index_exists', ['la_tenant_goodscat', 'uk_tenant_default_goodscat'])) !== 0
        ) runtimeFail('default_goods_category_legacy_fixture_failed');
        session.runSql("INSERT INTO la_tenant (id, sn, name, avatar, tactics, domain_alias_enable, create_time) VALUES (900001, 'probe-default-existing', 'Probe Existing', '', 0, 1, UNIX_TIMESTAMP()), (900002, 'probe-default-missing', 'Probe Missing', '', 0, 1, UNIX_TIMESTAMP())");
        session.runSql("INSERT INTO la_tenant_goodscat (tenant_id, name, sort, is_show, create_time, update_time) VALUES (900001, '默认分类', 99, 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP())");
        const existingRow = session.runSql("SELECT id FROM la_tenant_goodscat WHERE tenant_id = 900001 AND name = '默认分类'").fetchOne();
        existingDefaultNamedCategoryId = existingRow ? Number(existingRow[0]) : 0;
      }
      if (names[index] === PLATFORM_DEFAULT_GOODS_CATEGORY_MIGRATION) {
        session.runSql("INSERT INTO la_tenant_goodscat (tenant_id, name, sort, is_show, is_default, create_time, update_time, delete_time) VALUES (0, '默认分类', 91, 1, 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP(), UNIX_TIMESTAMP())");
        const existingPlatformRow = session.runSql("SELECT id FROM la_tenant_goodscat WHERE tenant_id = 0 AND name = '默认分类' ORDER BY id ASC LIMIT 1").fetchOne();
        existingPlatformDefaultNamedCategoryId = existingPlatformRow ? Number(existingPlatformRow[0]) : 0;
      }
      migrationStatements += executeLocked(text, true);
      if (names[index] === DEFAULT_GOODS_CATEGORY_MIGRATION) {
        if (Number(runtimeMetadataScalar('column_exists', ['la_tenant_goodscat', 'is_default'])) !== 1
          || Number(runtimeMetadataScalar('index_exists', ['la_tenant_goodscat', 'uk_tenant_default_goodscat'])) !== 1
        ) runtimeFail('default_goods_category_schema_migration_failed');
        executeLocked(text, true);
        const categoryRows = session.runSql("SELECT tenant_id, MIN(id), COUNT(*), SUM(is_default = 1), SUM(name = '默认分类' AND is_show = 0) FROM la_tenant_goodscat WHERE tenant_id IN (900001, 900002) AND delete_time IS NULL GROUP BY tenant_id ORDER BY tenant_id").fetchAll();
        if (!categoryRows || categoryRows.length !== 2
          || Number(categoryRows[0][0]) !== 900001
          || Number(categoryRows[0][1]) !== existingDefaultNamedCategoryId
          || Number(categoryRows[0][2]) !== 1
          || Number(categoryRows[0][3]) !== 1
          || Number(categoryRows[0][4]) !== 1
          || Number(categoryRows[1][0]) !== 900002
          || Number(categoryRows[1][2]) !== 1
          || Number(categoryRows[1][3]) !== 1
          || Number(categoryRows[1][4]) !== 1
        ) runtimeFail('default_goods_category_idempotency_failed');
      }
      if (names[index] === PLATFORM_DEFAULT_GOODS_CATEGORY_MIGRATION) {
        executeLocked(text, true);
        const platformCategoryRow = session.runSql("SELECT MIN(id), COUNT(*), SUM(is_default = 1), SUM(name = '默认分类' AND is_show = 0) FROM la_tenant_goodscat WHERE tenant_id = 0 AND delete_time IS NULL").fetchOne();
        if (!platformCategoryRow
          || Number(platformCategoryRow[0]) !== existingPlatformDefaultNamedCategoryId
          || Number(platformCategoryRow[1]) !== 1
          || Number(platformCategoryRow[2]) !== 1
          || Number(platformCategoryRow[3]) !== 1
        ) runtimeFail('platform_default_goods_category_idempotency_failed');
      }
      if (index >= 2) { const first = index === 2 ? 0 : index; const last = index === 2 ? 2 : index; for (let history = first; history <= last; history++) session.runSql('INSERT INTO la_migration_history (version) VALUES (?)', [names[history]]); }
    }
  if (migrationStatements !== 215) runtimeFail('statement_count_mismatch');
    return runtimeAssert(names, createdByThisRun);
  } finally {
    if (createdByThisRun) {
      try { session.runSql('DROP DATABASE ' + targetQuoted); if (Number(runtimeMetadataScalar('target_cleanup_exists', [])) !== 0) runtimeFail('cleanup_failed'); }
      catch (ignored) { return { status: 'blocked', code: 'cleanup_failed', stage: 'cleanup', migration_count: 0, createdByThisRun: true }; }
    }
  }
}

Object.defineProperty(runStaticProbe, 'fixedTarget', { value: TARGET, enumerable: false, writable: false, configurable: false });
module.exports = { prepareMigrationSql, runStaticProbe, runFixedRuntimeProbe };
