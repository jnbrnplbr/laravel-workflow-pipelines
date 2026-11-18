# Repository Guidelines

## Project Structure & Module Organization

This workspace currently contains `prompt.md`, the implementation specification for a reusable Laravel workflow pipelines package. The package itself is not built yet. When implementing the specification, organize it as a Composer package: PHP code in `src/` (contracts, models, services, actions, HTTP, events, and console commands), config in `config/`, publishable migrations in `database/migrations/`, Vue/TypeScript assets in `resources/js/`, translations in `resources/lang/en/`, and Pest tests in `tests/`. Keep package documentation such as `README.md`, `CHANGELOG.md`, and `UPGRADE.md` at the root.

## Build, Test, and Development Commands

No build or test commands are configured in this workspace yet. Once the package is scaffolded, follow its specification: use Composer scripts for PHP tooling and Pest, and document the exact commands in `README.md`. The target checks are Pest 4 with Orchestra Testbench, Larastan level 5, and Laravel Pint. Do not assume a command is available until it appears in `composer.json`.

## Coding Style & Naming Conventions

Follow the target stack in `prompt.md`: PHP 8.3+, Laravel 13, Vue 3 with `<script setup lang="ts">`, and Tailwind 4. Use Laravel Pint's Laravel preset for PHP formatting. Name PHP types in StudlyCase, methods and variables in camelCase, and database tables and columns in snake_case. Keep migrations additive after publication; never edit a migration that users may already have run. Avoid new runtime dependencies unless the package documentation explains their need.

## Testing Guidelines

Write Pest tests under `tests/`, grouped by the feature or package area they cover. Use Orchestra Testbench for Laravel integration tests and fixture models for approvable transactions. Cover workflow state changes, authorization, notifications, and migration behavior. Run the checks exposed by Composer before submitting changes; no coverage threshold is specified yet.

## AI Implementation References

Use [Laravel Boost](https://laravel.com/docs/boost) as the framework reference once the Laravel package is scaffolded. Its version-matched skills and documentation API should guide Laravel 13, Pest 4, Inertia 3, Tailwind 4, and Wayfinder patterns; check current docs when a framework detail is uncertain. Boost is not installed in this prompt-only workspace, so do not assume `boost:install` is available before a Laravel application/package harness exists. Preserve the repository-specific requirements in `prompt.md` when Boost guidance is general.

Use [obra/superpowers](https://github.com/obra/superpowers) as the implementation workflow reference: clarify the design against `prompt.md`, plan the package in reviewable phases, use test-driven development for behavior, debug from evidence, request a review, and verify checks before reporting completion. Apply only relevant skills; the specification remains the source of truth for package behavior and compatibility.

## Commit & Pull Request Guidelines

This workspace has no usable Git history from which to infer a commit convention. Use concise, imperative commit subjects (for example, `Add workflow resolver`) and keep changes focused. Pull requests should explain behavior and compatibility impacts, link related issues when available, list validation commands and results, and include screenshots for changed UI pages.

## Security & Configuration

Keep host-specific table names, prefixes, and integration settings in the published package config. Do not commit credentials or application secrets. Validate migration changes against existing data and report conflicts clearly rather than silently rewriting records.
