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

// ブロック設定を取得
$blockinstance = $DB->get_record('block_instances', ['id' => $instanceid], '*', MUST_EXIST);
$blockcfg = !empty($blockinstance->configdata)
    ? unserialize(base64_decode($blockinstance->configdata))
    : new stdClass();

$dataid = isset($blockcfg->dataid) ? (int)$blockcfg->dataid : 0;

// POST 処理（保存）
if ($_SERVER['REQUEST_METHOD'] === 'POST' && confirm_sesskey()) {
    // roles[フィールド名] = 役割 の形で送られてくる
    // optional_param_array は PARAM_ALPHA でキーを落とすため $_POST を直接参照する
    $roles = isset($_POST['roles']) && is_array($_POST['roles']) ? $_POST['roles'] : [];

    $fixed = $move = $sort = $seq = $group = $seqsort = [];
    foreach ($roles as $fname => $role) {
        $fname = clean_param($fname, PARAM_TEXT);
        $role  = clean_param($role,  PARAM_ALPHA);
        switch ($role) {
            case 'fixed':   $fixed[]   = $fname; break;
            case 'move':    $move[]    = $fname; break;
            case 'sort':    $sort[]    = $fname; break;
            case 'seq':     $seq[]     = $fname; break;
            case 'group':   $group[]   = $fname; break;
            case 'seqsort': $seqsort[] = $fname; break;
        }
    }

    $newcfg = clone $blockcfg;
    $newcfg->fixed_fields    = implode(',', $fixed);
    $newcfg->move_fields     = implode(',', $move);
    $newcfg->sort_field      = $sort[0]    ?? '';
    $newcfg->seq_field       = $seq[0]     ?? '';
    $newcfg->group_field     = $group[0]   ?? '';
    $newcfg->seq_sort_fields = implode(',', $seqsort);

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

// フィールド一覧取得
$fields = [];
if ($dataid) {
    $fields = array_values(block_data_flex_editor_fields_for_ajax($dataid));
}

// 現在の設定を解析
$sp = function(string $s): array {
    return array_values(array_filter(array_map('trim', explode(',', $s))));
};
$exFixed   = $sp($blockcfg->fixed_fields    ?? '');
$exMove    = $sp($blockcfg->move_fields     ?? '');
$exSort    = $sp($blockcfg->sort_field      ?? '');
$exSeq     = $sp($blockcfg->seq_field       ?? '');
$exGroup   = $sp($blockcfg->group_field     ?? '');
$exSeqSort = $sp($blockcfg->seq_sort_fields ?? '');
$hasExisting = count($exFixed) > 0 || count($exMove) > 0;

function dfe_get_role(
    string $fname, string $ftype,
    array $exFixed, array $exMove, array $exSort,
    array $exSeq, array $exGroup, array $exSeqSort,
    bool $hasExisting
): string {
    if ($hasExisting) {
        if (in_array($fname, $exFixed))   { return 'fixed'; }
        if (in_array($fname, $exMove))    { return 'move'; }
        if (in_array($fname, $exSort))    { return 'sort'; }
        if (in_array($fname, $exSeq))     { return 'seq'; }
        if (in_array($fname, $exGroup))   { return 'group'; }
        if (in_array($fname, $exSeqSort)) { return 'seqsort'; }
        return 'none';
    }
    // 初回デフォルト：型から推定
    if ($ftype === 'textarea') { return 'move'; }
    return 'fixed';
}

$roleLabels = [
    'fixed'   => get_string('role_fixed',   'block_data_flex_editor'),
    'move'    => get_string('role_move',    'block_data_flex_editor'),
    'sort'    => get_string('role_sort',    'block_data_flex_editor'),
    'seq'     => get_string('role_seq',     'block_data_flex_editor'),
    'group'   => get_string('role_group',   'block_data_flex_editor'),
    'seqsort' => get_string('role_seqsort', 'block_data_flex_editor'),
    'none'    => get_string('role_none',    'block_data_flex_editor'),
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
?>

<form method="post" action="<?php echo $saveUrl->out(); ?>">
  <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">

  <table class="table table-bordered table-sm" style="max-width:620px;">
    <thead class="thead-dark">
      <tr>
        <th><?php echo get_string('fieldname', 'block_data_flex_editor'); ?></th>
        <th><?php echo get_string('fieldtype', 'block_data_flex_editor'); ?></th>
        <th><?php echo get_string('fieldrole', 'block_data_flex_editor'); ?></th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($fields as $f):
          $fname = $f['name'];
          $ftype = $f['type'];
          $role  = dfe_get_role($fname, $ftype,
                       $exFixed, $exMove, $exSort, $exSeq, $exGroup, $exSeqSort, $hasExisting);
      ?>
      <tr>
        <td><strong><?php echo s($fname); ?></strong></td>
        <td><code><?php echo s($ftype); ?></code></td>
        <td>
          <select name="roles[<?php echo s($fname); ?>]" class="custom-select custom-select-sm">
            <?php foreach ($roleLabels as $val => $label): ?>
            <option value="<?php echo $val; ?>" <?php echo ($role === $val) ? 'selected' : ''; ?>>
              <?php echo s($label); ?>
            </option>
            <?php endforeach; ?>
          </select>
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
