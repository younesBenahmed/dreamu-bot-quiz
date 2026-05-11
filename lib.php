<?php
defined('MOODLE_INTERNAL') || die();

/**
 * Add Bot Quiz link to quiz navigation.
 */
function local_dreamu_botquiz_extend_settings_navigation(settings_navigation $settingsnav, context $context) {
    global $PAGE, $DB;

    if ($context->contextlevel !== CONTEXT_MODULE) {
        return;
    }

    $cm = get_coursemodule_from_id('quiz', $context->instanceid, 0, false, IGNORE_MISSING);
    if (!$cm) {
        return;
    }

    if (!has_capability('local/dreamu_botquiz:generate', $context)) {
        return;
    }

    $assignnode = $settingsnav->find('modulesettings', navigation_node::TYPE_SETTING);
    if (!$assignnode) {
        return;
    }

    $url = new moodle_url('/local/dreamu_botquiz/index.php', ['id' => $cm->id]);
    $assignnode->add(
        'Bot Quiz - Generer des reponses',
        $url,
        navigation_node::TYPE_SETTING,
        null,
        'dreamu_botquiz',
        new pix_icon('i/cohort', '')
    );
}
