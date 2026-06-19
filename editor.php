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

// ブロック設定を取得
$blockinstance = $DB->get_record('block_instances', ['id' => $instanceid], '*', MUST_EXIST);
$blockcfg      = unserialize(base64_decode($blockinstance->configdata));

$fixedFieldsCsv  = $blockcfg->fixed_fields    ?? '';
$moveFieldsCsv   = $blockcfg->move_fields     ?? '';
$sortField       = trim($blockcfg->sort_field      ?? '');
$seqField        = trim($blockcfg->seq_field       ?? '');
$groupField      = trim($blockcfg->group_field     ?? '');
$seqSortFieldsCsv = $blockcfg->seq_sort_fields ?? '';
$textformat      = isset($blockcfg->textformat) ? (int)$blockcfg->textformat : FORMAT_PLAIN;
$minrows         = isset($blockcfg->minrows)    ? (int)$blockcfg->minrows    : 2;

$fixedFields  = array_values(array_filter(array_map('trim', explode(',', $fixedFieldsCsv))));
$moveFields   = array_values(array_filter(array_map('trim', explode(',', $moveFieldsCsv))));
$seqSortFields = array_values(array_filter(array_map('trim', explode(',', $seqSortFieldsCsv))));
$allFields    = array_merge($fixedFields, $moveFields);

$renumberCfg = [
    'seqField'   => $seqField,
    'groupField' => $groupField,
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

// エントリ取得
$entries = block_data_flex_editor_get_entries($dataid, $USER->id, $sortField, $allFields);

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
    'hasRenumber' => !empty($seqField),
    'saveUrl'     => (new moodle_url('/blocks/data_flex_editor/editor.php'))->out(false),
    'sesskey'     => sesskey(),
    'minrows'     => $minrows,
    'menuOptions' => $menuOptions,
    'fieldTypes'  => array_map(fn($f) => $f->type, $fmeta),
    'strings'     => [
        'add'          => get_string('addrow',    'block_data_flex_editor'),
        'preview'      => get_string('preview',   'block_data_flex_editor'),
        'save'         => get_string('save',      'block_data_flex_editor'),
        'renumber'     => get_string('renumber',  'block_data_flex_editor'),
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
    $row = ['id' => $eid, 'fields' => $entry->fields];
    $entriesJs[] = $row;
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
</style>

<div id="dfe-app">
  <!-- 編集ビュー -->
  <div id="dfe-edit-view">
    <div class="dfe-mode-bar">
      <span id="dfe-mode-label"></span>
      <button class="dfe-mode-btn active" id="dfe-mode-insert"></button>
      <button class="dfe-mode-btn"        id="dfe-mode-swap"></button>
    </div>
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
    <div id="dfe-preview-content"></div>
    <div style="margin-top:1rem; display:flex; gap:8px; align-items:center; flex-wrap:wrap;">
      <button class="btn btn-secondary" id="dfe-back-btn"></button>
      <label id="dfe-renumber-wrap" style="display:none;">
        <input type="checkbox" id="dfe-renumber-cb"> <span id="dfe-renumber-label"></span>
      </label>
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
    CFG.fixedFields.forEach(f => {
        html += `<th>${escHtml(f)}</th>`;
    });
    CFG.moveFields.forEach(f => {
        html += `<th style="background:#2a6099;">${escHtml(f)}</th>`;
    });
    html += '<th>削除</th></tr>';
    thead.innerHTML = html;
}

// ===== テーブルボディ =====
function renderBody() {
    const tbody = document.getElementById('dfe-tbody');
    tbody.innerHTML = '';
    rows.forEach((row, idx) => {
        tbody.appendChild(makeRow(row, idx));
    });
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
        sel.addEventListener('change', () => { row.fields[fname] = sel.value; });
        return sel;
    }

    if (ftype === 'date') {
        const inp = document.createElement('input');
        inp.type  = 'date';
        inp.className = 'dfe-input';
        inp.value = val;
        inp.addEventListener('change', () => { row.fields[fname] = inp.value; });
        return inp;
    }

    if (ftype === 'textarea') {
        const ta = document.createElement('textarea');
        ta.className = 'dfe-input';
        ta.rows = CFG.minrows;
        ta.value = val;
        ta.addEventListener('input', () => { row.fields[fname] = ta.value; });
        return ta;
    }

    // text / number / url / その他
    const inp = document.createElement('input');
    inp.type = (ftype === 'number') ? 'number' : 'text';
    inp.className = 'dfe-input';
    inp.value = val;
    inp.addEventListener('input', () => { row.fields[fname] = inp.value; });
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

// ===== プレビュー =====
function showPreview() {
    const updates = [], deletes = [], adds = [];

    rows.forEach(row => {
        if (row.markedDelete && !row.isNew) {
            deletes.push({ entryId: row.id });
            return;
        }
        if (row.markedDelete && row.isNew) return; // 追加してすぐ削除は無視

        if (row.isNew) {
            adds.push({ current: row.fields });
            return;
        }

        // 変更チェック
        const changed = Object.keys(row.fields).some(
            k => row.fields[k] !== (row.original[k] ?? '')
        );
        if (changed) {
            updates.push({ entryId: row.id, original: row.original, current: row.fields });
        }
    });

    const allCols = CFG.fixedFields.concat(CFG.moveFields);

    let html = '';
    if (updates.length === 0 && deletes.length === 0 && adds.length === 0) {
        html = `<p>${L.nochanges}</p>`;
    } else {
        // 変更
        if (updates.length > 0) {
            html += `<h5>${L.changed}（${updates.length}件）</h5>`;
            html += buildPreviewTable(updates.map(u => ({
                label: `ID ${u.entryId}`,
                cols:  allCols.map(f => diffCell(u.original[f] ?? '', u.current[f] ?? '')),
                cls:   'changed',
            })), allCols);
        }
        // 削除
        if (deletes.length > 0) {
            const delRows = deletes.map(d => {
                const r = rows.find(rr => rr.id === d.entryId);
                return {
                    label: `ID ${d.entryId}`,
                    cols:  allCols.map(f => escHtml(r?.original?.[f] ?? '')),
                    cls:   'deleted',
                };
            });
            html += `<h5>${L.deleted}（${deletes.length}件）</h5>`;
            html += buildPreviewTable(delRows, allCols);
        }
        // 追加
        if (adds.length > 0) {
            html += `<h5>${L.added}（${adds.length}件）</h5>`;
            html += buildPreviewTable(adds.map((a, i) => ({
                label: `新規${i + 1}`,
                cols:  allCols.map(f => escHtml(a.current[f] ?? '')),
                cls:   '',
            })), allCols);
        }
    }

    document.getElementById('dfe-preview-content').innerHTML = html;
    document.getElementById('dfe-edit-view').style.display   = 'none';
    document.getElementById('dfe-preview').style.display     = '';

    // payload を hidden フォームに保持
    const payload = { updates, deletes, adds };
    window._dfePayload = payload;
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
