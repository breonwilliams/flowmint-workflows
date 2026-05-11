# FlowMint Workflows — Release Process

**This is the canonical release procedure.** Follow every step in order. If you're an AI assistant (Claude Code, Cowork, etc.) asked to "create a release" or "tag a new version" for this plugin, this document is your source of truth.

---

## Distribution model

FlowMint Workflows ships via **GitHub Releases** (not the WordPress.org plugin directory). FlowMint does **not** currently bundle a GitHub auto-updater — users install and update manually by downloading the ZIP from the GitHub release page and uploading via WP Admin → Plugins → Add New → Upload Plugin.

If you add an auto-updater in a future release, copy FRE's `FRE_GitHub_Updater` pattern and update this section.

---

## Hard dependency on Form Runtime Engine

FlowMint Workflows requires **Form Runtime Engine 1.6.0+** to be active. The plugin listens to `fre_submission_complete` (FRE's post-submission action) and refuses to initialize if FRE is missing or out-of-date. Bumping `FMW_REQUIRED_FRE_VERSION` in `flowmint-workflows.php` is a breaking change — coordinate with FRE's release cadence.

---

## Version-stamp locations

Every release must update the version number in **every** location below.

| File | Line / location | Format |
|------|----------------|--------|
| `flowmint-workflows.php` | Header `Version:` comment (~line 6) | `Version: 0.6.0` |
| `flowmint-workflows.php` | `FMW_VERSION` constant (~line 25) | `define( 'FMW_VERSION', '0.6.0' );` |
| `readme.txt` | `Stable tag:` (~line 7) | `Stable tag: 0.6.0` |
| `readme.txt` | `== Upgrade Notice ==` section | Add an entry for the new version |
| `CHANGELOG.md` | Move `[Unreleased]` content under a new heading | `## [0.6.0] — 2026-05-11` |
| Git tag | After commit | `v0.6.0` (with `v` prefix) |

> If schema-affecting changes were made: also bump `FMW_DB_VERSION` in `flowmint-workflows.php` (separate from the plugin version) to trigger the migration on next load.

---

## Pre-release checklist

- [ ] All code changes are committed and pushed to `main`
- [ ] `CHANGELOG.md` has a populated `[Unreleased]` section (move it under the new version heading during release)
- [ ] All five version-stamp locations updated to the new version
- [ ] `readme.txt` `Stable tag` matches the plugin header version exactly (Plugin Check will fail if not)
- [ ] If `FMW_REQUIRED_FRE_VERSION` was bumped: verify the target FRE version is actually released
- [ ] Plugin Check run locally returns clean (no errors)
- [ ] `composer install --no-dev` runs without errors (the build script needs Composer on PATH)
- [ ] No PHP errors in `debug.log` on a smoke-test install
- [ ] Spot-check the MCP connector page (FlowMint Workflows → Claude Connection) loads cleanly
- [ ] If step types changed: run any relevant unit tests (`composer test`)

---

## Release commands (copy/paste-ready)

Replace `0.6.0` with the actual version. Run from the plugin root.

```bash
# 1. Verify version-stamp consistency before tagging.
grep -E "^ \* Version:|FMW_VERSION|Stable tag:" flowmint-workflows.php readme.txt

# 2. Commit the version bump.
git add -A
git commit -m "Release v0.6.0"

# 3. Tag with v prefix.
git tag v0.6.0

# 4. Push branch and tag to GitHub.
git push origin main --tags

# 5. Build the release ZIP.
#    The build script:
#      - Excludes dev artifacts (tests/, build/, release/, *.zip, .phpunit.result.cache, bin/build-release.sh)
#      - Runs `composer install --no-dev --optimize-autoloader` inside the staged copy
#      - Zips the staged copy with the correct folder name for WordPress
./bin/build-release.sh

# 6. Create the GitHub Release and attach the ZIP.
gh release create v0.6.0 build/flowmint-workflows.zip \
    --title "v0.6.0" \
    --notes-file CHANGELOG.md
```

Alternative `--notes` form (focused summary instead of dumping the full CHANGELOG):

```bash
gh release create v0.6.0 build/flowmint-workflows.zip \
    --title "v0.6.0" \
    --notes "New step types + Plugin Check compliance. See CHANGELOG.md for details."
```

**Critical:** Always attach `build/flowmint-workflows.zip` (the build script's output). The ZIP contains a pre-slimmed `vendor/` with production-only Composer dependencies (Google API client, Action Scheduler). Without running the build script, vendor/ may include dev dependencies that inflate the ZIP by ~150 MB.

---

## Post-release verification

1. Open the GitHub release page. Confirm the ZIP asset is attached.
2. On a test WordPress site **that already has Form Runtime Engine ≥ 1.6.0 active**, install the new FlowMint ZIP via **Plugins → Add New → Upload Plugin → Replace current with uploaded**.
3. Activate without errors. Confirm there's no "FlowMint Workflows requires Form Runtime Engine" admin notice.
4. Visit **FlowMint Workflows → Run History** — page should load (empty list is fine).
5. Visit **FlowMint Workflows → Claude Connection** — page should load with the kill-switch toggle and Generate Connection button.
6. If credentials are configured: test one credential via the connector REST API to confirm the upgrade didn't break the encryption boundary.

---

## CHANGELOG format

Follow [Keep a Changelog](https://keepachangelog.com/en/1.1.0/):

```markdown
## [Unreleased]

## [0.6.0] — 2026-05-11

### Added
- New feature

### Changed
- Modified behavior

### Fixed
- Bug fix
```

Allowed sections: `Added`, `Changed`, `Deprecated`, `Removed`, `Fixed`, `Security`.

---

## Version numbering

[Semantic Versioning](https://semver.org/) — `MAJOR.MINOR.PATCH`:

- **MAJOR** — breaking changes (workflow JSON shape changes, step type signature changes, REST API shape changes, removed hooks)
- **MINOR** — new features, backward compatible (new step types, new REST endpoints, new MCP tools)
- **PATCH** — bug fixes only, no API changes

Note: FlowMint is currently in the `0.x` series. Bumping to `1.0.0` is reserved for the first version Breon considers customer-ready for general distribution.

---

## Plugin Check expectations

The current Plugin Check report should return **fully clean** — FlowMint has no known false-positives or accepted warnings after the 2026-05-11 compliance pass. If any error appears, fix it before tagging.

---

## Emergency rollback

If a bad release ships:

1. **Immediately tag a fix**: bump the patch version, fix the issue, and follow the full release flow above with the new version.
2. **Don't delete the bad tag** — manual installers may already have the bad version. Roll forward, don't roll back.
3. Note the regression in `CHANGELOG.md` under the new version's `Fixed` section.
4. If the bad release affected stored workflow data: write a one-off migration step into the next version's activation hook so installers get a clean recovery path.

---

**Last updated:** 2026-05-11
