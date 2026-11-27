<?php
defined('MOODLE_INTERNAL') || die();

// ---------------------------------------------------------------------------
// アクセス検証
// ---------------------------------------------------------------------------

/**
 * ブロックインスタンスを取得し、このプラグインのブロックで、かつ指定コース内
 * （コーストップ、またはコース内のモジュールページなど下位コンテキスト）に
 * 配置されたものであることを確認する。
 *
 * URL パラメータの instanceid を別コース・別ブロックのものに差し替えて
 * 権限チェックをすり抜けることを防ぐ。
 *
 * @param int $instanceid
 * @param int $courseid
 * @return array [stdClass $blockinstance, stdClass $blockcfg]
 * @throws moodle_exception
 */
function block_data_flex_editor_get_instance(int $instanceid, int $courseid): array {
    global $DB;

    $blockinstance = $DB->get_record('block_instances', ['id' => $instanceid], '*', MUST_EXIST);
    $coursecontext = context_course::instance($courseid);

    if ($blockinstance->blockname !== 'data_flex_editor') {
        throw new moodle_exception('invalidinstance', 'block_data_flex_editor');
    }
    $parentcontext = context::instance_by_id($blockinstance->parentcontextid, IGNORE_MISSING);
    $incourse = $parentcontext && (
        (int)$parentcontext->id === (int)$coursecontext->id
        || strpos($parentcontext->path, $coursecontext->path . '/') === 0
    );
    if (!$incourse) {
        throw new moodle_exception('invalidinstance', 'block_data_flex_editor');
    }

    $blockcfg = !empty($blockinstance->configdata)
        ? unserialize(base64_decode($blockinstance->configdata))
        : new stdClass();
    if (!is_object($blockcfg)) {
        $blockcfg = new stdClass();
    }

    return [$blockinstance, $blockcfg];
}

/**
 * 更新・削除対象のエントリがすべて「指定 dataid かつ指定ユーザー作成」であることを確認する。
 *
 * エディタの一覧表示は userid で絞り込んでいるが、保存時に送られてくる entryId は
 * クライアント側で書き換え可能なため、サーバー側で改めて所有者を検証する。
 *
 * @param int   $dataid
 * @param int   $userid
 * @param int[] $entryids
 * @throws moodle_exception 1件でも条件を満たさないエントリがあれば例外
 */
function block_data_flex_editor_require_owned_entries(int $dataid, int $userid, array $entryids): void {
    global $DB;

    $entryids = array_values(array_unique(array_map('intval', $entryids)));
    if (empty($entryids)) {
        return;
    }

    list($insql, $params) = $DB->get_in_or_equal($entryids, SQL_PARAMS_NAMED);
    $params['dataid'] = $dataid;
    $params['userid'] = $userid;
    $count = $DB->count_records_select('data_records',
        "id $insql AND dataid = :dataid AND userid = :userid", $params);

    if ($count !== count($entryids)) {
        throw new moodle_exception('invalidentry', 'block_data_flex_editor');
    }
}

// ---------------------------------------------------------------------------
// 代理編集（他のユーザーのエントリを編集）
// ---------------------------------------------------------------------------

/**
 * 他のユーザーのエントリを代理編集できるかを判定する。
 *
 * ブロック側の block/data_flex_editor:editothers に加えて、対象データベース側の
 * mod/data:manageentries（他人のエントリの管理）も持っていることを条件にする。
 * 「データベース上で他人のエントリを触れる人だけが、このエディタでも代理編集できる」。
 *
 * @param context_block $blockctx
 * @param int           $dataid
 * @param int           $courseid
 * @return bool
 */
function block_data_flex_editor_can_edit_others(context_block $blockctx, int $dataid, int $courseid): bool {
    if (!has_capability('block/data_flex_editor:editothers', $blockctx)) {
        return false;
    }
    $cm = get_coursemodule_from_instance('data', $dataid, $courseid, false, IGNORE_MISSING);
    if (!$cm) {
        return false;
    }
    return has_capability('mod/data:manageentries', context_module::instance($cm->id));
}

/**
 * 編集対象ユーザーを確定する。
 *
 * - 自分自身：そのまま許可（従来どおり）
 * - 他のユーザー：代理編集の権限（ブロック＋データベース両方）を要求し、
 *   削除済みでないこと、かつ「このデータベースにエントリがある」か
 *   「このコースに登録されている」ことを確認する
 *
 * @param int           $userid   0 なら自分
 * @param context_block $blockctx
 * @param int           $dataid
 * @param int           $courseid
 * @return stdClass  対象ユーザーのレコード
 * @throws moodle_exception
 */
function block_data_flex_editor_resolve_target_user(
    int $userid, context_block $blockctx, int $dataid, int $courseid
): stdClass {
    global $DB, $USER;

    if ($userid === 0 || $userid === (int)$USER->id) {
        return $USER;
    }

    if (!block_data_flex_editor_can_edit_others($blockctx, $dataid, $courseid)) {
        throw new required_capability_exception($blockctx,
            'block/data_flex_editor:editothers', 'nopermissions', '');
    }

    $user = $DB->get_record('user', ['id' => $userid, 'deleted' => 0]);
    if (!$user) {
        throw new moodle_exception('invaliduser', 'block_data_flex_editor');
    }
    $hasentries = $DB->record_exists('data_records', ['dataid' => $dataid, 'userid' => $userid]);
    if (!$hasentries && !is_enrolled(context_course::instance($courseid), $userid)) {
        throw new moodle_exception('invaliduser', 'block_data_flex_editor');
    }
    return $user;
}

/**
 * このデータベースにエントリを持つユーザーの一覧（代理編集のユーザー選択用）。
 *
 * @param int $dataid
 * @return array  [userid => {id, firstname, lastname, ..., username, entrycount, lastmodified}]
 */
function block_data_flex_editor_get_entry_owners(int $dataid): array {
    global $DB;

    $namefields = \core_user\fields::for_name()->get_sql('u', false, '', '', false)->selects;
    $sql = "SELECT u.id, u.username, {$namefields},
                   COUNT(r.id) AS entrycount, MAX(r.timemodified) AS lastmodified
              FROM {data_records} r
              JOIN {user} u ON u.id = r.userid AND u.deleted = 0
             WHERE r.dataid = :dataid
          GROUP BY u.id, u.username, {$namefields}
          ORDER BY u.username ASC";
    return $DB->get_records_sql($sql, ['dataid' => $dataid]);
}

/**
 * 対象ユーザーのエントリ状態の指紋（楽観ロック用）。
 *
 * エディタを開いた時点の指紋をフォームに埋め込み、保存時に取り直して比較する。
 * エントリの追加・削除・承認状態の変更・内容の変更（本体のデータベース画面からの
 * 編集を含む）があれば値が変わる。
 *
 * @param int $dataid
 * @param int $userid
 * @return string  sha1
 */
function block_data_flex_editor_get_fingerprint(int $dataid, int $userid): string {
    global $DB;

    $records = $DB->get_records('data_records', ['dataid' => $dataid, 'userid' => $userid],
        'id ASC', 'id, timemodified, approved');
    $buf = '';
    foreach ($records as $r) {
        $buf .= "{$r->id}:{$r->timemodified}:{$r->approved};";
    }
    if ($records) {
        list($insql, $params) = $DB->get_in_or_equal(array_keys($records));
        $contents = $DB->get_recordset_select('data_content', "recordid $insql", $params,
            'id ASC', 'id, recordid, fieldid, content, content1, content2, content3, content4');
        foreach ($contents as $c) {
            $buf .= "|{$c->recordid}:{$c->fieldid}:" . sha1(implode("\x1f", [
                (string)$c->content, (string)$c->content1, (string)$c->content2,
                (string)$c->content3, (string)$c->content4,
            ]));
        }
        $contents->close();
    }
    return sha1($buf);
}

/**
 * 代理編集のユーザー選択画面を出力する（header から footer まで）。
 *
 * 一覧：このデータベースにエントリを持つユーザー（エントリ数・最終更新）
 * 補助：エントリがまだないコース登録ユーザーをプルダウンで選べる（新規科目など）
 *
 * @param int   $dataid
 * @param int   $courseid
 * @param array $baseparams  dataid / courseid / instanceid
 */
function block_data_flex_editor_render_user_selector(int $dataid, int $courseid, array $baseparams): void {
    global $OUTPUT, $USER;

    $editorurl = '/blocks/data_flex_editor/editor.php';
    $owners    = block_data_flex_editor_get_entry_owners($dataid);

    echo $OUTPUT->header();
    echo $OUTPUT->heading(get_string('pluginname', 'block_data_flex_editor'));
    echo html_writer::tag('p', get_string('selectuser_intro', 'block_data_flex_editor'));

    // 自分のエントリ
    echo html_writer::div(html_writer::link(
        new moodle_url($editorurl, $baseparams + ['userid' => $USER->id]),
        get_string('editmyentries', 'block_data_flex_editor'),
        ['class' => 'btn btn-secondary btn-sm']
    ), 'mb-3');

    // エントリを持つユーザーの一覧
    if ($owners) {
        $table = new html_table();
        $table->attributes['class'] = 'generaltable dfe-user-table';
        $table->head = [
            get_string('fullnameuser'),
            get_string('username'),
            get_string('entrycount', 'block_data_flex_editor'),
            get_string('lastmodified'),
            '',
        ];
        foreach ($owners as $u) {
            $table->data[] = [
                s(fullname($u)),
                s($u->username),
                (int)$u->entrycount,
                $u->lastmodified ? userdate($u->lastmodified, get_string('strftimedatetimeshort')) : '—',
                html_writer::link(
                    new moodle_url($editorurl, $baseparams + ['userid' => $u->id]),
                    get_string('editthisuser', 'block_data_flex_editor'),
                    ['class' => 'btn btn-primary btn-sm']
                ),
            ];
        }
        echo html_writer::table($table);
    } else {
        echo $OUTPUT->notification(get_string('noowners', 'block_data_flex_editor'),
            \core\output\notification::NOTIFY_INFO, false);
    }

    // エントリがまだないコース登録ユーザー
    $enrolled = get_enrolled_users(context_course::instance($courseid), '', 0,
        'u.id, u.username, ' . \core_user\fields::for_name()->get_sql('u', false, '', '', false)->selects,
        'u.username ASC');
    $options = [];
    foreach ($enrolled as $u) {
        if (!isset($owners[$u->id]) && (int)$u->id !== (int)$USER->id) {
            $options[$u->id] = fullname($u) . ' (' . $u->username . ')';
        }
    }
    if ($options) {
        echo $OUTPUT->heading(get_string('otherusers', 'block_data_flex_editor'), 4, 'mt-4');
        echo html_writer::start_tag('form', ['method' => 'get', 'action' => (new moodle_url($editorurl))->out(false),
            'class' => 'form-inline']);
        foreach ($baseparams as $k => $v) {
            echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => $k, 'value' => $v]);
        }
        echo html_writer::select($options, 'userid', '', ['' => get_string('choosedots')],
            ['class' => 'custom-select mr-2', 'required' => 'required']);
        echo html_writer::empty_tag('input', ['type' => 'submit', 'class' => 'btn btn-secondary btn-sm',
            'value' => get_string('editthisuser', 'block_data_flex_editor')]);
        echo html_writer::end_tag('form');
    }

    echo $OUTPUT->footer();
}

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

    // ソート（ソートキーが表示対象外のフィールドでも DB の生値で並べる）
    if ($sortfield && isset($fm[$sortfield])) {
        $sfid  = $fm[$sortfield];
        $ftype = $fmeta[$sortfield]->type ?? 'text';
        uasort($result, function($a, $b) use ($cmap, $sfid, $ftype) {
            $va = $cmap[$a->id][$sfid] ?? '';
            $vb = $cmap[$b->id][$sfid] ?? '';
            if ($ftype === 'date' || $ftype === 'number') {
                $na = ($va !== '' && is_numeric($va)) ? (float)$va : PHP_INT_MAX;
                $nb = ($vb !== '' && is_numeric($vb)) ? (float)$vb : PHP_INT_MAX;
                return $na <=> $nb;
            }
            // 空値は末尾へ
            if ($va === '' && $vb !== '') { return 1; }
            if ($vb === '' && $va !== '') { return -1; }
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
 * @param string|null $expectedfp  エディタを開いた時点の指紋。null なら同時編集チェックをしない
 * @return bool  false = 同時編集を検知したため保存しなかった
 */
function block_data_flex_editor_save_entries(
    int $dataid, array $payload, int $userid,
    array $fixedFields, array $moveFields,
    array $renumberCfg, int $textformat = FORMAT_PLAIN,
    ?string $expectedfp = null
): bool {
    global $DB;

    $fm    = block_data_flex_editor_get_fieldmap($dataid);
    $fmeta = block_data_flex_editor_get_fields($dataid);
    $allfields = array_merge($fixedFields, $moveFields);

    // --- 所有者チェック（書き込み前にまとめて検証） ---
    $targetids = [];
    foreach ($payload['deletes'] ?? [] as $del) {
        $targetids[] = (int)($del['entryId'] ?? 0);
    }
    foreach ($payload['updates'] ?? [] as $upd) {
        $targetids[] = (int)($upd['entryId'] ?? 0);
    }
    block_data_flex_editor_require_owned_entries($dataid, $userid, $targetids);

    $transaction = $DB->start_delegated_transaction();

    // --- 同時編集チェック（楽観ロック） ---
    // 開いてから保存するまでに、本人・他の編集者・本体画面のいずれかで変更があれば保存しない
    if ($expectedfp !== null
            && !hash_equals(block_data_flex_editor_get_fingerprint($dataid, $userid), $expectedfp)) {
        $transaction->allow_commit();
        return false;
    }

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
    if (!empty($payload['renumber'])
            && (!empty($renumberCfg['seqField']) || !empty($renumberCfg['groupSeqField']))) {
        block_data_flex_editor_renumber($dataid, $userid, $fm, $fmeta, $renumberCfg);
    }

    $transaction->allow_commit();
    return true;
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
 * 連番の振り直し
 *
 * cfg:
 *   seqField      : 全体連番を書き込むフィールド名（空なら書き込まない）
 *   groupField    : 区分フィールド名（区分別連番のグループ化に使う。読み取りのみ）
 *   groupSeqField : 区分別連番を書き込むフィールド名（空なら書き込まない。groupField 必須）
 *   sortFields    : 振り直し時の並び順を決めるフィールド名の配列（先頭ほど優先）
 *
 * 区分は値をそのままグループキーとして扱う（例：「講義・実習」は「講義」「実習」とは別系列）。
 * 区分が空のエントリは「空」という1つの系列として数える。
 */
function block_data_flex_editor_renumber(
    int $dataid, int $userid, array $fm, array $fmeta, array $cfg
): void {
    global $DB;

    $seqField      = $cfg['seqField']      ?? '';
    $groupField    = $cfg['groupField']    ?? '';
    $groupSeqField = $cfg['groupSeqField'] ?? '';
    $sortFields    = $cfg['sortFields']    ?? [];

    $seqFieldId      = ($seqField && isset($fm[$seqField])) ? $fm[$seqField] : 0;
    $grpFieldId      = ($groupField && isset($fm[$groupField])) ? $fm[$groupField] : 0;
    $groupSeqFieldId = ($groupSeqField && isset($fm[$groupSeqField]) && $grpFieldId)
        ? $fm[$groupSeqField] : 0;

    if (!$seqFieldId && !$groupSeqFieldId) {
        return;
    }

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
            // date型・number型は数値比較（空は末尾）
            if ($ftype === 'date' || $ftype === 'number') {
                $row[$sf] = ($raw !== '' && is_numeric($raw)) ? (int)$raw : PHP_INT_MAX;
            } else {
                $row[$sf] = $raw;
            }
        }
        $row['_group'] = $grpFieldId ? trim((string)($cmap[$rid][$grpFieldId] ?? '')) : '';
        $sortData[] = $row;
    }

    // ソート実行（同順位は record id 順で安定させる）
    usort($sortData, function($a, $b) use ($sortFields) {
        foreach ($sortFields as $sf) {
            $va = $a[$sf] ?? '';
            $vb = $b[$sf] ?? '';
            if (is_int($va) && is_int($vb)) {
                $cmp = $va <=> $vb;
            } else {
                if ($va === '' && $vb !== '') { return 1; }
                if ($vb === '' && $va !== '') { return -1; }
                $cmp = strnatcasecmp((string)$va, (string)$vb);
            }
            if ($cmp !== 0) {
                return $cmp;
            }
        }
        return $a['_id'] <=> $b['_id'];
    });

    // 採番
    $seqCounter   = 1;
    $groupCounter = [];

    foreach ($sortData as $row) {
        $rid = $row['_id'];

        if ($seqFieldId) {
            block_data_flex_editor_upsert_content($rid, $seqFieldId, sprintf('%03d', $seqCounter++));
        }

        if ($groupSeqFieldId) {
            $group = $row['_group'];
            $groupCounter[$group] = ($groupCounter[$group] ?? 0) + 1;
            block_data_flex_editor_upsert_content($rid, $groupSeqFieldId,
                sprintf('%03d', $groupCounter[$group]));
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
