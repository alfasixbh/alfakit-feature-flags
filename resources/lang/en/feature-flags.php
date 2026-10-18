<?php

declare(strict_types=1);

return [
    'navigation' => [
        'label' => 'Feature flags',
    ],

    'features' => [
        'title' => 'Feature flags',
        'subheading' => 'Every Pennant feature, who has it and how it is rolled out.',
        'subheading_unenforced' => 'Every Pennant feature and its stored values. Rules are not enforced (enforce_rules is off).',
        'tenant_subheading' => 'The features of :tenant.',
        'empty' => [
            'heading' => 'No features yet',
            'description' => 'Define features with Feature::define() or class-based features in app/Features, and they show up here.',
        ],
    ],

    'columns' => [
        'name' => 'Feature',
        'type' => 'Type',
        'status' => 'Status',
        'source' => 'Source',
        'value' => 'Value',
        'active' => 'Active',
        'active_tooltip' => 'Scopes with an active stored value',
        'inactive' => 'Inactive',
        'inactive_tooltip' => 'Scopes with an inactive stored value',
        'overrides' => 'Targeted',
        'overrides_tooltip' => 'Scopes with their own value',
        'last_changed' => 'Last changed',
        'never' => 'Never',
        'scope' => 'Scope',
        'updated_by' => 'Set by',
        'updated_at' => 'Updated',
        'code' => 'Application code',
    ],

    'types' => [
        'boolean' => 'On / off',
        'rich' => 'Rich value',
    ],

    'status' => [
        'on' => 'On for everyone',
        'off' => 'Off for everyone',
        'value' => 'Value for everyone',
        'rollout' => 'Rolling out',
        'rollout_percentage' => 'Rolling out to :percentage%',
        'killed' => 'Killed',
        'default' => 'Definition',
    ],

    'sources' => [
        'killed' => 'Kill switch',
        'before' => 'Feature class',
        'override' => 'Own value',
        'everyone' => 'Everyone',
        'rollout' => 'Rollout',
        'default' => 'Definition',
    ],

    'values' => [
        'on' => 'On',
        'off' => 'Off',
        'active' => 'Active',
        'custom' => 'Custom value',
    ],

    'scopes' => [
        'null' => 'Guests (no scope)',
        'global' => 'Global scope',
    ],

    'schedule' => [
        'activates' => 'Turns on :date',
        'deactivates' => 'Turns off :date',
    ],

    'fields' => [
        'value' => 'Value',
        'value_help' => 'Any JSON value: a string in quotes, a number, an array or an object. false turns the feature off.',
        'percentage' => 'Share of scopes',
        'percentage_help' => 'Each scope always lands in the same place, so raising the percentage only adds scopes. Scopes with their own value keep it.',
        'activate_at' => 'Activate for everyone at',
        'activate_at_help' => 'Leave empty to schedule no activation.',
        'activate_value' => 'Value to activate with',
        'deactivate_at' => 'Deactivate for everyone at',
        'deactivate_at_help' => 'Leave empty to schedule no deactivation.',
        'scopes' => 'Scopes',
        'scopes_help' => 'Search users, teams or any other kind of scope the panel offers.',
        'state' => 'Give them',
    ],

    'actions' => [
        'more' => 'More actions',
        'everyone' => 'Everyone',
        'manage' => [
            'label' => 'Manage',
        ],
        'activate' => [
            'label' => 'Activate for everyone',
            'modal_heading' => 'Activate :feature for everyone?',
            'modal_description' => 'Every scope gets the feature, including the ones that resolve it later. Scopes with their own value get it too.',
            'submit' => 'Activate',
            'notification' => ':feature is on for everyone',
        ],
        'deactivate' => [
            'label' => 'Deactivate for everyone',
            'modal_heading' => 'Deactivate :feature for everyone?',
            'modal_description' => 'Every scope loses the feature, including the ones that resolve it later. Scopes with their own value lose it too.',
            'submit' => 'Deactivate',
            'notification' => ':feature is off for everyone',
        ],
        'set_value' => [
            'label' => 'Set value',
            'modal_heading' => 'Set the value of :feature for everyone',
            'modal_description' => 'Every scope gets this value, including the ones that resolve it later.',
            'submit' => 'Save value',
            'notification' => 'The value of :feature was saved',
        ],
        'use_definition' => [
            'label' => 'Back to the definition',
            'modal_heading' => 'Stop forcing :feature?',
            'modal_description' => 'Scopes resolve the feature from their own value, their stored value or the feature\'s definition again. The kill switch and schedules stay.',
            'submit' => 'Use the definition',
            'notification' => ':feature follows its definition again',
        ],
        'rollout' => [
            'label' => 'Roll out',
            'modal_heading' => 'Roll out :feature',
            'modal_description' => 'Activate the feature for a share of the scopes, chosen by a stable hash. 0% turns it off for everyone without their own value.',
            'submit' => 'Save rollout',
            'notification' => ':feature is rolling out to :percentage%',
        ],
        'schedule' => [
            'label' => 'Schedule',
            'modal_heading' => 'Schedule :feature',
            'modal_description' => 'Turn the feature on or off for everyone at a given time. The scheduler applies it within a minute.',
            'submit' => 'Save schedule',
            'notification' => 'The schedule of :feature was saved',
            'cleared' => 'The schedule of :feature was cleared',
        ],
        'kill' => [
            'label' => 'Kill switch',
            'modal_heading' => 'Kill :feature?',
            'modal_description' => 'The feature is off for everyone right away, whatever its rules, rollout or own values say, until you restore it. Nothing else is changed.',
            'submit' => 'Kill the feature',
            'notification' => ':feature was killed',
        ],
        'restore' => [
            'label' => 'Restore',
            'modal_heading' => 'Restore :feature?',
            'modal_description' => 'The kill switch is lifted: scopes get the values they had before.',
            'submit' => 'Restore',
            'notification' => ':feature was restored',
        ],
        'purge' => [
            'label' => 'Purge stored values',
            'modal_heading' => 'Purge the stored values of :feature?',
            'modal_description' => 'Pennant forgets every stored value and own value of this feature, so each scope resolves it again. Rules, rollouts and the kill switch stay. This cannot be undone.',
            'submit' => 'Purge',
            'notification' => 'The stored values of :feature were purged',
        ],
        'reset' => [
            'label' => 'Reset',
            'modal_heading' => 'Reset :feature?',
            'modal_description' => 'Purges every stored value and own value and removes the kill switch, the value for everyone, the rollout and the schedule. The feature follows its definition again. This cannot be undone.',
            'submit' => 'Reset',
            'notification' => ':feature was reset',
        ],
        'activate_tenant' => [
            'label' => 'Turn on',
            'notification' => ':feature is on',
        ],
        'deactivate_tenant' => [
            'label' => 'Turn off',
            'modal_heading' => 'Turn :feature off?',
            'modal_description' => 'The feature is off for this tenant until you turn it on again.',
            'submit' => 'Turn off',
            'notification' => ':feature is off',
        ],
        'set_value_tenant' => [
            'label' => 'Set value',
            'modal_heading' => 'Set the value of :feature',
        ],
        'forget_tenant' => [
            'label' => 'Use the default',
            'modal_heading' => 'Use the default for :feature?',
            'modal_description' => 'This tenant\'s own value is removed and the feature follows the application\'s rules again.',
            'submit' => 'Use the default',
            'notification' => ':feature follows the default again',
        ],
    ],

    'details' => [
        'overview' => 'Overview',
        'class' => 'Class',
        'everyone_value' => 'Value for everyone',
        'tenant_value' => 'Value for :tenant',
        'killed_heading' => 'The kill switch is on',
        'killed_description' => 'This feature is off for everyone since :date. Restore it to bring the previous values back.',
        'unenforced_heading' => 'Rules are not enforced',
        'unenforced_description' => 'enforce_rules is off in config/alfakit-feature-flags.php: the kill switch, values for everyone that resolve later and rollouts have no effect on Feature::active().',
        'undefined_heading' => 'This feature is not defined',
        'undefined_description' => 'Pennant has values or the panel has rules for it, but no Feature::define() or feature class defines it (any more).',
    ],

    'targeting' => [
        'heading' => 'Targeting',
        'description' => 'Scopes with their own value. They win over the value for everyone and rollouts; only the kill switch beats them.',
        'current_tenant' => ':tenant (this tenant)',
        'empty' => [
            'heading' => 'No targeted scopes',
            'description' => 'Give single users, teams or tenants their own value.',
        ],
        'actions' => [
            'add' => 'Add scopes',
            'add_heading' => 'Target :feature',
            'add_description' => 'The scopes you choose get their own value.',
            'add_submit' => 'Save',
            'activate' => 'Turn on',
            'deactivate' => 'Turn off',
            'set_value' => 'Set value',
            'set_value_heading' => 'Set the value for :scope',
            'forget' => 'Remove',
            'forget_heading' => 'Remove :scope?',
            'forget_description' => 'The scope loses its own value and follows the feature\'s rules again.',
            'forget_selected' => 'Remove selected',
        ],
        'notifications' => [
            'added' => '{1} :count scope targeted|[2,*] :count scopes targeted',
            'updated' => 'Value saved',
            'forgotten' => 'Scope removed',
            'forgotten_many' => '{1} :count scope removed|[2,*] :count scopes removed',
        ],
    ],

    'stored' => [
        'heading' => 'Stored values',
        'description' => 'The values Pennant stored when scopes resolved the feature.',
        'empty' => 'Nothing stored yet',
    ],

    'audit' => [
        'heading' => 'Audit trail',
        'description' => 'Every change made to this feature: who, what and when.',
        'empty' => 'No changes yet',
        'system' => 'System',
        'view' => 'Details',
        'before' => 'Before',
        'after' => 'After',
        'activity' => ':action: :feature',
        'columns' => [
            'when' => 'When',
            'who' => 'Who',
            'what' => 'Change',
            'change' => 'Before → after',
            'source' => 'Made from',
        ],
        'sources' => [
            'panel' => 'The panel',
            'schedule' => 'The schedule',
            'code' => 'Application code',
        ],
        'snapshot' => [
            'everyone' => 'Everyone: :value',
            'scheduled' => 'scheduled',
            'stored' => ':active active, :inactive inactive, :overrides targeted',
        ],
        'actions' => [
            'activated_for_everyone' => 'Activated for everyone',
            'deactivated_for_everyone' => 'Deactivated for everyone',
            'value_set_for_everyone' => 'Value set for everyone',
            'definition_restored' => 'Back to the definition',
            'rollout_set' => 'Rollout changed',
            'killed' => 'Killed',
            'restored' => 'Restored',
            'scheduled' => 'Schedule changed',
            'purged' => 'Stored values purged',
            'reset' => 'Reset',
            'scope_activated' => 'Turned on for a scope',
            'scope_deactivated' => 'Turned off for a scope',
            'scope_value_set' => 'Value set for a scope',
            'scope_forgotten' => 'Own value removed',
        ],
    ],

    'entry' => [
        'empty' => 'No features',
        'activate' => 'Turn on',
        'deactivate' => 'Turn off',
        'set_value' => 'Set value',
        'set_value_heading' => 'Set the value of :feature',
        'forget' => 'Use the default',
        'forget_tooltip' => 'Remove this record\'s own value',
        'source_tooltip' => 'Where the value comes from',
    ],

    'validation' => [
        'scope_not_allowed' => 'You can\'t target one of the chosen scopes.',
    ],
];
