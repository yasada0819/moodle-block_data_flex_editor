<?php
/**
 * AJAX endpoint: dataid を受け取り、フィールド一覧を JSON で返す
 * 呼び出し: ?action=get_fields&dataid=XXXX&sesskey=...
 */
define('AJAX_SCRIPT', true);
require_once('../../config.php');
require_once($CFG->dirroot . '/blocks/data_flex_editor/locallib.php');

require_sesskey();
require_login();

$dataid = required_param('dataid', PARAM_INT);

// dataid の存在確認
if (!$DB->record_exists('data', ['id' => $dataid])) {
    echo json_encode(['error' => 'invalid dataid']);
    die();
}

$fields = block_data_flex_editor_fields_for_ajax($dataid);
echo json_encode(['fields' => $fields]);
