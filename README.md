# spora-plugin-zernio

Spora plugin: zernio. The full reference (install, configuration,
per-tool parameters, development) has moved to the docs site:

**[docs.spora-ai.com/develop/plugins/reference/zernio](https://docs.spora-ai.com/develop/plugins/reference/zernio)**

See the docs site for the canonical reference. The README previously
in this repo has been migrated there.

## Translations

This plugin ships a German catalogue at [`locales/de.json`](locales/de.json),
declared through `translationsPaths()` in `src/ZernioPlugin.php`. The host
merges it under core's own catalogue, so a German install reads the plugin's
operator copy in German.

The **key is the English source string**, byte for byte — editing the English
in the source changes the key, so the old entry stops matching and the new
English has no entry. The gate is this repository's own
`tests/Unit/LocaleCatalogTest.php`: it fails on an empty translation, on a
translation identical to its key, and on a key the source no longer produces.

**Translated**

- the seven tool display names — `Zernio Accounts`, `Zernio Post`,
  `Zernio Queue`, `Zernio Analytics`, `Zernio Media`, `Zernio Webhooks`,
  `Zernio Validate`. `Zernio Webhooks` becomes `Zernio-Webhooks`: the German
  compound hyphen, not a rewording.
- the three `#[ToolSetting]` descriptions, which all seven tools share
- the `plugin.json` description

**Never translated** — these strings are read by the model, so the operator
sees exactly what the model sees:

- `#[Tool].description`
- `#[ToolOperation].description`
- `#[ToolParameter].description`
- every `#[ToolSetting].label` and its option labels, including the labels of
  settings that carry `exposeToLlm: true`

See the [plugin author guide → Translations](https://docs.spora-ai.com/develop/plugins/author-guide/translations).
