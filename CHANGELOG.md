# Changelog

## Unreleased — initial extraction

Extract Patterns, Technical and TickerManipulation into `trademinator/indicators`. Depend on `trademinator/bcmath` rather than copying its trait or functions. Add explicit precision policies/contexts, per-call overrides, a plain-PHP calculator and normalization function. Remove exchange-global precision, process-wide scale changes, presentation truncation and global debug output. Preserve the CCXT OHLCV normalization boundary and retained-slice utilities.

Include a native regression runner, syntax checks, examples, native CI configuration and migration documentation. Precision-related and defensive behavior changes are listed in `docs/MIGRATION.md`; validation limits are listed in `docs/VALIDATION.md`. No release tag has been created by this archive.
