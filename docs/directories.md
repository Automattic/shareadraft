# Repository structure

| Path                       | Purpose                                                                                       |
| -------------------------- | --------------------------------------------------------------------------------------------- |
| `shareadraft.php`  | Plugin entry file: header, guards, constants, autoloader, start. Kept intentionally small.    |
| `inc/`                     | The integration's WordPress runtime code (autoloaded at runtime by `inc/autoload.php`).       |
| `src/`                     | Block-editor JavaScript, compiled into `build/` by `npm run build`.                           |
| `languages/`               | Translation catalogues shipped with the plugin (regenerate with `composer i18n`).             |
| `fixtures/`                | Mock runtime configs for local development and tests (see `fixtures/README.md`).              |
| `tests/unit/`              | Fast PHPUnit unit tests (pure PHP, no WordPress; run through `composer test:unit`).           |
| `tests/integration/`       | PHPUnit integration tests (boot WordPress; run through `composer test:integration`).          |
| `tests/e2e/`               | Playwright end-to-end tests (run through `composer test:e2e`; needs a running `vip dev-env`). |
| `vip-manifest.yaml`        | The handoff manifest VIP registers and loads the integration from (see `docs/manifest.md`).   |
| `vip-manifest.schema.json` | JSON Schema the manifest is validated against.                                                |
| `docs/`                    | Operational docs, including the required `vip-integration.md` and `manifest.md`.              |
| `AGENTS.md`                | Orientation for AI coding agents working in this repo.                                        |
| `CONTRIBUTING.md`          | Tooling, tests, and local environments for contributors.                                      |
| `.wordpress-org/`          | WordPress.org plugin directory assets: screenshots, and later the banner and icon.            |
| `.wpvip/`                  | VIP local development environment config and plugin loader.                                   |
| `.devcontainer/`           | GitHub Codespaces configuration.                                                              |
| `.github/workflows/`       | CI: unit tests, integration tests, e2e, linting, static analysis, CodeQL, dependency review, and the tagged release build. |
| `LICENSE`                  | GPLv2 licence text, shipped in the release ZIP.                                               |
| `.distignore`              | What to leave out of the release ZIP (see "Cutting a release" in `docs/vip-integration.md`). |
