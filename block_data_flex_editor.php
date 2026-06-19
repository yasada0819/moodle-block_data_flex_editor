<?php
defined('MOODLE_INTERNAL') || die();

class block_data_flex_editor extends block_base {

    public function init() {
        $this->title = get_string('pluginname', 'block_data_flex_editor');
    }

    public function has_config(): bool {
        return false;
    }

    public function instance_allow_config(): bool {
        return true;
    }

    public function applicable_formats(): array {
        return ['all' => true];
    }

    public function get_content() {
        global $CFG;

        if ($this->content !== null) {
            return $this->content;
        }
        $this->content = new stdClass();
        $this->content->text   = '';
        $this->content->footer = '';

        $blockctx = context_block::instance($this->instance->id);

        if (!has_capability('block/data_flex_editor:edit', $blockctx)) {
            return $this->content;
        }

        $cfg    = $this->config ?? new stdClass();
        $params = [
            'courseid'   => $this->page->course->id,
            'instanceid' => $this->instance->id,
        ];

        $links = [];

        // エディタを開くリンク（dataid が設定済みの場合のみ）
        if (!empty($cfg->dataid)) {
            $editorUrl = new moodle_url('/blocks/data_flex_editor/editor.php',
                array_merge($params, ['dataid' => (int)$cfg->dataid]));
            $label = !empty($cfg->title) ? $cfg->title
                   : get_string('openeditor', 'block_data_flex_editor');
            $links[] = html_writer::link($editorUrl, $label,
                ['class' => 'btn btn-primary btn-sm']);
        } else {
            $links[] = html_writer::tag('p',
                get_string('notconfigured', 'block_data_flex_editor'),
                ['class' => 'text-muted small']);
        }

        // フィールド設定リンク（manage権限がある場合）
        if (has_capability('block/data_flex_editor:manage', $blockctx)) {
            $settingsUrl = new moodle_url('/blocks/data_flex_editor/settings.php', $params);
            $links[] = html_writer::link($settingsUrl,
                get_string('fieldsettings', 'block_data_flex_editor'),
                ['class' => 'btn btn-secondary btn-sm']);
        }

        $this->content->text = implode(' ', $links);
        return $this->content;
    }
}
