<p align="center">
<a href="https://folivoro.com" target="_blank">
<img src="https://raw.githubusercontent.com/folivoro/art/refs/heads/main/sloth-logo.svg" alt="Sloth Logo" width="200" height="200" />
</a>
</p>
<p align="center">
<a href="https://packagist.org/packages/folvioro/climb"><img src="https://img.shields.io/packagist/dt/folvioro/climb" alt="Total Downloads"></a>
<a href="https://packagist.org/packages/folvioro/climb"><img src="https://img.shields.io/packagist/v/folvioro/climb" alt="Latest Stable Version"></a>
<a href="https://packagist.org/packages/folvioro/climb"><img src="https://img.shields.io/packagist/l/folvioro/climb" alt="License"></a>
</p>


# folivoro/climb 🧗

Modernizes [Sloth](https://github.com/folivoro/sloth) projects to the latest version.

## Installation

```bash
composer global require folivoro/climb
```

Make sure `~/.composer/vendor/bin` is in your `$PATH`:

```bash
export PATH="$PATH:$HOME/.composer/vendor/bin"
```

## Usage

From the root of your Sloth project:

```bash
climb
```

Explicitly target a version:

```bash
climb --to=2
```

## What it does

### v1 → v2

1. **UpdateComposerJson** — removes deprecated Sloth v1 scripts, normalizes `composer.json`
2. **UpdateComposerPackages** — swaps `sixmonkey/sloth` / `folivoro/sloth` for `folivoro/sloth:^2.0`, removes old Layotter packages, installs `folivoro/cecropia`, optionally installs `folivoro/layotter-bridge`
3. **MigrateConfigs** — installs a temporary MU-plugin to dump all `Configure::` values, migrates them to Laravel-style config files in `app/config/` and `theme/config/`
4. **MigrateViewExtensions** — converts legacy Twig filter/function registrations to `AbstractViewExtension` classes in `theme/Extensions/View/`
5. **MigrateTypedProperties** — removes typed property declarations from Model and Taxonomy classes via Rector
6. **MigrateBootstrap** — removes Sloth v1 bootstrapping from `bootstrap.php`, deletes obsolete `sloth.php` mu-plugin, cleans up `.gitignore`

## Prerequisites

- PHP ^8.4
- `composer` available on `$PATH`
- A Sloth v1 project with `installer-paths` configured in `composer.json`

## How MigrateConfigs works

climb installs a temporary MU-plugin (`legacy-config-dumper.php`) into your WordPress MU-plugin directory. On the next WordPress request it dumps all `Configure::read()` values to `climb-config.json` in your project root, then climb removes the plugin automatically.

climb reads `WP_HOME` from your `.env` and suggests the URL to open — but you can make the request however you like (browser, curl, Docker, Lando, etc.).

If `climb-config.json` already exists, the dump step is skipped.

## License

MIT
