<?php
require_once('../../config.php');
require_once($CFG->dirroot . '/blocks/data_flex_editor/locallib.php');

$dataid     = required_param('dataid',     PARAM_INT);
$courseid   = required_param('courseid',   PARAM_INT);
$instanceid = required_param('instanceid', PARAM_INT);
$action     = optional_param('action', 'edit', PARAM_ALPHA);

$course  = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
$context = context_course::instance($courseid);

require_login($course);
require_capability('block/data_flex_editor:edit', context_block::instance($instanceid));

$PAGE->set_url('/blocks/data_flex_editor/editor.php', [
    'dataid'     => $dataid,
    'courseid'   => $courseid,
    'instanceid' => $instanceid,
]);
$PAGE->set_context($context);
$PAGE->set_course($course);
$PAGE->set_title(get_string('pluginname', 'block_data_flex_editor'));
$PAGE->set_heading($course->fullname);

// ブロック設定を取得（このコースに置かれた本プラグインのブロックであることも確認）
list($blockinstance, $blockcfg) = block_data_flex_editor_get_instance($instanceid, $courseid);

// URL の dataid がブロック設定の対象データベースと一致することを確認
// かつ、そのデータベースがこのコースのものであることを確認
if (empty($blockcfg->dataid) || (int)$blockcfg->dataid !== $dataid
        || !$DB->record_exists('data', ['id' => $dataid, 'course' => $courseid])) {
    throw new moodle_exception('invaliddataid', 'block_data_flex_editor');
}

$fixedFieldsCsv  = $blockcfg->fixed_fields    ?? '';
$moveFieldsCsv   = $blockcfg->move_fields     ?? '';
$sortField       = trim($blockcfg->sort_field      ?? '');
$seqField        = trim($blockcfg->seq_field       ?? '');
$groupField      = trim($blockcfg->group_field     ?? '');
$groupSeqField   = trim($blockcfg->group_seq_field ?? '');
$seqSortFieldsCsv = $blockcfg->seq_sort_fields ?? '';
$textformat      = isset($blockcfg->textformat) ? (int)$blockcfg->textformat : FORMAT_PLAIN;
$minrows         = isset($blockcfg->minrows)    ? (int)$blockcfg->minrows    : 2;

$fixedFields  = array_values(array_filter(array_map('trim', explode(',', $fixedFieldsCsv))));
$moveFields   = array_values(array_filter(array_map('trim', explode(',', $moveFieldsCsv))));
$seqSortFields = array_values(array_filter(array_map('trim', explode(',', $seqSortFieldsCsv))));
$allFields    = array_merge($fixedFields, $moveFields);

$renumberCfg = [
    'seqField'   => $seqField,
    'groupField'    => $groupField,
    'groupSeqField' => $groupSeqField,
    'sortFields' => $seqSortFields,
];

// フィールドメタ情報（型・選択肢）
$fmeta    = block_data_flex_editor_get_fields($dataid);

// 保存処理
if ($action === 'save' && confirm_sesskey()) {
    $payload = json_decode(required_param('updates', PARAM_RAW), true);
    if ($payload) {
        block_data_flex_editor_save_entries(
            $dataid, $payload, $USER->id,
            $fixedFields, $moveFields,
            $renumberCfg, $textformat
        );
    }
    redirect(
        new moodle_url('/blocks/data_flex_editor/editor.php', [
            'dataid'     => $dataid,
            'courseid'   => $courseid,
            'instanceid' => $instanceid,
        ]),
        get_string('saved', 'block_data_flex_editor'),
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

// 連番の予測表示に使うフィールド（表示列に含まれないものは extra として別に渡す）
$hasRenumber = !empty($seqField) || ($groupSeqField !== '' && $groupField !== '');
$seqRelated  = array_values(array_unique(array_filter(array_merge(
    $seqSortFields, [$groupField, $seqField, $groupSeqField]
), fn($f) => $f !== '' && isset($fmeta[$f]))));
$extraFields = array_values(array_diff($seqRelated, $allFields));

// エントリ取得
$entries = block_data_flex_editor_get_entries($dataid, $USER->id, $sortField,
    array_merge($allFields, $extraFields));

// menu型の選択肢をまとめて取得
$menuOptions = [];
foreach ($allFields as $fname) {
    if (isset($fmeta[$fname]) && $fmeta[$fname]->type === 'menu') {
        $menuOptions[$fname] = block_data_flex_editor_get_menu_options($dataid, $fname);
    }
}

// JS に渡す設定
$jsConfig = json_encode([
    'dataid'      => $dataid,
    'courseid'    => $courseid,
    'instanceid'  => $instanceid,
    'fixedFields' => $fixedFields,
    'moveFields'  => $moveFields,
    'sortField'   => $sortField,
    'seqField'    => $seqField,
    'hasRenumber' => $hasRenumber,
    'seq'         => [
        'seqField'      => isset($fmeta[$seqField]) ? $seqField : '',
        'groupField'    => isset($fmeta[$groupField]) ? $groupField : '',
        'groupSeqField' => ($groupField !== '' && isset($fmeta[$groupSeqField])) ? $groupSeqField : '',
        'sortFields'    => array_values(array_filter($seqSortFields, fn($f) => isset($fmeta[$f]))),
    ],
    'saveUrl'     => (new moodle_url('/blocks/data_flex_editor/editor.php'))->out(false),
    'sesskey'     => sesskey(),
    'minrows'     => $minrows,
    'menuOptions' => $menuOptions,
    'fieldTypes'  => array_map(fn($f) => $f->type, $fmeta),
    'strings'     => [
        'add'          => get_string('addrow',    'block_data_flex_editor'),
        'preview'      => get_string('preview',   'block_data_flex_editor'),
        'save'         => get_string('save',      'block_data_flex_editor'),
        'renumber'     => $seqSortFields
            ? get_string('renumber_by', 'block_data_flex_editor', implode('・', $seqSortFields))
            : get_string('renumber', 'block_data_flex_editor'),
        'renumberOn'   => get_string('renumber_on',  'block_data_flex_editor'),
        'renumberOff'  => get_string('renumber_off', 'block_data_flex_editor'),
        'showAll'      => get_string('showall',      'block_data_flex_editor'),
        'numChanged'   => get_string('numchanged',   'block_data_flex_editor'),
        'newRow'       => get_string('newrow',       'block_data_flex_editor'),
        'deleteCol'    => get_string('deletecol',    'block_data_flex_editor'),
        'modeInsert'   => get_string('modeinsert','block_data_flex_editor'),
        'modeSwap'     => get_string('modeswap',  'block_data_flex_editor'),
        'modeLabel'    => get_string('modelabel', 'block_data_flex_editor'),
        'previewTitle' => get_string('previewtitle','block_data_flex_editor'),
        'back'         => get_string('back',      'block_data_flex_editor'),
        'changed'      => get_string('changed',   'block_data_flex_editor'),
        'deleted'      => get_string('deleted',   'block_data_flex_editor'),
        'added'        => get_string('added',     'block_data_flex_editor'),
        'nochanges'    => get_string('nochanges', 'block_data_flex_editor'),
        'confirmSave'  => get_string('confirmsave','block_data_flex_editor'),
    ],
]);

// エントリを JS 用配列に変換
$entriesJs = [];
foreach ($entries as $eid => $entry) {
    $fields = $extra = [];
    foreach ($entry->fields as $fname => $val) {
        if (in_array($fname, $allFields, true)) {
            $fields[$fname] = $val;
        } else {
            $extra[$fname] = $val;
        }
    }
    $entriesJs[] = ['id' => (int)$eid, 'fields' => (object)$fields, 'extra' => (object)$extra];
}
$entriesJson = json_encode($entriesJs);

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('pluginname', 'block_data_flex_editor'));
?>

<style>
/* ===== モードバー ===== */
.dfe-mode-bar { margin-bottom: 1rem; display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
.dfe-mode-bar span { font-weight: bold; margin-right: 4px; }
.dfe-mode-btn { padding: 4px 16px; border: 2px solid #2E75B6; border-radius: 4px;
                background: #fff; color: #2E75B6; cursor: pointer; font-size: 0.9rem; }
.dfe-mode-btn.active { background: #2E75B6; color: #fff; }

/* ===== テーブル ===== */
.dfe-table { width: 100%; border-collapse: collapse; margin-top: 0.5rem; font-size: 0.9rem; }
.dfe-table th { background: #1F4E79; color: #fff; padding: 8px 6px; text-align: center; white-space: nowrap; }
.dfe-table td { border: 1px solid #ccc; padding: 5px 6px; vertical-align: middle; }
.dfe-table tr:nth-child(even) { background: #f5f8fc; }
.dfe-table tr.dragging       { opacity: 0.4; }
.dfe-table tr.dragover-insert { border-top: 3px solid #2E75B6; }
.dfe-table tr.dragover-swap   { outline: 2px solid #e67e22; background: #fef9f0 !important; }
.dfe-table tr.marked-delete   { background: #fdecea !important; opacity: 0.7; }

/* ===== ハンドル・ボタン ===== */
.drag-handle { cursor: grab; color: #999; font-size: 1.1rem; padding: 0 4px;
               user-select: none; text-align: center; }
.drag-handle:active { cursor: grabbing; }
.move-btn { border: none; background: none; cursor: pointer; color: #2E75B6;
            font-size: 0.95rem; padding: 2px 3px; line-height: 1; }
.move-btn:disabled { color: #ccc; cursor: default; }
.move-btn:hover:not(:disabled) { background: #e8f0fb; border-radius: 3px; }

/* ===== セル ===== */
.fixed-col { background: #eef4fb !important; }
.dfe-table tr.marked-delete .fixed-col { background: #f9d7d5 !important; }
.dfe-input    { width: 100%; border: 1px solid #ccc; border-radius: 4px;
                padding: 3px 5px; box-sizing: border-box; }
.dfe-select   { width: 100%; border: 1px solid #ccc; border-radius: 4px; padding: 3px 5px; }
.del-cb       { cursor: pointer; width: 16px; height: 16px; accent-color: #c0392b; }
.btn-add-row  { margin-top: 0.75rem; }

/* ===== プレビュー ===== */
#dfe-preview { display: none; margin-top: 1.5rem; }
#dfe-preview table { width: 100%; border-collapse: collapse; font-size: 0.88rem; }
#dfe-preview th { background: #2E75B6; color: #fff; padding: 7px; }
#dfe-preview td { border: 1px solid #ccc; padding: 5px 7px; }
#dfe-preview .changed { background: #fff3cd; }
#dfe-preview .deleted { background: #fdecea; }
.diff-old { color: #999; text-decoration: line-through; font-size: 0.85em; display: block; }
.diff-new { color: #155724; font-weight: bold; display: block; }
.num-col  { background: #f0f0f0 !important; text-align: center; font-family: monospace;
            white-space: nowrap; color: #333; }
.num-col.num-changed { background: #fff3cd !important; font-weight: bold; color: #8a5a00; }
.dfe-table th.num-th { background: #555; }
.dfe-renumber-bar { margin: 0.25rem 0 0.75rem; padding: 6px 10px; background: #f5f8fc;
                    border: 1px solid #d6e2f0; border-radius: 4px; display: inline-block; }
.dfe-renumber-bar input { margin-right: 4px; }
#dfe-preview .new-row { background: #e8f5e9; }
#dfe-preview .num-only { background: #fffbe8; }
#dfe-preview tr.deleted td { text-decoration: line-through; color: #999; }
.dfe-preview-status { margin: 0.25rem 0 0.75rem; font-size: 0.9rem; }
</style>

<div id="dfe-app">
  <!-- 編集ビュー -->
  <div id="dfe-edit-view">
    <div class="dfe-mode-bar">
      <span id="dfe-mode-label"></span>
      <button class="dfe-mode-btn active" id="dfe-mode-insert"></button>
      <button class="dfe-mode-btn"        id="dfe-mode-swap"></button>
    </div>
    <label class="dfe-renumber-bar" id="dfe-renumber-wrap" style="display:none;">
      <input type="checkbox" id="dfe-renumber-cb" checked> <span id="dfe-renumber-label"></span>
    </label>
    <div style="overflow-x:auto;">
      <table class="dfe-table" id="dfe-table">
        <thead id="dfe-thead"></thead>
        <tbody id="dfe-tbody"></tbody>
      </table>
    </div>
    <button class="btn btn-secondary btn-sm btn-add-row" id="dfe-add-row"></button>
    <div style="margin-top:1rem;">
      <button class="btn btn-primary" id="dfe-preview-btn"></button>
    </div>
  </div>

  <!-- プレビュービュー -->
  <div id="dfe-preview">
    <h4 id="dfe-preview-title"></h4>
    <div class="dfe-preview-status" id="dfe-preview-status"></div>
    <label style="display:block; margin-bottom:0.5rem;">
      <input type="checkbox" id="dfe-showall-cb"> <span id="dfe-showall-label"></span>
    </label>
    <div id="dfe-preview-content"></div>
    <div style="margin-top:1rem; display:flex; gap:8px; align-items:center; flex-wrap:wrap;">
      <button class="btn btn-secondary" id="dfe-back-btn"></button>
      <button class="btn btn-success" id="dfe-save-btn"></button>
    </div>
  </div>
</div>

<form id="dfe-save-form" method="post" style="display:none;">
  <input type="hidden" name="action"   value="save">
  <input type="hidden" name="dataid"   value="<?php echo $dataid; ?>">
  <input type="hidden" name="courseid" value="<?php echo $courseid; ?>">
  <input type="hidden" name="instanceid" value="<?php echo $instanceid; ?>">
  <input type="hidden" name="sesskey"  value="<?php echo sesskey(); ?>">
  <input type="hidden" name="updates"  id="dfe-updates-input">
</form>

<script>
(function() {
const CFG      = <?php echo $jsConfig; ?>;
const ENTRIES  = <?php echo $entriesJson; ?>;
const L        = CFG.strings;

// ===== 状態 =====
let dndMode = 'insert'; // 'insert' | 'swap'
let rows    = [];       // [{id, fields:{name:value}, isNew, markedDelete}]

// ===== 初期化 =====
function init() {
    rows = ENTRIES.map(e => ({
        id:           e.id,
        original:     JSON.parse(JSON.stringify(e.fields)),
        fields:       JSON.parse(JSON.stringify(e.fields)),
        extra:        e.extra || {},
        isNew:        false,
        markedDelete: false,
    }));

    // ラベル
    document.getElementById('dfe-mode-label').textContent   = L.modeLabel + '：';
    document.getElementById('dfe-mode-insert').textContent  = L.modeInsert;
    document.getElementById('dfe-mode-swap').textContent    = L.modeSwap;
    document.getElementById('dfe-add-row').textContent      = '＋ ' + L.add;
    document.getElementById('dfe-preview-btn').textContent  = L.preview;
    document.getElementById('dfe-preview-title').textContent = L.previewTitle;
    document.getElementById('dfe-back-btn').textContent     = L.back;
    document.getElementById('dfe-save-btn').textContent     = L.save;
    if (CFG.hasRenumber) {
        document.getElementById('dfe-renumber-wrap').style.display = '';
        document.getElementById('dfe-renumber-label').textContent = L.renumber;
    }
    document.getElementById('dfe-showall-label').textContent = L.showAll;

    renderHeader();
    renderBody();
    bindButtons();
}

// ===== テーブルヘッダ =====
function renderHeader() {
    const thead = document.getElementById('dfe-thead');
    let html = '<tr>'
        + '<th></th>'   // ドラッグハンドル
        + '<th>↕</th>'; // ↑↓ボタン
    numCols().forEach(f => {
        html += `<th class="num-th">${escHtml(f)}</th>`;
    });
    CFG.fixedFields.forEach(f => {
        html += `<th>${escHtml(f)}</th>`;
    });
    CFG.moveFields.forEach(f => {
        html += `<th style="background:#2a6099;">${escHtml(f)}</th>`;
    });
    html += `<th>${escHtml(L.deleteCol)}</th></tr>`;
    thead.innerHTML = html;
}

// ===== テーブルボディ =====
function renderBody() {
    const tbody = document.getElementById('dfe-tbody');
    tbody.innerHTML = '';
    rows.forEach((row, idx) => {
        tbody.appendChild(makeRow(row, idx));
    });
    updateNumbers();
}

function makeRow(row, idx) {
    const tr = document.createElement('tr');
    tr.dataset.idx = idx;
    if (row.markedDelete) tr.classList.add('marked-delete');

    // ドラッグハンドル（移動列がある場合のみ有効）
    const handleTd = document.createElement('td');
    if (CFG.moveFields.length > 0) {
        handleTd.innerHTML = '<span class="drag-handle" draggable="true">⠿</span>';
        const handle = handleTd.querySelector('.drag-handle');
        bindDrag(handle, tr, idx);
    }
    tr.appendChild(handleTd);

    // ↑↓ボタン
    const moveTd = document.createElement('td');
    moveTd.style.whiteSpace = 'nowrap';
    const upBtn  = makeBtn('▲', () => swapRows(idx, idx - 1));
    const downBtn = makeBtn('▼', () => swapRows(idx, idx + 1));
    upBtn.disabled   = idx === 0;
    downBtn.disabled = idx === rows.length - 1;
    upBtn.className  = 'move-btn';
    downBtn.className = 'move-btn';
    moveTd.appendChild(upBtn);
    moveTd.appendChild(downBtn);
    tr.appendChild(moveTd);

    // 連番（読み取り専用・予測表示）
    numCols().forEach(fname => {
        const td = document.createElement('td');
        td.className = 'num-col';
        td.dataset.numField = fname;
        tr.appendChild(td);
    });

    // 固定列
    CFG.fixedFields.forEach(fname => {
        const td = document.createElement('td');
        td.className = 'fixed-col';
        td.appendChild(makeFieldInput(row, fname, false));
        tr.appendChild(td);
    });

    // 移動列
    CFG.moveFields.forEach(fname => {
        const td = document.createElement('td');
        td.appendChild(makeFieldInput(row, fname, false));
        tr.appendChild(td);
    });

    // 削除チェックボックス
    const delTd = document.createElement('td');
    delTd.style.textAlign = 'center';
    const cb = document.createElement('input');
    cb.type = 'checkbox';
    cb.className = 'del-cb';
    cb.checked = row.markedDelete;
    cb.addEventListener('change', () => {
        row.markedDelete = cb.checked;
        renderBody();
    });
    delTd.appendChild(cb);
    tr.appendChild(delTd);

    return tr;
}

function makeFieldInput(row, fname, readonly) {
    const ftype = CFG.fieldTypes[fname] || 'text';
    const val   = row.fields[fname] ?? '';

    if (ftype === 'menu' && CFG.menuOptions[fname]) {
        const sel = document.createElement('select');
        sel.className = 'dfe-select';
        const opts = CFG.menuOptions[fname];
        const emptyOpt = document.createElement('option');
        emptyOpt.value = '';
        emptyOpt.textContent = '---';
        sel.appendChild(emptyOpt);
        opts.forEach(o => {
            const opt = document.createElement('option');
            opt.value = o;
            opt.textContent = o;
            if (o === val) opt.selected = true;
            sel.appendChild(opt);
        });
        sel.addEventListener('change', () => { row.fields[fname] = sel.value; onFieldChanged(fname); });
        return sel;
    }

    if (ftype === 'date') {
        const inp = document.createElement('input');
        inp.type  = 'date';
        inp.className = 'dfe-input';
        inp.value = val;
        inp.addEventListener('change', () => { row.fields[fname] = inp.value; onFieldChanged(fname); });
        return inp;
    }

    if (ftype === 'textarea') {
        const ta = document.createElement('textarea');
        ta.className = 'dfe-input';
        ta.rows = CFG.minrows;
        ta.value = val;
        ta.addEventListener('input', () => { row.fields[fname] = ta.value; });
        ta.addEventListener('change', () => onFieldChanged(fname));
        return ta;
    }

    // text / number / url / その他
    const inp = document.createElement('input');
    inp.type = (ftype === 'number') ? 'number' : 'text';
    inp.className = 'dfe-input';
    inp.value = val;
    inp.addEventListener('input', () => { row.fields[fname] = inp.value; });
    inp.addEventListener('change', () => onFieldChanged(fname));
    return inp;
}

// ===== 隣接スワップ =====
function swapRows(i, j) {
    if (j < 0 || j >= rows.length) return;
    // 移動列のみ入れ替え
    CFG.moveFields.forEach(fname => {
        [rows[i].fields[fname], rows[j].fields[fname]]
            = [rows[j].fields[fname], rows[i].fields[fname]];
    });
    renderBody();
}

// ===== ドラッグ&ドロップ =====
let dragIdx = null;

function bindDrag(handle, tr, idx) {
    handle.addEventListener('dragstart', e => {
        dragIdx = idx;
        tr.classList.add('dragging');
        e.dataTransfer.effectAllowed = 'move';
    });
    handle.addEventListener('dragend', () => {
        tr.classList.remove('dragging');
        document.querySelectorAll('.dfe-table tr').forEach(r => {
            r.classList.remove('dragover-insert', 'dragover-swap');
        });
        dragIdx = null;
    });
    tr.addEventListener('dragover', e => {
        e.preventDefault();
        document.querySelectorAll('.dfe-table tr').forEach(r => {
            r.classList.remove('dragover-insert', 'dragover-swap');
        });
        tr.classList.add(dndMode === 'insert' ? 'dragover-insert' : 'dragover-swap');
    });
    tr.addEventListener('drop', e => {
        e.preventDefault();
        const toIdx = parseInt(tr.dataset.idx);
        if (dragIdx === null || dragIdx === toIdx) return;
        applyDrop(dragIdx, toIdx);
    });
}

function applyDrop(fromIdx, toIdx) {
    if (dndMode === 'swap') {
        CFG.moveFields.forEach(fname => {
            [rows[fromIdx].fields[fname], rows[toIdx].fields[fname]]
                = [rows[toIdx].fields[fname], rows[fromIdx].fields[fname]];
        });
    } else {
        // 挿入（押し出し）
        const moved = {};
        CFG.moveFields.forEach(fname => {
            moved[fname] = rows[fromIdx].fields[fname];
        });
        if (fromIdx < toIdx) {
            for (let i = fromIdx; i < toIdx; i++) {
                CFG.moveFields.forEach(fname => {
                    rows[i].fields[fname] = rows[i + 1].fields[fname];
                });
            }
        } else {
            for (let i = fromIdx; i > toIdx; i--) {
                CFG.moveFields.forEach(fname => {
                    rows[i].fields[fname] = rows[i - 1].fields[fname];
                });
            }
        }
        CFG.moveFields.forEach(fname => {
            rows[toIdx].fields[fname] = moved[fname];
        });
    }
    renderBody();
}

// ===== 行追加 =====
function addRow() {
    const emptyFields = {};
    CFG.fixedFields.concat(CFG.moveFields).forEach(f => { emptyFields[f] = ''; });
    rows.push({
        id:           null,
        original:     null,
        fields:       emptyFields,
        isNew:        true,
        markedDelete: false,
    });
    renderBody();
}

// ===== 連番の予測 =====
// サーバー側 block_data_flex_editor_renumber() と同じ規則で計算する：
//   ・削除予定の行は除外
//   ・連番ソートキー順（date/number は数値、空は末尾。両方数値なら数値比較、それ以外は自然順）
//   ・同順位は record id 順（新規行は既存行の後ろ、追加順）
//   ・区分はそのままの値でグループ化（前後の空白は除去）
function numCols() {
    const out = [];
    if (CFG.seq.seqField)      out.push(CFG.seq.seqField);
    if (CFG.seq.groupSeqField) out.push(CFG.seq.groupSeqField);
    return out;
}

function renumberOn() {
    const cb = document.getElementById('dfe-renumber-cb');
    return CFG.hasRenumber && cb && cb.checked;
}

function valueOf(row, fname) {
    if (Object.prototype.hasOwnProperty.call(row.fields, fname)) return row.fields[fname] ?? '';
    return (row.extra && row.extra[fname]) ?? '';
}

function currentNum(row, fname) {
    if (row.isNew) return '';
    if (row.original && Object.prototype.hasOwnProperty.call(row.original, fname)) {
        return row.original[fname] ?? '';
    }
    return (row.extra && row.extra[fname]) ?? '';
}

function isNumeric(v) { return v !== '' && !isNaN(v) && !isNaN(parseFloat(v)); }

function cmpVal(va, vb, ftype) {
    if (va === '' && vb !== '') return 1;
    if (vb === '' && va !== '') return -1;
    if (va === '' && vb === '') return 0;
    if (ftype === 'number' || (isNumeric(va) && isNumeric(vb))) {
        return parseFloat(va) - parseFloat(vb);
    }
    // date は YYYY-MM-DD 文字列なので文字列比較で日付順になる
    return String(va).localeCompare(String(vb), 'ja', { numeric: true, sensitivity: 'base' });
}

/** @return Map(row => {fieldName: '001', ...}) */
function predictNumbers() {
    const result = new Map();
    const live = rows.filter(r => !r.markedDelete);
    const order = live.map((r, i) => ({ r, i }));
    const types = CFG.fieldTypes;
    order.sort((a, b) => {
        for (const f of CFG.seq.sortFields) {
            const c = cmpVal(String(valueOf(a.r, f)), String(valueOf(b.r, f)), types[f] || 'text');
            if (c !== 0) return c;
        }
        // 同順位：既存行は record id 順、新規行はその後ろに追加順
        if (!a.r.isNew && !b.r.isNew) return a.r.id - b.r.id;
        if (a.r.isNew !== b.r.isNew) return a.r.isNew ? 1 : -1;
        return a.i - b.i;
    });
    let seq = 0;
    const grp = {};
    order.forEach(({ r }) => {
        const nums = {};
        if (CFG.seq.seqField) nums[CFG.seq.seqField] = String(++seq).padStart(3, '0');
        if (CFG.seq.groupSeqField) {
            const g = String(valueOf(r, CFG.seq.groupField)).trim();
            grp[g] = (grp[g] || 0) + 1;
            nums[CFG.seq.groupSeqField] = String(grp[g]).padStart(3, '0');
        }
        result.set(r, nums);
    });
    return result;
}

/** 表示する番号（オン：予測値／オフ：現在値）と、変わるかどうか */
function numbersFor(row, predicted) {
    const out = {};
    numCols().forEach(f => {
        const cur = currentNum(row, f);
        if (renumberOn() && !row.markedDelete) {
            const nv = predicted.get(row)?.[f] ?? '';
            out[f] = { cur, next: nv, changed: nv !== cur };
        } else {
            out[f] = { cur, next: cur, changed: false };
        }
    });
    return out;
}

function updateNumbers() {
    if (numCols().length === 0) return;
    const predicted = predictNumbers();
    document.querySelectorAll('#dfe-tbody tr').forEach(tr => {
        const row = rows[parseInt(tr.dataset.idx)];
        if (!row) return;
        const nums = numbersFor(row, predicted);
        tr.querySelectorAll('td.num-col').forEach(td => {
            const n = nums[td.dataset.numField];
            td.textContent = n.next !== '' ? n.next : '—';
            td.classList.toggle('num-changed', n.changed);
            td.title = n.changed ? `${n.cur || '—'} → ${n.next}` : '';
        });
    });
}

function onFieldChanged(fname) {
    if (CFG.seq.sortFields.includes(fname) || fname === CFG.seq.groupField) {
        updateNumbers();
    }
}

// ===== プレビュー =====
function showPreview() {
    const updates = [], deletes = [], adds = [];
    const predicted = predictNumbers();
    const nc = numCols();
    const on = renumberOn();

    // 行ごとの状態を判定
    const info = rows.map(row => {
        const nums = numbersFor(row, predicted);
        const numChanged = nc.some(f => nums[f].changed);
        let state = 'same';
        if (row.markedDelete && !row.isNew) {
            state = 'deleted';
            deletes.push({ entryId: row.id });
        } else if (row.markedDelete && row.isNew) {
            state = 'skip'; // 追加してすぐ削除は無視
        } else if (row.isNew) {
            state = 'added';
            adds.push({ current: row.fields });
        } else {
            const changed = Object.keys(row.fields).some(
                k => row.fields[k] !== (row.original[k] ?? '')
            );
            if (changed) {
                state = 'changed';
                updates.push({ entryId: row.id, original: row.original, current: row.fields });
            } else if (numChanged) {
                state = 'numonly';
            }
        }
        return { row, nums, state };
    });

    const allCols = CFG.fixedFields.concat(CFG.moveFields);
    const header  = nc.concat(allCols);
    const numCells = (x, mode) => nc.map(f => {
        const n = x.nums[f];
        if (mode === 'deleted') return escHtml(n.cur);
        if (mode === 'added')   return escHtml(n.next || '—');
        return diffCell(n.cur, n.next);
    });
    const fieldCells = (x, mode) => allCols.map(f => {
        if (mode === 'deleted') return escHtml(x.row.original?.[f] ?? '');
        if (mode === 'added')   return escHtml(x.row.fields[f] ?? '');
        return diffCell(x.row.original?.[f] ?? '', x.row.fields[f] ?? '');
    });
    const label = x => x.row.isNew ? L.newRow : `ID ${x.row.id}`;
    const toDef = (x, mode, cls) => ({
        label: label(x), cols: numCells(x, mode).concat(fieldCells(x, mode)), cls,
    });

    // 振り直しの状態表示
    document.getElementById('dfe-preview-status').textContent =
        CFG.hasRenumber ? (on ? L.renumberOn : L.renumberOff) : '';

    const showAll = document.getElementById('dfe-showall-cb').checked;
    let html = '';

    if (showAll) {
        // 全件：保存後の並び（振り直しオンなら予測した通番順、オフなら現在の行順）
        let list = info.filter(x => x.state !== 'skip' && x.state !== 'deleted');
        if (on && CFG.seq.seqField) {
            list.sort((a, b) => (a.nums[CFG.seq.seqField].next).localeCompare(b.nums[CFG.seq.seqField].next));
        }
        const cls = { changed: 'changed', added: 'new-row', numonly: 'num-only', same: '' };
        const defs = list.map(x => toDef(x, x.state === 'added' ? 'added' : 'diff', cls[x.state]));
        info.filter(x => x.state === 'deleted').forEach(x => defs.push(toDef(x, 'deleted', 'deleted')));
        html = buildPreviewTable(defs, header);
    } else {
        const ch = info.filter(x => x.state === 'changed');
        const no = info.filter(x => x.state === 'numonly');
        const de = info.filter(x => x.state === 'deleted');
        const ad = info.filter(x => x.state === 'added');
        if (!ch.length && !no.length && !de.length && !ad.length) {
            html = `<p>${L.nochanges}</p>`;
        }
        if (ch.length) {
            html += `<h5>${L.changed}（${ch.length}件）</h5>`;
            html += buildPreviewTable(ch.map(x => toDef(x, 'diff', 'changed')), header);
        }
        if (no.length) {
            html += `<h5>${L.numChanged}（${no.length}件）</h5>`;
            html += buildPreviewTable(no.map(x => toDef(x, 'diff', 'num-only')), header);
        }
        if (de.length) {
            html += `<h5>${L.deleted}（${de.length}件）</h5>`;
            html += buildPreviewTable(de.map(x => toDef(x, 'deleted', 'deleted')), header);
        }
        if (ad.length) {
            html += `<h5>${L.added}（${ad.length}件）</h5>`;
            html += buildPreviewTable(ad.map(x => toDef(x, 'added', 'new-row')), header);
        }
    }

    document.getElementById('dfe-preview-content').innerHTML = html;
    document.getElementById('dfe-edit-view').style.display   = 'none';
    document.getElementById('dfe-preview').style.display     = 'block';

    // payload を保持（番号の書き込みはサーバー側の振り直しで行う）
    window._dfePayload = { updates, deletes, adds };
}

function buildPreviewTable(rowDefs, cols) {
    let html = '<table><thead><tr><th>#</th>';
    cols.forEach(c => { html += `<th>${escHtml(c)}</th>`; });
    html += '</tr></thead><tbody>';
    rowDefs.forEach(r => {
        html += `<tr class="${r.cls}"><td>${r.label}</td>`;
        r.cols.forEach(c => { html += `<td>${c}</td>`; });
        html += '</tr>';
    });
    html += '</tbody></table>';
    return html;
}

function diffCell(oldVal, newVal) {
    if (oldVal === newVal) return escHtml(newVal);
    return `<span class="diff-old">${escHtml(oldVal)}</span>`
         + `<span class="diff-new">${escHtml(newVal)}</span>`;
}

// ===== 保存 =====
function doSave() {
    if (!confirm(L.confirmSave)) return;
    const payload = window._dfePayload || { updates: [], deletes: [], adds: [] };
    payload.renumber = document.getElementById('dfe-renumber-cb')?.checked || false;
    document.getElementById('dfe-updates-input').value = JSON.stringify(payload);
    document.getElementById('dfe-save-form').submit();
}

// ===== ボタンのバインド =====
function bindButtons() {
    document.getElementById('dfe-mode-insert').addEventListener('click', () => {
        dndMode = 'insert';
        document.getElementById('dfe-mode-insert').classList.add('active');
        document.getElementById('dfe-mode-swap').classList.remove('active');
    });
    document.getElementById('dfe-mode-swap').addEventListener('click', () => {
        dndMode = 'swap';
        document.getElementById('dfe-mode-swap').classList.add('active');
        document.getElementById('dfe-mode-insert').classList.remove('active');
    });
    document.getElementById('dfe-add-row').addEventListener('click', addRow);
    document.getElementById('dfe-preview-btn').addEventListener('click', showPreview);
    document.getElementById('dfe-back-btn').addEventListener('click', () => {
        document.getElementById('dfe-preview').style.display   = 'none';
        document.getElementById('dfe-edit-view').style.display = '';
    });
    document.getElementById('dfe-save-btn').addEventListener('click', doSave);
    document.getElementById('dfe-renumber-cb').addEventListener('change', updateNumbers);
    document.getElementById('dfe-showall-cb').addEventListener('change', showPreview);
}

// ===== ユーティリティ =====
function escHtml(s) {
    return String(s)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
}

function makeBtn(label, handler) {
    const btn = document.createElement('button');
    btn.textContent = label;
    btn.addEventListener('click', handler);
    return btn;
}

// ===== 起動 =====
init();

})();
</script>

<?php echo $OUTPUT->footer(); ?>
