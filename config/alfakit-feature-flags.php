<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Enforce the panel's rules
    |--------------------------------------------------------------------------
    |
    | Kill switches, "everyone" values, percentage rollouts and per-scope targeting set in
    | the panel apply to every `Feature::active()` check. The plugin decorates Pennant's
    | stores to do so (your definitions and stores are unchanged). Turn it off to manage
    | stored values only.
    |
    */

    'enforce_rules' => true,

    /*
    |--------------------------------------------------------------------------
    | Track explicit Pennant writes
    |--------------------------------------------------------------------------
    |
    | `Feature::for($user)->activate()` (and `deactivate()`, `forget()`, `purge()`) in your
    | code also become targeting entries, so explicit activations keep winning over a
    | rollout set later in the panel.
    |
    */

    'track_pennant_writes' => true,

    /*
    |--------------------------------------------------------------------------
    | Schedules
    |--------------------------------------------------------------------------
    |
    | Register `alfakit-feature-flags:apply-schedules` with Laravel's scheduler (every
    | minute). Set to false to schedule it yourself.
    |
    */

    'register_schedule' => true,

    /*
    |--------------------------------------------------------------------------
    | Database connection
    |--------------------------------------------------------------------------
    |
    | The connection of the plugin's tables (rules, targeting and audit trail). Null uses
    | the default connection.
    |
    */

    'connection' => null,

    /*
    |--------------------------------------------------------------------------
    | Descriptions
    |--------------------------------------------------------------------------
    |
    | Descriptions shown next to features that don't describe themselves with the
    | `#[Description]` attribute or a `description()` method: `'new-billing' => '…'`.
    |
    */

    'descriptions' => [],

];
