// Windows opt-in check: FABREMENT_CONNECTION_INTEGRATION=1 node tests/Integration/mcp-connection-instructions.cjs
// PHP, Codex and PowerShell must be on PATH, or set FABREMENT_TEST_PHP / CODEX / POWERSHELL.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const { spawnSync } = require('node:child_process');

if (process.platform !== 'win32' || process.env.FABREMENT_CONNECTION_INTEGRATION !== '1') {
  console.log('Set FABREMENT_CONNECTION_INTEGRATION=1 on Windows to run isolated CLI and mutation checks.');
  process.exit(0);
}

const root = path.resolve(__dirname, '../..');
const temp = fs.mkdtempSync(path.join(os.tmpdir(), 'fabrement-connection-test-'));
const php = process.env.FABREMENT_TEST_PHP || 'php';
const codex = process.env.FABREMENT_TEST_CODEX || 'codex.exe';
const powershell = process.env.FABREMENT_TEST_POWERSHELL || 'powershell.exe';
const configPath = 'src/Modules/Mcp/McpConnectionConfig.php';
const pagePath = 'src/Page/BuilderBlock/BuilderBlockCredentialsPage.php';
const originals = new Map([configPath, pagePath].map(file => [file, fs.readFileSync(path.join(root, file), 'utf8')]));

function run(binary, args, options = {}) {
  const result = spawnSync(binary, args, { cwd: root, encoding: 'utf8', timeout: 60000, ...options });
  if (result.error) throw result.error;
  return result;
}

function success(result) {
  assert.equal(result.status, 0, result.stderr + result.stdout);
  return result.stdout;
}

function phpPath(file) {
  return JSON.stringify(file.replaceAll('\\', '/'));
}

try {
  success(run(php, ['vendor/phpunit/phpunit/phpunit', '--do-not-cache-result', '--filter', 'McpConnectionConfigTest|McpCredentialsPageTest']));
  console.log('PASS: unmodified production classes pass the baseline tests');

  const mutations = [
    {
      label: 'wrong server prefix', file: configPath,
      from: "return 'fabrement-' .", to: "return 'wp-blocks-' .",
      filter: 'testApplicationPasswordSetupUsesTheSameSiteNameInEveryRepresentation',
    },
    {
      label: 'single-quoted Windows token', file: configPath,
      from: String.raw`\"{$token}\"`, to: "'{$token}'",
      filter: 'testCodexWindowsCliCommandQuotesUrlForTerminalSetup',
    },
    {
      label: 'shared token variable across URLs', file: configPath,
      from: "strtoupper( substr( hash( 'sha256', $url ), 0, 12 ) )", to: "'FIXED'",
      filter: 'testTokenVariablesDoNotCollideForSitesWithTheSameTitle',
    },
    {
      label: 'site name lost in real password-generation handler', file: pagePath,
      from: "$plaintext, get_bloginfo( 'name' )", to: "$plaintext, ''",
      filter: 'testGenerationNamesKeyAndProducesCorrectOneTimePayload',
    },
  ];
  for (const [index, mutation] of mutations.entries()) {
    const source = originals.get(mutation.file);
    assert.ok(source.includes(mutation.from), `Mutation target missing: ${mutation.label}`);
    const mutant = path.join(temp, `mutant-${index}.php`);
    const bootstrap = path.join(temp, `bootstrap-${index}.php`);
    fs.writeFileSync(mutant, source.replace(mutation.from, mutation.to));
    fs.writeFileSync(bootstrap, `<?php\nrequire ${phpPath(path.join(root, 'tests/bootstrap.php'))};\nrequire ${phpPath(mutant)};\n`);
    success(run(php, ['-n', '-l', mutant]));
    const result = run(php, ['vendor/phpunit/phpunit/phpunit', '--do-not-cache-result', '--bootstrap', bootstrap, '--filter', mutation.filter]);
    assert.equal(result.status, 1, `Mutation did not produce an assertion failure: ${mutation.label}\n${result.stdout}${result.stderr}`);
    assert.match(result.stdout, /FAILURES!/);
    assert.match(result.stdout, /Failures: [1-9]/);
    assert.doesNotMatch(result.stdout, /ERRORS!|Errors: [1-9]/);
    console.log(`PASS: tests reject ${mutation.label} (assertion failure, not a PHP error)`);
  }

  const fixture = success(run(php, ['-n'], { input: `<?php
require '${configPath}';
use Fabrement\\Modules\\Mcp\\McpConnectionConfig as C;
$url = "http://127.0.0.1:9/a'b?rest_route=/fabrement/v1/mcp&x=1";
$title = 'Example Studio';
$setup = C::connection('codex', $url, 'synthetic-user', 'SYNTHETIC_PASSWORD', $title);
$commands = [$setup['cli_command']];
foreach (['codex', 'claude'] as $client) {
    $commands[] = C::oauth_connection($client, $url, $title)['cli_command'];
}
$commands[] = C::connection('claude', $url, 'synthetic-user', 'SYNTHETIC_PASSWORD', $title)['cli_command'];
echo json_encode(['setup' => $setup, 'commands' => $commands], JSON_THROW_ON_ERROR);
` }));
  const { setup, commands } = JSON.parse(fixture);
  const commandsFile = path.join(temp, 'commands.json');
  fs.writeFileSync(commandsFile, JSON.stringify(commands));
  const parserScript = path.join(temp, 'parse.ps1');
  fs.writeFileSync(parserScript, `
param([string]$CommandsFile)
$ErrorActionPreference = "Stop"
foreach ($command in (Get-Content -LiteralPath $CommandsFile -Raw | ConvertFrom-Json)) {
  $tokens = $null
  $parseErrors = $null
  $ast = [System.Management.Automation.Language.Parser]::ParseInput($command, [ref]$tokens, [ref]$parseErrors)
  if ($parseErrors.Count) { throw "PowerShell parsing failed" }
  $singleQuoted = @($ast.FindAll({ param($n) $n -is [System.Management.Automation.Language.StringConstantExpressionAst] -and $n.StringConstantType -eq "SingleQuoted" }, $true))
  if ($singleQuoted.Count) { throw "Single-quoted argument found" }
}
`);
  success(run(powershell, ['-NoProfile', '-NonInteractive', '-File', parserScript, commandsFile]));
  console.log('PASS: real PowerShell parser accepts all four generated Windows command variants');

  const home = path.join(temp, 'codex-home');
  fs.mkdirSync(home);
  const env = { ...process.env, CODEX_HOME: home };
  // Use a reserved loopback port and a synthetic bearer token: no real server or OAuth login.
  success(run(codex, ['mcp', 'add', 'unrelated-test-server', '--url', 'http://127.0.0.1:9/other', '--bearer-token-env-var', 'SYNTHETIC_OTHER_TOKEN'], { env }));
  const lines = setup.cli_command.split('\n');
  assert.equal(lines.length, 3);
  assert.match(lines[1], /^\[Environment\]::SetEnvironmentVariable\(/);
  const variable = lines[2].match(/--bearer-token-env-var ([A-Z0-9_]+)$/)[1];
  // User-level registry persistence is intentionally NOT executed. Only the process assignment
  // and the generated add command run, with an isolated CODEX_HOME and the real Codex executable.
  const cliScript = path.join(temp, 'add.ps1');
  fs.writeFileSync(cliScript, `
$ErrorActionPreference = "Stop"
function codex { & $env:FABREMENT_TEST_CODEX @args; if ($LASTEXITCODE -ne 0) { throw "Codex command failed" } }
${lines[0]}
${lines[2]}
if ($env:${variable} -ne "${Buffer.from('synthetic-user:SYNTHETIC_PASSWORD').toString('base64')}") { throw "Token value changed" }
`);
  success(run(powershell, ['-NoProfile', '-NonInteractive', '-File', cliScript], { env: { ...env, FABREMENT_TEST_CODEX: codex } }));
  const saved = JSON.parse(success(run(codex, ['mcp', 'get', setup.name, '--json'], { env })));
  assert.equal(saved.name, 'fabrement-example-studio');
  assert.equal(saved.transport.url, 'http://127.0.0.1:9/a%27b?rest_route=/fabrement/v1/mcp&x=1');
  assert.equal(saved.transport.bearer_token_env_var, variable);
  const other = JSON.parse(success(run(codex, ['mcp', 'get', 'unrelated-test-server', '--json'], { env })));
  assert.equal(other.transport.url, 'http://127.0.0.1:9/other');
  console.log('PASS: real Codex CLI saves the generated name, complete URL and token variable; unrelated server preserved');
  console.log('LIMIT: no live WordPress/provider login or Windows User environment persistence was tested');
} finally {
  for (const [file, original] of originals) {
    assert.equal(fs.readFileSync(path.join(root, file), 'utf8'), original, `Production file changed: ${file}`);
  }
  const parent = path.resolve(os.tmpdir());
  assert.equal(path.dirname(path.resolve(temp)), parent);
  assert.ok(path.basename(temp).startsWith('fabrement-connection-test-'));
  fs.rmSync(temp, { recursive: true, force: true });
}
