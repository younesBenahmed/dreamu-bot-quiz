<?php
require_once(__DIR__ . '/../../config.php');
require_once($CFG->dirroot . '/mod/quiz/locallib.php');
require_once($CFG->dirroot . '/question/engine/lib.php');

$cmid = required_param('id', PARAM_INT);
$cm = get_coursemodule_from_id('quiz', $cmid, 0, false, MUST_EXIST);
$course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
$context = context_module::instance($cm->id);

require_login($course, false, $cm);
require_capability('local/dreamu_botquiz:generate', $context);

$quiz = $DB->get_record('quiz', ['id' => $cm->instance], '*', MUST_EXIST);

$PAGE->set_url(new moodle_url('/local/dreamu_botquiz/index.php', ['id' => $cmid]));
$PAGE->set_context($context);
$PAGE->set_title('Bot Quiz - ' . format_string($quiz->name));
$PAGE->set_heading($course->fullname);

// Handle form submission.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && confirm_sesskey()) {
    $numstudents = required_param('numstudents', PARAM_INT);
    $minskill = required_param('minskill', PARAM_INT);
    $maxskill = required_param('maxskill', PARAM_INT);
    $prefix = optional_param('prefix', 'bot_etudiant', PARAM_ALPHANUMEXT);
    $password = optional_param('password', 'BotQuiz2026!', PARAM_RAW);

    $numstudents = max(1, min(200, $numstudents));
    $minskill = max(0, min(100, $minskill));
    $maxskill = max($minskill, min(100, $maxskill));

    // Get quiz slots with questions.
    $slots = $DB->get_records('quiz_slots', ['quizid' => $quiz->id], 'slot ASC');
    $questions_by_slot = [];
    foreach ($slots as $sl) {
        $ref = $DB->get_record('question_references', [
            'component' => 'mod_quiz', 'questionarea' => 'slot', 'itemid' => $sl->id,
        ]);
        if (!$ref) continue;
        $ver = $DB->get_record('question_versions', ['questionbankentryid' => $ref->questionbankentryid], '*', IGNORE_MULTIPLE);
        if (!$ver) continue;
        $q = $DB->get_record('question', ['id' => $ver->questionid]);
        if (!$q) continue;
        $answers = $DB->get_records('question_answers', ['question' => $q->id], 'id ASC');
        $questions_by_slot[$sl->slot] = ['question' => $q, 'answers' => $answers, 'slot' => $sl];
    }

    $totalq = count($questions_by_slot);
    if ($totalq == 0) {
        redirect($PAGE->url, 'Le quiz ne contient aucune question.', null, \core\output\notification::NOTIFY_ERROR);
    }

    // Enrol plugin.
    $enrolplugin = enrol_get_plugin('manual');
    $instances = enrol_get_instances($course->id, true);
    $manualinstance = null;
    foreach ($instances as $inst) {
        if ($inst->enrol === 'manual') { $manualinstance = $inst; break; }
    }
    if (!$manualinstance) {
        $enrolplugin->add_instance($course);
        $instances = enrol_get_instances($course->id, true);
        foreach ($instances as $inst) {
            if ($inst->enrol === 'manual') { $manualinstance = $inst; break; }
        }
    }

    $results = [];
    $created_users = 0;

    for ($i = 1; $i <= $numstudents; $i++) {
        $username = $prefix . '_' . str_pad($i, 3, '0', STR_PAD_LEFT);

        // Create or get user.
        $user = $DB->get_record('user', ['username' => $username]);
        if (!$user) {
            $user = new stdClass();
            $user->username = $username;
            $user->password = hash_internal_user_password($password);
            $user->firstname = 'Etudiant';
            $user->lastname = 'Bot ' . $i;
            $user->email = $username . '@bot.local';
            $user->confirmed = 1;
            $user->mnethostid = $CFG->mnet_localhost_id;
            $user->timecreated = time();
            $user->timemodified = time();
            $user->lang = 'fr';
            $user->id = $DB->insert_record('user', $user);
            $created_users++;
        }

        // Enrol in course.
        if (!is_enrolled($context, $user->id)) {
            try {
                $enrolplugin->enrol_user($manualinstance, $user->id, 5); // student
            } catch (\Exception $e) {
                // Ignore email errors.
            }
        }

        // Check if already attempted.
        $existing = $DB->get_record('quiz_attempts', [
            'quiz' => $quiz->id, 'userid' => $user->id, 'state' => 'finished',
        ]);
        if ($existing) {
            $results[] = ['name' => fullname($user), 'score' => $existing->sumgrades, 'total' => $totalq, 'status' => 'existant'];
            continue;
        }

        // Create question usage.
        $quba = question_engine::make_questions_usage_by_activity('mod_quiz', $context);
        $quba->set_preferred_behaviour('deferredfeedback');

        $slotmap = [];
        foreach ($questions_by_slot as $sn => $data) {
            $qobj = question_bank::load_question($data['question']->id);
            $newslot = $quba->add_question($qobj, $data['slot']->maxmark);
            $slotmap[$sn] = $newslot;
        }
        $quba->start_all_questions();
        question_engine::save_questions_usage_by_activity($quba);

        // Create attempt.
        $attempt = new stdClass();
        $attempt->quiz = $quiz->id;
        $attempt->userid = $user->id;
        $attempt->attempt = 1;
        $attempt->uniqueid = $quba->get_id();
        $attempt->layout = implode(',', array_values($slotmap));
        $attempt->currentpage = 0;
        $attempt->preview = 0;
        $attempt->state = 'inprogress';
        $attempt->timestart = time() - mt_rand(300, 7200);
        $attempt->timefinish = 0;
        $attempt->timemodified = time();
        $attempt->timecheckstate = null;
        $attempt->sumgrades = null;
        $attempt->gradednotificationsenttime = null;
        $attempt->id = $DB->insert_record('quiz_attempts', $attempt);

        // Answer questions with skill level.
        $skill = mt_rand($minskill, $maxskill);
        $correct = 0;

        foreach ($questions_by_slot as $sn => $data) {
            $slot = $slotmap[$sn];
            $answers = array_values($data['answers']);
            $correctIdx = null;
            $wrongIdxs = [];

            foreach ($answers as $idx => $ans) {
                if ($ans->fraction > 0.5) $correctIdx = $idx;
                else $wrongIdxs[] = $idx;
            }

            if (mt_rand(1, 100) <= $skill && $correctIdx !== null) {
                $choice = $correctIdx;
                $correct++;
            } else {
                $choice = !empty($wrongIdxs) ? $wrongIdxs[array_rand($wrongIdxs)] : 0;
            }

            $quba->process_action($slot, ['answer' => $choice]);
        }

        // Finish.
        $quba->finish_all_questions(time());
        question_engine::save_questions_usage_by_activity($quba);

        $sumgrades = 0;
        foreach ($slotmap as $sn => $slot) {
            $sumgrades += $quba->get_question_mark($slot);
        }

        $attempt->state = 'finished';
        $attempt->timefinish = time();
        $attempt->timemodified = time();
        $attempt->sumgrades = $sumgrades;
        $DB->update_record('quiz_attempts', $attempt);

        // Quiz grade.
        $quizgrade = ($quiz->sumgrades > 0) ? ($sumgrades / $quiz->sumgrades) * $quiz->grade : 0;
        $existing_grade = $DB->get_record('quiz_grades', ['quiz' => $quiz->id, 'userid' => $user->id]);
        if ($existing_grade) {
            $DB->set_field('quiz_grades', 'grade', $quizgrade, ['id' => $existing_grade->id]);
        } else {
            $gr = new stdClass();
            $gr->quiz = $quiz->id;
            $gr->userid = $user->id;
            $gr->grade = $quizgrade;
            $gr->timemodified = time();
            $DB->insert_record('quiz_grades', $gr);
        }

        $pct = $totalq > 0 ? round(($sumgrades / $totalq) * 100) : 0;
        $results[] = ['name' => fullname($user), 'score' => $sumgrades, 'total' => $totalq, 'pct' => $pct, 'skill' => $skill, 'status' => 'nouveau'];
    }

    // Show results.
    echo $OUTPUT->header();
    echo $OUTPUT->heading('Bot Quiz - Resultats');

    echo '<div class="alert alert-success">';
    echo '<strong>' . count($results) . ' tentatives generees</strong>';
    if ($created_users > 0) echo ' (' . $created_users . ' comptes crees)';
    echo '</div>';

    // Stats.
    $scores = array_column(array_filter($results, fn($r) => $r['status'] === 'nouveau'), 'pct');
    if (!empty($scores)) {
        $avg = round(array_sum($scores) / count($scores), 1);
        $min = min($scores);
        $max = max($scores);
        echo '<div class="row mb-3">';
        echo '<div class="col-md-3"><div class="card bg-primary text-white"><div class="card-body text-center"><h4>' . count($scores) . '</h4><p>Nouvelles tentatives</p></div></div></div>';
        echo '<div class="col-md-3"><div class="card bg-info text-white"><div class="card-body text-center"><h4>' . $avg . '%</h4><p>Moyenne</p></div></div></div>';
        echo '<div class="col-md-3"><div class="card bg-danger text-white"><div class="card-body text-center"><h4>' . $min . '%</h4><p>Min</p></div></div></div>';
        echo '<div class="col-md-3"><div class="card bg-success text-white"><div class="card-body text-center"><h4>' . $max . '%</h4><p>Max</p></div></div></div>';
        echo '</div>';
    }

    // Table.
    echo '<table class="table table-striped table-sm">';
    echo '<thead><tr><th>Etudiant</th><th>Score</th><th>%</th><th>Skill</th><th>Statut</th></tr></thead><tbody>';
    foreach ($results as $r) {
        $badge = $r['status'] === 'nouveau'
            ? '<span class="badge badge-success bg-success">Nouveau</span>'
            : '<span class="badge badge-secondary bg-secondary">Existant</span>';
        $pct = $r['pct'] ?? '-';
        $skill = $r['skill'] ?? '-';
        echo '<tr><td>' . s($r['name']) . '</td><td>' . $r['score'] . '/' . $r['total'] . '</td><td>' . $pct . '%</td><td>' . $skill . '</td><td>' . $badge . '</td></tr>';
    }
    echo '</tbody></table>';

    $quizurl = new moodle_url('/mod/quiz/report.php', ['id' => $cmid, 'mode' => 'overview']);
    $backurl = new moodle_url('/local/dreamu_botquiz/index.php', ['id' => $cmid]);
    echo '<a href="' . $quizurl . '" class="btn btn-primary mr-2">Voir les resultats du quiz</a>';
    echo '<a href="' . $backurl . '" class="btn btn-secondary">Generer plus</a>';

    echo $OUTPUT->footer();
    exit;
}

// Display form.
echo $OUTPUT->header();
echo $OUTPUT->heading('Bot Quiz - ' . format_string($quiz->name));

$totalq = $DB->count_records('quiz_slots', ['quizid' => $quiz->id]);
$existing_attempts = $DB->count_records('quiz_attempts', ['quiz' => $quiz->id, 'state' => 'finished']);

echo '<div class="card mb-3"><div class="card-body">';
echo '<h5>' . format_string($quiz->name) . '</h5>';
echo '<table class="table table-sm" style="max-width:400px;">';
echo '<tr><td>Questions dans le quiz</td><td><strong>' . $totalq . '</strong></td></tr>';
echo '<tr><td>Tentatives existantes</td><td><strong>' . $existing_attempts . '</strong></td></tr>';
echo '</table>';
echo '</div></div>';

if ($totalq == 0) {
    echo '<div class="alert alert-warning">Ce quiz ne contient aucune question. Ajoutez des questions avant de generer des reponses.</div>';
    echo $OUTPUT->footer();
    exit;
}

echo '<form method="post" action="">';
echo '<input type="hidden" name="sesskey" value="' . sesskey() . '">';

echo '<div class="card mb-3"><div class="card-body">';
echo '<h5>Parametres de generation</h5>';

echo '<div class="form-group row">';
echo '<label class="col-sm-4 col-form-label">Nombre d\'etudiants fictifs</label>';
echo '<div class="col-sm-3"><input type="number" name="numstudents" value="50" min="1" max="200" class="form-control"></div>';
echo '<small class="col-sm-5 form-text text-muted">Entre 1 et 200 etudiants</small>';
echo '</div>';

echo '<div class="form-group row">';
echo '<label class="col-sm-4 col-form-label">Niveau minimum (skill %)</label>';
echo '<div class="col-sm-3"><input type="number" name="minskill" value="20" min="0" max="100" class="form-control"></div>';
echo '<small class="col-sm-5 form-text text-muted">Chance de bonne reponse pour le plus faible</small>';
echo '</div>';

echo '<div class="form-group row">';
echo '<label class="col-sm-4 col-form-label">Niveau maximum (skill %)</label>';
echo '<div class="col-sm-3"><input type="number" name="maxskill" value="95" min="0" max="100" class="form-control"></div>';
echo '<small class="col-sm-5 form-text text-muted">Chance de bonne reponse pour le meilleur</small>';
echo '</div>';

echo '<div class="form-group row">';
echo '<label class="col-sm-4 col-form-label">Prefixe des comptes</label>';
echo '<div class="col-sm-3"><input type="text" name="prefix" value="bot_etudiant" class="form-control"></div>';
echo '<small class="col-sm-5 form-text text-muted">Les comptes seront nommes prefixe_001, prefixe_002...</small>';
echo '</div>';

echo '<div class="form-group row">';
echo '<label class="col-sm-4 col-form-label">Mot de passe des comptes</label>';
echo '<div class="col-sm-3"><input type="text" name="password" value="BotQuiz2026!" class="form-control"></div>';
echo '</div>';

echo '</div></div>';

echo '<button type="submit" class="btn btn-primary btn-lg">Generer les reponses</button>';
echo ' <a href="' . new moodle_url('/mod/quiz/view.php', ['id' => $cmid]) . '" class="btn btn-secondary">Annuler</a>';

echo '</form>';

echo $OUTPUT->footer();
