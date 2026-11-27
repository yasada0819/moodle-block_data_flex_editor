<?php
defined('MOODLE_INTERNAL') || die();

$capabilities = [
    'block/data_flex_editor:edit' => [
        'captype'      => 'write',
        'contextlevel' => CONTEXT_BLOCK,
        'archetypes'   => [
            'editingteacher' => CAP_ALLOW,
            'teacher'        => CAP_ALLOW,
            'manager'        => CAP_ALLOW,
        ],
    ],
    // 他のユーザーのエントリを代理編集する（ユーザー選択画面から対象を選ぶ）。
    // 実際に使えるのは、対象データベースの mod/data:manageentries も持っている場合のみ。
    'block/data_flex_editor:editothers' => [
        'riskbitmask'  => RISK_DATALOSS | RISK_PERSONAL,
        'captype'      => 'write',
        'contextlevel' => CONTEXT_BLOCK,
        'archetypes'   => [
            'editingteacher' => CAP_ALLOW,
            'manager'        => CAP_ALLOW,
        ],
    ],
    'block/data_flex_editor:manage' => [
        'captype'      => 'write',
        'contextlevel' => CONTEXT_BLOCK,
        'archetypes'   => [
            'editingteacher' => CAP_ALLOW,
            'manager'        => CAP_ALLOW,
        ],
    ],
];
