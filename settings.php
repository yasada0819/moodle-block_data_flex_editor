<?php
/**
 * フィールド役割の設定ページ（JSなし・素のHTMLフォーム）
 */
require_once('../../config.php');
require_once($CFG->dirroot . '/blocks/data_flex_editor/locallib.php');

$instanceid = required_param('instanceid', PARAM_INT);
$courseid   = required_param('courseid',   PARAM_INT);

$course  = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
$context = context_course::instance($courseid);

require_login($course);
require_capability('block/data_flex_editor:manage', context_block::instance($instanceid));

$PAGE->set_url('/blocks/data_flex_editor/settings.php', [
    'instanceid' => $instanceid,
    'courseid'   => $courseid,
]);
$PAGE->set_context($context);
$PAGE->set_course($course);
$PAGE->set_title(get_string('fieldsettings', 'block_data_flex_editor'));
$PAGE->set_heading($course->fullname);

// ブロック設定を取得（このコースに置かれた本プラグインのブロックであることも確認）
list($blockinstance, $blockcfg) = block_data_flex_editor_get_instance($instanceid, $courseid);

$dataid = isset($blockcfg->dataid) ? (int)$blockcfg->dataid : 0;

// フィールド一覧取得
$fields = [];
if ($dataid) {
    $fields = array_values(block_data_flex_editor_fields_for_ajax($dataid));
}
$fieldnames = array_column($fields, 'name');

// POST 処理（保存）
if ($_SERVER['REQUEST_METHOD'] === 'POST' && confirm_sesskey()) {
    // フィールド名は日本語を含むため、optional_param_array（キーを PARAM_ALPHA 等で落とす）は使わず
    // $_POST を直接参照し、キー・値を個別にサニタイズしたうえで実在フィールド名と照合する。
    $display = isset($_POST['display']) && is_array($_POST['display']) ? $_POST['display'] : [];
    $order   = isset($_POST['seqsort']) && is_array($_POST['seqsort']) ? $_POST['seqsort'] : [];

    $pickfield = function(string $key) use ($fieldnames): string {
        $v = isset($_POST[$key]) ? clean_param((string)$_POST[$key], PARAM_TEXT) : '';
        return in_array($v, $fieldnames, true) ? $v : '';
    };

    $fixed = $move = [];
    $seqsort = [];
    foreach ($fieldnames as $fname) {
        $role = isset($display[$fname]) ? clean_param((string)$display[$fname], PARAM_ALPHA) : 'none';
        if ($role === 'fixed') {
            $fixed[] = $fname;
        } else if ($role === 'move') {
            $move[] = $fname;
        }
        $rank = isset($order[$fname]) ? clean_param((string)$order[$fname], PARAM_INT) : 0;
        if ($rank > 0) {
            $seqsort[$fname] = $rank;
        }
    }
    // 連番ソートキーは順位の昇順（同順位はフィールド定義順）
    uksort($seqsort, function($a, $b) use ($seqsort, $fieldnames) {
        return [$seqsort[$a], array_search($a, $fieldnames)]
           <=> [$seqsort[$b], array_search($b, $fieldnames)];
    });

    $newcfg = clone $blockcfg;
    $newcfg->fixed_fields    = implode(',', $fixed);
    $newcfg->move_fields     = implode(',', $move);
    $newcfg->sort_field      = $pickfield('sort_field');
    $newcfg->seq_field       = $pickfield('seq_field');
    $newcfg->group_field     = $pickfield('group_field');
    $newcfg->group_seq_field = $pickfield('group_seq_field');
    $newcfg->seq_sort_fields = implode(',', array_keys($seqsort));

    $blockinstance->configdata = base64_encode(serialize($newcfg));
    $DB->update_record('block_instances', $blockinstance);

    redirect(
        new moodle_url('/blocks/data_flex_editor/settings.php', [
            'instanceid' => $instanceid,
            'courseid'   => $courseid,
        ]),
        get_string('settings_saved', 'block_data_flex_editor'),
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

// 現在の設定を解析
$sp = function(string $s): array {
    return array_values(array_filter(array_map('trim', explode(',', $s))));
};
$exFixed   = $sp($blockcfg->fixed_fields    ?? '');
$exMove    = $sp($blockcfg->move_fields     ?? '');
$exSeqSort = $sp($blockcfg->seq_sort_fields ?? '');
$hasExisting = count($exFixed) > 0 || count($exMove) > 0;

$cur = [
    'sort_field'      => trim($blockcfg->sort_field      ?? ''),
    'seq_field'       => trim($blockcfg->seq_field       ?? ''),
    'group_field'     => trim($blockcfg->group_field     ?? ''),
    'group_seq_field' => trim($blockcfg->group_seq_field ?? ''),
];

/**
 * 表示上の役割（固定列／移動列／使用しない）を決める
 */
function dfe_get_display(string $fname, string $ftype, array $exFixed, array $exMove,
        array $special, bool $hasExisting): string {
    if ($hasExisting) {
        if (in_array($fname, $exFixed, true)) { return 'fixed'; }
        if (in_array($fname, $exMove, true))  { return 'move'; }
        return 'none';
    }
    // 初回デフォルト：連番系は非表示、textarea は移動列、それ以外は固定列
    if (in_array($fname, $special, true)) { return 'none'; }
    return $ftype === 'textarea' ? 'move' : 'fixed';
}

$displayLabels = [
    'fixed' => get_string('role_fixed', 'block_data_flex_editor'),
    'move'  => get_string('role_move',  'block_data_flex_editor'),
    'none'  => get_string('role_none',  'block_data_flex_editor'),
];
$specialLabels = [
    'sort_field'      => [get_string('role_sort',     'block_data_flex_editor'), get_string('role_sort_help',     'block_data_flex_editor')],
    'seq_field'       => [get_string('role_seq',      'block_data_flex_editor'), get_string('role_seq_help',      'block_data_flex_editor')],
    'group_field'     => [get_string('role_group',    'block_data_flex_editor'), get_string('role_group_help',    'block_data_flex_editor')],
    'group_seq_field' => [get_string('role_groupseq', 'block_data_flex_editor'), get_string('role_groupseq_help', 'block_data_flex_editor')],
];

$backUrl = new moodle_url('/course/view.php', ['id' => $courseid]);
$saveUrl = new moodle_url('/blocks/data_flex_editor/settings.php', [
    'instanceid' => $instanceid,
    'courseid'   => $courseid,
]);

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('fieldsettings', 'block_data_flex_editor'));

echo html_writer::link($backUrl, '← ' . get_string('back_to_course', 'block_data_flex_editor'),
    ['class' => 'btn btn-secondary btn-sm mb-3']);

if (!$dataid) {
    echo $OUTPUT->notification(get_string('notconfigured', 'block_data_flex_editor'), 'warning');
    echo $OUTPUT->footer();
    exit;
}

if (empty($fields)) {
    echo $OUTPUT->notification(get_string('nofields', 'block_data_flex_editor'), 'warning');
    echo $OUTPUT->footer();
    exit;
}

if ($cur['group_seq_field'] !== '' && $cur['group_field'] === '') {
    echo $OUTPUT->notification(get_string('groupseq_needs_group', 'block_data_flex_editor'), 'warning');
}
if ($cur['seq_field'] !== '' && $cur['seq_field'] === $cur['group_seq_field']) {
    echo $OUTPUT->notification(get_string('seq_same_field', 'block_data_flex_editor'), 'warning');
}
?>

<form method="post" action="<?php echo $saveUrl->out(); ?>">
  <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">

  <h4 class="mt-2"><?php echo get_string('section_display', 'block_data_flex_editor'); ?></h4>
  <table class="table table-bordered table-sm" style="max-width:760px;">
    <thead class="thead-dark">
      <tr>
        <th><?php echo get_string('fieldname', 'block_data_flex_editor'); ?></th>
        <th><?php echo get_string('fieldtype', 'block_data_flex_editor'); ?></th>
        <th><?php echo get_string('fieldrole', 'block_data_flex_editor'); ?></th>
        <th><?php echo get_string('role_seqsort', 'block_data_flex_editor'); ?></th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($fields as $f):
          $fname = $f['name'];
          $ftype = $f['type'];
          $disp  = dfe_get_display($fname, $ftype, $exFixed, $exMove,
                       array_filter(array_values($cur)), $hasExisting);
          $rank  = array_search($fname, $exSeqSort, true);
          $rank  = ($rank === false) ? '' : $rank + 1;
      ?>
      <tr>
        <td><strong><?php echo s($fname); ?></strong></td>
        <td><code><?php echo s($ftype); ?></code></td>
        <td>
          <select name="display[<?php echo s($fname); ?>]" class="custom-select custom-select-sm">
            <?php foreach ($displayLabels as $val => $label): ?>
            <option value="<?php echo $val; ?>" <?php echo ($disp === $val) ? 'selected' : ''; ?>>
              <?php echo s($label); ?>
            </option>
            <?php endforeach; ?>
          </select>
        </td>
        <td style="width:9em;">
          <input type="number" min="0" step="1" class="form-control form-control-sm"
                 name="seqsort[<?php echo s($fname); ?>]" value="<?php echo s($rank); ?>"
                 placeholder="—">
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <p class="text-muted small"><?php echo get_string('role_seqsort_help', 'block_data_flex_editor'); ?></p>

  <h4 class="mt-4"><?php echo get_string('section_special', 'block_data_flex_editor'); ?></h4>
  <table class="table table-bordered table-sm" style="max-width:760px;">
    <tbody>
      <?php foreach ($specialLabels as $key => [$label, $help]): ?>
      <tr>
        <th style="width:16em;"><?php echo s($label); ?></th>
        <td>
          <select name="<?php echo $key; ?>" class="custom-select custom-select-sm">
            <option value=""><?php echo get_string('role_none', 'block_data_flex_editor'); ?></option>
            <?php foreach ($fieldnames as $fn): ?>
            <option value="<?php echo s($fn); ?>" <?php echo ($cur[$key] === $fn) ? 'selected' : ''; ?>>
              <?php echo s($fn); ?>
            </option>
            <?php endforeach; ?>
          </select>
          <div class="text-muted small mt-1"><?php echo s($help); ?></div>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>

  <button type="submit" class="btn btn-primary">
    <?php echo get_string('save', 'block_data_flex_editor'); ?>
  </button>
</form>

<?php echo $OUTPUT->footer(); ?>
