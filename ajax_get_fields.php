<?php
/**
 * AJAX endpoint: dataid を受け取り、フィールド一覧を JSON で返す
 * 呼び出し: ?courseid=XX&instanceid=XX&dataid=XXXX&sesskey=...
 * フィールド役割の設定権限（:manage）を持つユーザーのみ利用可
 */
define('AJAX_SCRIPT', true);
require_once('../../config.php');
require_once($CFG->dirroot . '/blocks/data_flex_editor/locallib.php');

require_sesskey();

$courseid   = required_param('courseid',   PARAM_INT);
$instanceid = required_param('instanceid', PARAM_INT);
$dataid     = required_param('dataid',     PARAM_INT);

$course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
require_login($course);
block_data_flex_editor_get_instance($instanceid, $courseid);
require_capability('block/data_flex_editor:manage', context_block::instance($instanceid));

// dataid の存在確認
if (!$DB->record_exists('data', ['id' => $dataid])) {
    echo json_encode(['error' => 'invalid dataid']);
    die();
}

$fields = block_data_flex_editor_fields_for_ajax($dataid);
echo json_encode(['fields' => $fields]);
