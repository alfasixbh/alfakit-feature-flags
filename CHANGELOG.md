# Changelog

All notable changes to `alfasix/alfakit-feature-flags` are documented in this file.

## Unreleased

- Initial release: a feature flag control centre for Laravel Pennant. The Features page lists every defined, class-based, discovered and stored-only feature with its description (`#[Description]`, `description()` or `descriptions()`), type (on/off or rich value), status, active/inactive stored counts, targeted scopes and last change. Per feature: activate, deactivate or set a validated JSON value for everyone (including scopes that resolve later), back to the definition, stable percentage rollouts with a slider, scheduled activation/deactivation (`alfakit-feature-flags:apply-schedules`, scheduled every minute), a kill switch, purge and reset, all confirmed and authorised. Targeting gives users, teams or tenants their own value through configurable scope resolvers; explicit `Feature::for()->activate()` calls in code are tracked too. Every change lands in an audit trail (and the activity log when installed). `FeatureFlagsEntry` shows and toggles features on a record's page, `FeatureFlagged` hides pages, resources and actions while a feature is inactive, and tenant panels manage the current tenant only. Deny by default (`manageFeatureFlags` gate).
