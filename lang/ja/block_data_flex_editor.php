<?php
$string['pluginname']    = '時間割フレキシブルエディタ';
$string['blocktitle']    = 'ブロックタイトル';
$string['blocksettings'] = 'ブロック設定';
$string['dataid']        = 'データベースID';
$string['dataid_help']   = 'データベースモジュールの URL に表示される d=XXXX の数値を入力してください。';
$string['fetchfields']   = 'フィールドを取得する';
$string['fetchok']       = '{n} 件のフィールドを取得しました。各フィールドの役割を設定してください。';
$string['fetcherr']      = 'フィールドの取得に失敗しました。データベースIDを確認してください。';
$string['notconfigured'] = 'ブロックを設定してください（データベースIDとフィールドの役割）。';
$string['openeditor']    = '時間割エディタを開く';

// フィールド役割
$string['role_fixed']   = '固定列（直接編集）';
$string['role_move']    = '移動列（D&Dで入れ替え）';
$string['role_sort']    = '初期ソートキー';
$string['role_seq']     = '連番フィールド（振り直し対象）';
$string['role_group']   = 'グループフィールド（区分）';
$string['role_seqsort'] = '連番ソート順位';
$string['role_none']    = '使用しない';

// テキスト設定
$string['textformat']      = 'テキストフォーマット';
$string['textformat_help'] = 'textarea フィールドの保存形式を指定します。';
$string['formatplain']     = 'プレーンテキスト';
$string['formathtml']      = 'HTML';
$string['formatmoodle']    = 'Moodle 自動フォーマット';
$string['minrows']         = 'テキストエリア最小行数';
$string['minrows_help']    = '編集テーブル内のテキストエリアの最小行数。';

// エディタUI
$string['addrow']      = '末尾にコマを追加';
$string['preview']     = 'プレビューを確認する';
$string['save']        = '保存する';
$string['back']        = '← 編集に戻る';
$string['renumber']    = '実施日・時間順で連番を振り直す';
$string['modeinsert']  = '挿入（押し出し）';
$string['modeswap']    = 'スワップ（入れ替え）';
$string['modelabel']   = 'D&Dモード';
$string['previewtitle'] = '変更内容プレビュー';
$string['changed']     = '変更';
$string['deleted']     = '削除';
$string['added']       = '追加';
$string['nochanges']   = '変更はありません。';
$string['confirmsave'] = '保存してよろしいですか？';
$string['saved']       = '保存しました。';
$string['noentries']   = 'このデータベースにエントリが見つかりませんでした。';

// settings.php 用
$string['fieldsettings']  = 'フィールド役割の設定';
$string['settings_notice']= 'dataid を保存後、ブロック上の「フィールド役割の設定」から各フィールドの役割を設定してください。';
$string['settings_saved'] = 'フィールド設定を保存しました。';
$string['back_to_course'] = 'コースに戻る';
$string['fieldname']      = 'フィールド名';
$string['fieldtype']      = '型';
$string['fieldrole']      = '役割';
$string['nofields']       = 'フィールドが見つかりませんでした。dataid を確認してください。';
$string['invalidinstance'] = 'このブロックはこのコースに配置されたものではありません。';
$string['invaliddataid']   = 'データベースIDがブロックの設定と一致しません。';
$string['invalidentry']    = '自分が作成したエントリ以外、または別のデータベースのエントリが含まれているため保存できませんでした。';
$string['role_sort_help']      = 'エディタを開いたときの並び順に使うフィールド。表示しない列でも指定できます（例：通し番号）。';
$string['role_seq_help']       = '「連番を振り直す」で 001, 002, … を全体の時系列順に書き込むフィールド。';
$string['role_group_help']     = '区分別連番のグループ分けに使うフィールド（読み取りのみ）。値をそのまま区分として扱い、「講義・実習」は「講義」「実習」とは別系列で数えます。';
$string['role_groupseq']       = '区分別連番フィールド（振り直し対象）';
$string['role_groupseq_help']  = '「連番を振り直す」で、区分ごとに 001, 002, … を書き込むフィールド。区分フィールドの指定が必要です。';
$string['role_seqsort_help']   = '連番ソートキー：振り直し時の並び順。1, 2, … の順に優先します（例：実施日=1、時間=2）。空欄は使用しません。表示の役割と同時に指定できます。';
$string['section_display']     = '表示と並び順';
$string['section_special']     = '連番・ソートの設定';
$string['groupseq_needs_group'] = '区分別連番フィールドを使うには、グループフィールド（区分）も指定してください。';
$string['seq_same_field']      = '連番フィールドと区分別連番フィールドに同じフィールドが指定されています。';
