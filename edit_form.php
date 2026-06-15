<?php
defined('MOODLE_INTERNAL') || die();

class block_data_flex_editor_edit_form extends block_edit_form {

    protected function specific_definition($mform) {

        $mform->addElement('header', 'configheader',
            get_string('blocksettings', 'block_data_flex_editor'));

        // ブロックタイトル
        $mform->addElement('text', 'config_title',
            get_string('blocktitle', 'block_data_flex_editor'));
        $mform->setType('config_title', PARAM_TEXT);

        // データベースID
        $mform->addElement('text', 'config_dataid',
            get_string('dataid', 'block_data_flex_editor'));
        $mform->setType('config_dataid', PARAM_INT);
        $mform->addRule('config_dataid', null, 'numeric', null, 'client');
        $mform->addHelpButton('config_dataid', 'dataid', 'block_data_flex_editor');

        // テキストフォーマット
        $formatoptions = [
            FORMAT_PLAIN  => get_string('formatplain',  'block_data_flex_editor'),
            FORMAT_HTML   => get_string('formathtml',   'block_data_flex_editor'),
            FORMAT_MOODLE => get_string('formatmoodle', 'block_data_flex_editor'),
        ];
        $mform->addElement('select', 'config_textformat',
            get_string('textformat', 'block_data_flex_editor'), $formatoptions);
        $mform->setDefault('config_textformat', FORMAT_PLAIN);

        // テキストエリア最小行数
        $rowoptions = array_combine(range(1, 10), range(1, 10));
        $mform->addElement('select', 'config_minrows',
            get_string('minrows', 'block_data_flex_editor'), $rowoptions);
        $mform->setDefault('config_minrows', 2);

        // フィールド役割の設定は settings.php で行う旨を案内
        $mform->addElement('html',
            '<div class="alert alert-info mt-2">'
            . get_string('settings_notice', 'block_data_flex_editor')
            . '</div>'
        );
    }
}
