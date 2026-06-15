<?php
defined('MOODLE_INTERNAL') || die();

// ---------------------------------------------------------------------------
// フィールドメタデータ
// ---------------------------------------------------------------------------

/**
 * dataid のフィールド一覧を返す
 * @return array  [name => {id, name, type, param1}]
 */
function block_data_flex_editor_get_fields(int $dataid): array {
    global $DB;
    $rows = $DB->get_records('data_fields', ['dataid' => $dataid], 'id ASC',
                             'id, name, type, param1');
    $result = [];
    foreach ($rows as $r) {
        $result[$r->name] = $r;
    }
    return $result;
}

/**
 * フィールド名 → フィールドID のマップ
 */
function block_data_flex_editor_get_fieldmap(int $dataid): array {
    $fields = block_data_flex_editor_get_fields($dataid);
    $map = [];
    foreach ($fields as $name => $f) {
        $map[$name] = (int)$f->id;
    }
    return $map;
}

// ---------------------------------------------------------------------------
// エントリ取得
// ---------------------------------------------------------------------------

/**
 * 指定ユーザーのエントリを取得する
 *
 * @param int    $dataid
 * @param int    $userid
 * @param string $sortfield  ソートに使うフィールド名（空なら record id 順）
 * @param array  $allfields  表示対象フィールド名リスト（固定列＋移動列）
 * @return array  [recordid => {id, fields:[name=>value]}]
 */
function block_data_flex_editor_get_entries(
    int $dataid, int $userid, string $sortfield, array $allfields
): array {
    global $DB;

    $fm     = block_data_flex_editor_get_fieldmap($dataid);
    $fmeta  = block_data_flex_editor_get_fields($dataid);

    $records = $DB->get_records('data_records', [
        'dataid' => $dataid,
        'userid' => $userid,
    ], 'id ASC');

    if (empty($records)) {
        return [];
    }

    $recordids = array_keys($records);
    list($insql, $params) = $DB->get_in_or_equal($recordids);
    $contents = $DB->get_records_select('data_content', "recordid $insql", $params);

    // [recordid][fieldid] = content
    $cmap = [];
    foreach ($contents as $c) {
        $cmap[$c->recordid][$c->fieldid] = $c->content;
    }

    $result = [];
    foreach ($records as $rec) {
        $obj = new stdClass();
        $obj->id     = $rec->id;
        $obj->fields = [];
        foreach ($allfields as $fname) {
            if (!isset($fm[$fname])) {
                $obj->fields[$fname] = '';
                continue;
            }
            $fid   = $fm[$fname];
            $raw   = $cmap[$rec->id][$fid] ?? '';
            $ftype = $fmeta[$fname]->type ?? 'text';
            $obj->fields[$fname] = block_data_flex_editor_format_value($raw, $ftype);
        }
        $result[$rec->id] = $obj;
    }

    // ソート
    if ($sortfield && isset($fm[$sortfield])) {
        $ftype = $fmeta[$sortfield]->type ?? 'text';
        uasort($result, function($a, $b) use ($sortfield, $ftype) {
            $va = $a->fields[$sortfield] ?? '';
            $vb = $b->fields[$sortfield] ?? '';
            if ($ftype === 'date' || $ftype === 'number') {
                $na = is_numeric($va) ? (float)$va : PHP_INT_MAX;
                $nb = is_numeric($vb) ? (float)$vb : PHP_INT_MAX;
                return $na <=> $nb;
            }
            return strnatcasecmp((string)$va, (string)$vb);
        });
    }

    return $result;
}

/**
 * フィールド型に応じて値を整形（表示用）
 */
function block_data_flex_editor_format_value(string $raw, string $type): string {
    if ($type === 'date') {
        if (empty($raw) || !is_numeric($raw)) {
            return $raw;
        }
        return date('Y-m-d', (int)$raw);
    }
    return $raw;
}

/**
 * 表示用の値を保存用に変換
 */
function block_data_flex_editor_parse_value(string $val, string $type): string {
    if ($type === 'date') {
        if (empty($val)) {
            return '';
        }
        $ts = strtotime($val);
        return $ts !== false ? (string)$ts : $val;
    }
    return $val;
}

// ---------------------------------------------------------------------------
// メニュー選択肢の取得
// ---------------------------------------------------------------------------

/**
 * menu型フィールドの選択肢を返す
 * @return string[]
 */
function block_data_flex_editor_get_menu_options(int $dataid, string $fieldname): array {
    global $DB;
    $f = $DB->get_record('data_fields', ['dataid' => $dataid, 'name' => $fieldname]);
    if (!$f || $f->type !== 'menu') {
        return [];
    }
    $lines = preg_split('/\r?\n/', trim($f->param1 ?? ''));
    return array_values(array_filter($lines, fn($l) => $l !== ''));
}

// ---------------------------------------------------------------------------
// 一括保存
// ---------------------------------------------------------------------------

/**
 * @param int   $dataid
 * @param array $payload  {updates, deletes, adds, renumber}
 * @param int   $userid
 * @param array $fixedFields   固定列フィールド名リスト
 * @param array $moveFields    移動列フィールド名リスト
 * @param array $renumberCfg  {seqField, groupField, sortFields:[]}
 * @param int   $textformat
 */
function block_data_flex_editor_save_entries(
    int $dataid, array $payload, int $userid,
    array $fixedFields, array $moveFields,
    array $renumberCfg, int $textformat = FORMAT_PLAIN
): void {
    global $DB;

    $fm    = block_data_flex_editor_get_fieldmap($dataid);
    $fmeta = block_data_flex_editor_get_fields($dataid);
    $allfields = array_merge($fixedFields, $moveFields);

    // --- 削除 ---
    foreach ($payload['deletes'] ?? [] as $del) {
        $eid = (int)$del['entryId'];
        $DB->delete_records('data_content', ['recordid' => $eid]);
        $DB->delete_records('data_records',  ['id'       => $eid]);
    }

    // --- 更新 ---
    foreach ($payload['updates'] ?? [] as $upd) {
        $eid     = (int)$upd['entryId'];
        $current = $upd['current'];
        block_data_flex_editor_write_fields($eid, $current, $allfields, $fm, $fmeta, $textformat);
        $DB->set_field('data_records', 'timemodified', time(), ['id' => $eid]);
    }

    // --- 追加 ---
    foreach ($payload['adds'] ?? [] as $add) {
        $rec = (object)[
            'dataid'       => $dataid,
            'userid'       => $userid,
            'groupid'      => 0,
            'timecreated'  => time(),
            'timemodified' => time(),
            'approved'     => 0,
        ];
        $eid = $DB->insert_record('data_records', $rec);
        block_data_flex_editor_write_fields($eid, $add['current'], $allfields, $fm, $fmeta, $textformat);
    }

    // --- 連番振り直し ---
    if (!empty($payload['renumber']) && !empty($renumberCfg['seqField'])) {
        block_data_flex_editor_renumber($dataid, $userid, $fm, $fmeta, $renumberCfg);
    }
}

/**
 * フィールド値をdata_contentに書き込む
 */
function block_data_flex_editor_write_fields(
    int $entryid, array $current, array $allfields,
    array $fm, array $fmeta, int $textformat
): void {
    global $DB;

    foreach ($allfields as $fname) {
        if (!array_key_exists($fname, $current) || !isset($fm[$fname])) {
            continue;
        }
        $fieldid = $fm[$fname];
        $ftype   = $fmeta[$fname]->type ?? 'text';
        $value   = block_data_flex_editor_parse_value((string)$current[$fname], $ftype);

        $existing = $DB->get_record('data_content', [
            'recordid' => $entryid,
            'fieldid'  => $fieldid,
        ]);
        if ($existing) {
            $existing->content = $value;
            if ($ftype === 'textarea') {
                $existing->content1 = $textformat;
            }
            $DB->update_record('data_content', $existing);
        } else {
            $row = (object)[
                'recordid' => $entryid,
                'fieldid'  => $fieldid,
                'content'  => $value,
            ];
            if ($ftype === 'textarea') {
                $row->content1 = $textformat;
            }
            $DB->insert_record('data_content', $row);
        }
    }
}

// ---------------------------------------------------------------------------
// 連番振り直し
// ---------------------------------------------------------------------------

/**
 * seqField  : 通し番号フィールド名
 * groupField: 区分フィールド名（空なら区分なし）
 * sortFields: ソートキーフィールド名の配列（順に優先）
 */
function block_data_flex_editor_renumber(
    int $dataid, int $userid, array $fm, array $fmeta, array $cfg
): void {
    global $DB;

    $seqField   = $cfg['seqField']   ?? '';
    $groupField = $cfg['groupField'] ?? '';
    $sortFields = $cfg['sortFields'] ?? [];

    if (!$seqField || !isset($fm[$seqField])) {
        return;
    }

    // 全エントリのソートキー値を取得
    $records = $DB->get_records('data_records', [
        'dataid' => $dataid,
        'userid' => $userid,
    ], 'id ASC', 'id');

    if (empty($records)) {
        return;
    }

    $recordids = array_keys($records);
    list($insql, $params) = $DB->get_in_or_equal($recordids);
    $contents = $DB->get_records_select('data_content', "recordid $insql", $params);

    $cmap = [];
    foreach ($contents as $c) {
        $cmap[$c->recordid][$c->fieldid] = $c->content;
    }

    // ソートデータ構築
    $sortData = [];
    foreach ($recordids as $rid) {
        $row = ['_id' => $rid];
        foreach ($sortFields as $sf) {
            if (!isset($fm[$sf])) {
                $row[$sf] = '';
                continue;
            }
            $raw   = $cmap[$rid][$fm[$sf]] ?? '';
            $ftype = $fmeta[$sf]->type ?? 'text';
            // date型はタイムスタンプのまま数値比較
            $row[$sf] = ($ftype === 'date' || $ftype === 'number') ? (int)$raw : $raw;
        }
        if ($groupField && isset($fm[$groupField])) {
            $row['_group'] = $cmap[$rid][$fm[$groupField]] ?? '';
        } else {
            $row['_group'] = '';
        }
        $sortData[$rid] = $row;
    }

    // ソート実行
    usort($sortData, function($a, $b) use ($sortFields) {
        foreach ($sortFields as $sf) {
            $va = $a[$sf] ?? '';
            $vb = $b[$sf] ?? '';
            $cmp = is_numeric($va) && is_numeric($vb)
                ? $va <=> $vb
                : strnatcasecmp((string)$va, (string)$vb);
            if ($cmp !== 0) {
                return $cmp;
            }
        }
        return 0;
    });

    // 採番
    $seqCounter   = 1;
    $groupCounter = [];
    $seqFieldId   = $fm[$seqField];
    $grpFieldId   = $groupField && isset($fm[$groupField]) ? $fm[$groupField] : 0;

    foreach ($sortData as $row) {
        $rid   = $row['_id'];
        $group = $row['_group'];

        $seqVal = sprintf('%03d', $seqCounter++);
        block_data_flex_editor_upsert_content($rid, $seqFieldId, $seqVal);

        // グループ別カウンタ（groupFieldが設定されている場合）
        if ($grpFieldId) {
            if (!isset($groupCounter[$group])) {
                $groupCounter[$group] = 1;
            }
            // grpFieldId への書き込みはしない（groupFieldは読み取り専用）
            // 区分別カウンタを別フィールドに書く場合は cfg['groupSeqField'] を追加する
        }
    }
}

/**
 * data_content の upsert ヘルパー
 */
function block_data_flex_editor_upsert_content(int $recordid, int $fieldid, string $value): void {
    global $DB;
    $existing = $DB->get_record('data_content', ['recordid' => $recordid, 'fieldid' => $fieldid]);
    if ($existing) {
        $existing->content = $value;
        $DB->update_record('data_content', $existing);
    } else {
        $DB->insert_record('data_content', (object)[
            'recordid' => $recordid,
            'fieldid'  => $fieldid,
            'content'  => $value,
        ]);
    }
}

// ---------------------------------------------------------------------------
// AJAX用：フィールド一覧を返す
// ---------------------------------------------------------------------------

/**
 * dataid のフィールド一覧を JSON 出力用配列で返す
 * @return array  [{name, type, label}]
 */
function block_data_flex_editor_fields_for_ajax(int $dataid): array {
    $fields = block_data_flex_editor_get_fields($dataid);
    $result = [];
    foreach ($fields as $name => $f) {
        $result[] = [
            'name'  => $name,
            'type'  => $f->type,
            'label' => $name, // mod_data はフィールド名がそのままラベル
        ];
    }
    return $result;
}
