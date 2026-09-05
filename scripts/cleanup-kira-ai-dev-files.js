/**
 * One-shot cleanup of leftover dev-only files inside the Kira AI plugin folder
 * on the production host.
 *
 * Why: FTP-Deploy-Action applies `exclude` to BOTH sides of its diff, so an
 * excluded path is never seen as "removed locally" and never deleted from the
 * server. Those leftovers must be removed once, explicitly.
 *
 * Safety: all operations are confined to PLUGIN_DIR; the run aborts if that
 * folder is missing; kira-ai.php is on a hard deny-list; DRY_RUN=1 only prints.
 *
 * Usage:
 *   FTP_SERVER=... FTP_USERNAME=... FTP_PASSWORD=... node scripts/cleanup-kira-ai-dev-files.js
 *   DRY_RUN=1 ... same ...            # print plan only
 *   node scripts/cleanup-kira-ai-dev-files.js --self-test   # local FTP server test
 *
 * Deps: npm install basic-ftp   (same library FTP-Deploy-Action uses)
 */

"use strict";

const fs = require("fs");
const os = require("os");
const path = require("path");

const PLUGIN_DIR = "wp-content/plugins/kira-ai 2";

// Relative to PLUGIN_DIR. Exact names only, no globs.
const TARGETS = [
  "tests",
  "phpunit.xml.dist",
  "composer.json",
  ".DS_Store",
  "assets/.DS_Store",
  "includes/.DS_Store",
];

// Never removable, even if mistakenly added to TARGETS.
const DENY = new Set(["kira-ai.php", "index.php", "wp-config.php", "."]);

const DRY_RUN = process.env.DRY_RUN === "1";

function log(msg) {
  process.stdout.write(msg + "\n");
}

async function exists(client, rel) {
  const parent = path.posix.dirname(rel);
  const base = path.posix.basename(rel);
  try {
    const listing = await client.list(parent === "." ? "" : parent);
    return listing.some((e) => e.name === base);
  } catch {
    return false;
  }
}

const { FileType } = require("basic-ftp");

async function isDir(client, rel) {
  const parent = path.posix.dirname(rel);
  const base = path.posix.basename(rel);
  const listing = await client.list(parent === "." ? "" : parent);
  const entry = listing.find((e) => e.name === base);
  // FileType.Directory === 2 (FileType.File === 1) - never hardcode this.
  return !!entry && entry.type === FileType.Directory;
}


async function run(client) {
  // Every target lives INSIDE the plugin folder, so prefix it once here.
  // Without this, targets would resolve against the FTP root.
  const targets = TARGETS.map((t) => PLUGIN_DIR + "/" + t);

  // Guard: refuse to operate unless the plugin folder is really there.
  if (!(await exists(client, PLUGIN_DIR))) {
    throw new Error(`Refusing to run: "${PLUGIN_DIR}" not found on the server.`);
  }
  log(`Target dir : ${PLUGIN_DIR}/`);
  log(`Mode       : ${DRY_RUN ? "DRY RUN (no deletions)" : "LIVE"}`);
  log("");

  let failures = 0;
  for (const target of targets) {
    const shown = target.slice(PLUGIN_DIR.length + 1);
    if (DENY.has(shown)) {
      log(`  DENIED   ${shown.padEnd(22)} (protected)`);
      continue;
    }
    if (!(await exists(client, target))) {
      log(`  skip     ${shown.padEnd(22)} (not present)`);
      continue;
    }
    if (DRY_RUN) {
      log(`  would delete  ${shown}`);
      continue;
    }
    try {
      if (await isDir(client, target)) await removeTree(client, target);
      else await client.remove(target);

      if (await exists(client, target)) {
        log(`  FAILED   ${shown.padEnd(22)} (still present)`);
        failures++;
      } else {
        log(`  deleted  ${shown}`);
      }
    } catch (err) {
      log(`  ERROR    ${shown.padEnd(22)} ${err.message}`);
      failures++;
    }
  }
  log("");
  if (failures > 0) throw new Error(`${failures} target(s) could not be removed.`);
  log(DRY_RUN ? "Dry run complete." : "Cleanup finished.");
}

async function removeTree(client, rel) {
  const listing = await client.list(rel);
  for (const item of listing) {
    if (item.name === "." || item.name === "..") continue;
    const child = rel + "/" + item.name;
    if (item.type === FileType.Directory) await removeTree(client, child);
    else await client.remove(child);
  }
  await client.remove(rel);
}


/** Minimal in-memory FTP client so the logic is verifiable without a server. */
function stubClient(root) {
  const toAbs = (rel) => path.join(root, rel === "" ? "" : rel);
  const typeOf = (abs) =>
    fs.existsSync(abs) && fs.statSync(abs).isDirectory()
      ? FileType.Directory
      : FileType.File;
  return {
    async list(rel) {
      const abs = toAbs(rel || ".");
      if (!fs.existsSync(abs)) throw new Error("no such path " + rel);
      return fs.readdirSync(abs).map((name) => ({
        name,
        type: typeOf(path.join(abs, name)),
      }));
    },
    async remove(rel) {
      const abs = toAbs(rel);
      if (!fs.existsSync(abs)) throw new Error("missing " + rel);
      if (fs.statSync(abs).isDirectory()) fs.rmSync(abs, { recursive: true });
      else fs.unlinkSync(abs);
    },
  };
}

function report(pluginRoot) {
  const leftovers = TARGETS.filter((t) => fs.existsSync(path.join(pluginRoot, t)));
  const kept = {
    "kira-ai.php": fs.existsSync(path.join(pluginRoot, "kira-ai.php")),
    "includes/kira-ai-dashboard.php": fs.existsSync(
      path.join(pluginRoot, "includes/kira-ai-dashboard.php")
    ),
    "assets/css/kira-ai-admin.css": fs.existsSync(
      path.join(pluginRoot, "assets/css/kira-ai-admin.css")
    ),
  };
  log("");
  log("=== SELF-TEST RESULT ===");
  log(`targets still present : ${leftovers.length ? leftovers.join(", ") : "none"}`);
  for (const [k, v] of Object.entries(kept)) log(`${k.padEnd(30)}: ${v ? "preserved" : "MISSING"}`);
  const pass = leftovers.length === 0 && Object.values(kept).every(Boolean);
  log(pass ? "PASS" : "FAIL");
  if (!pass) process.exitCode = 1;
  return pass;
}

async function stubTest(pluginRoot) {
  await run(stubClient(pluginRoot));
  const ok = report(pluginRoot);
  fs.rmSync(path.dirname(path.dirname(path.dirname(pluginRoot))), {
    recursive: true,
    force: true,
  });
  return ok;
}

async function main() {
  if (process.argv.includes("--self-test")) {
    const tmp = fs.mkdtempSync(path.join(os.tmpdir(), "kiraftp-"));
    const pluginRoot = path.join(tmp, PLUGIN_DIR);
    for (const f of [
      "kira-ai.php", "phpunit.xml.dist", "composer.json", ".DS_Store",
      "assets/.DS_Store", "assets/css/kira-ai-admin.css",
      "includes/kira-ai-dashboard.php", "tests/README.md", "tests/bootstrap.php",
    ]) {
      const dest = path.join(pluginRoot, f);
      fs.mkdirSync(path.dirname(dest), { recursive: true });
      fs.writeFileSync(dest, "x");
    }
    // run() resolves PLUGIN_DIR relative to the FTP root, so the stub must be
    // rooted at `tmp`, not at the plugin folder itself.
    await run(stubClient(tmp));
    const ok = report(pluginRoot);
    fs.rmSync(tmp, { recursive: true, force: true });
    return ok;
  }

  const { Client } = require("basic-ftp");
  const host = process.env.FTP_SERVER;
  const user = process.env.FTP_USERNAME;
  const pass = process.env.FTP_PASSWORD;
  if (!host || !user || !pass) {
    throw new Error("FTP_SERVER, FTP_USERNAME and FTP_PASSWORD must be set.");
  }
  const client = new Client();
  try {
    await client.access({ host, user, password: pass, secure: false });
    await run(client);
  } finally {
    client.close();
  }
}

if (require.main === module) {
  main().catch((err) => {
    log("ERROR: " + err.message);
    process.exitCode = 1;
  });
}

