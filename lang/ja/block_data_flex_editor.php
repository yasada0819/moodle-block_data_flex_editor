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
$string['role_group']   = 'グループフィールド（区分別カウント）';
$string['role_seqsort'] = '連番ソートキー（振り直し時の並び順）';
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
