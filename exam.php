<?php
session_start();
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
require 'config/database.php';
require 'includes/functions.php';

requireLogin('login.php');

$attemptId = (int) ($_GET['attempt_id'] ?? $_SESSION['attempt_id'] ?? 0);

$stmt = $pdo->prepare("
    SELECT ea.*, e.title, e.duration, NOW() AS db_now
    FROM exam_attempts ea
    JOIN exams e ON e.id = ea.exam_id
    WHERE ea.id = ? AND ea.user_id = ? AND ea.status IN ('in_progress','flagged')
    LIMIT 1
");
$stmt->execute([$attemptId, $_SESSION['user_id']]);
$attempt = $stmt->fetch();

if (!$attempt) {
    unset($_SESSION['attempt_id']);
    header('Location: instructions.php');
    exit();
}

if (strtotime($attempt['ends_at']) <= strtotime($attempt['db_now'])) {
    $pdo->prepare("UPDATE exam_attempts SET status = 'auto_submitted', submitted_at = NOW() WHERE id = ? AND submitted_at IS NULL")
        ->execute([$attemptId]);
    unset($_SESSION['attempt_id']);
    header('Location: instructions.php?msg=expired');
    exit();
}

$stmt = $pdo->prepare("SELECT * FROM questions WHERE exam_id = ? ORDER BY RAND()");
$stmt->execute([$attempt['exam_id']]);
$questions = $stmt->fetchAll();
?>

<!DOCTYPE html>
<html>
<head>
    <title><?= htmlspecialchars($attempt['title']); ?></title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="exam-body">

<header class="exam-header">
    <div>
        <strong><?= htmlspecialchars($attempt['title']); ?></strong>
        <span id="violationStatus">Violations: <?= (int) $attempt['violation_count']; ?></span>
    </div>
    <div id="timer"></div>
</header>

<main class="exam-layout">
    <section class="question-panel" id="questionPanel" hidden>
        <form id="examForm" method="POST" action="submit-exam.php">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()); ?>">
            <input type="hidden" name="attempt_id" value="<?= (int) $attemptId; ?>">

            <?php foreach ($questions as $index => $question): ?>
            <fieldset class="question-card">
                <legend>Question <?= $index + 1; ?></legend>
                <p><?= nl2br(htmlspecialchars($question['question'])); ?></p>
                <?php foreach (['A' => 'option_a', 'B' => 'option_b', 'C' => 'option_c', 'D' => 'option_d'] as $letter => $field): ?>
                <label>
                    <input type="radio" name="answers[<?= (int) $question['id']; ?>]" value="<?= $letter; ?>">
                    <?= $letter; ?>. <?= htmlspecialchars($question[$field]); ?>
                </label>
                <?php endforeach; ?>
            </fieldset>
            <?php endforeach; ?>

            <button type="submit">Submit Exam</button>
        </form>
    </section>

    <aside class="proctor-panel">
        <video id="cameraPreview" autoplay muted playsinline></video>
        <p id="proctorStatus">Camera monitoring active.</p>
    </aside>
</main>

<div class="modal-backdrop" id="violationModal" hidden>
    <section class="exam-modal" role="dialog" aria-modal="true" aria-labelledby="violationTitle">
        <div class="modal-icon" aria-hidden="true">!</div>
        <div>
            <h2 id="violationTitle">Exam Rule Warning</h2>
            <p id="violationMessage">This action is restricted during the exam.</p>
            <p class="modal-count" id="violationModalCount">Violations: <?= (int) $attempt['violation_count']; ?></p>
        </div>
        <button type="button" id="violationCloseBtn">I Understand</button>
    </section>
</div>

<div class="lockdown-overlay" id="lockdownOverlay" hidden>
    <section class="lockdown-panel" aria-live="assertive">
        <h2>Exam Locked</h2>
        <p id="lockdownMessage">Return to the exam window and fullscreen mode to continue.</p>
        <button type="button" id="resumeExamBtn">Return to Exam</button>
    </section>
</div>

<script src="https://cdn.jsdelivr.net/npm/@mediapipe/face_mesh/face_mesh.js"></script>
<script>
const attemptId = <?= (int) $attemptId; ?>;
const csrfToken = <?= json_encode(csrfToken()); ?>;
const endsAt = new Date(<?= json_encode($attempt['ends_at']); ?>.replace(' ', 'T')).getTime();
let violations = <?= (int) $attempt['violation_count']; ?>;
let submitting = false;
let warningAudioContext = null;
let cameraStream = null;
let faceMesh = null;
let faceMonitorTimer = null;
let lastMovementViolationAt = 0;
let movementStrikeCount = 0;
let faceMonitorBusy = false;
let modalCloseTimer = null;
let baseQuestionAccess = false;
const environmentLocks = new Map();
const lastEventLoggedAt = {};

function setQuestionAccess(isAllowed, message) {
    baseQuestionAccess = isAllowed;
    updateQuestionAccess(message);
}

function setEnvironmentLock(key, message) {
    if (message) {
        environmentLocks.set(key, message);
    } else {
        environmentLocks.delete(key);
    }

    updateQuestionAccess(message || 'Camera monitoring active.');
}

function updateQuestionAccess(message) {
    const questionPanel = document.getElementById('questionPanel');
    const examForm = document.getElementById('examForm');
    const lockdownOverlay = document.getElementById('lockdownOverlay');
    const lockdownMessage = document.getElementById('lockdownMessage');
    const activeLock = environmentLocks.values().next().value || '';
    const isAllowed = baseQuestionAccess && environmentLocks.size === 0;

    questionPanel.hidden = !isAllowed;
    examForm.querySelectorAll('input, button').forEach(function(control) {
        if (control.type !== 'hidden') {
            control.disabled = !isAllowed;
        }
    });

    if (lockdownOverlay) {
        lockdownOverlay.hidden = isAllowed;
    }

    if (lockdownMessage && (activeLock || message)) {
        lockdownMessage.textContent = activeLock || message;
    }

    if (message || activeLock) {
        document.getElementById('proctorStatus').textContent = activeLock || message;
    }
}

function getWarningAudioContext() {
    const AudioContextClass = window.AudioContext || window.webkitAudioContext;

    if (!AudioContextClass) {
        return null;
    }

    if (!warningAudioContext) {
        warningAudioContext = new AudioContextClass();
    }

    if (warningAudioContext.state === 'suspended') {
        warningAudioContext.resume().catch(function(){});
    }

    return warningAudioContext;
}

function playWarningSound() {
    const audioContext = getWarningAudioContext();

    if (!audioContext) {
        return;
    }

    const oscillator = audioContext.createOscillator();
    const gain = audioContext.createGain();
    const now = audioContext.currentTime;

    oscillator.type = 'sine';
    oscillator.frequency.setValueAtTime(880, now);
    oscillator.frequency.setValueAtTime(660, now + 0.16);

    gain.gain.setValueAtTime(0.001, now);
    gain.gain.exponentialRampToValueAtTime(0.35, now + 0.03);
    gain.gain.exponentialRampToValueAtTime(0.001, now + 0.45);

    oscillator.connect(gain);
    gain.connect(audioContext.destination);
    oscillator.start(now);
    oscillator.stop(now + 0.5);
}

['pointerdown', 'keydown'].forEach(function(eventName) {
    document.addEventListener(eventName, function() {
        getWarningAudioContext();
    }, {once: true});
});

function violationStage(count) {
    if (count >= 5) {
        return {
            title: 'Exam Auto-Submit',
            message: 'You have reached 5 violations. Your exam is being submitted automatically.',
            level: 'danger'
        };
    }

    if (count >= 3) {
        return {
            title: 'Student Flagged',
            message: 'You have reached 3 violations. Your attempt has been flagged for admin review.',
            level: 'danger'
        };
    }

    if (count === 2) {
        return {
            title: 'Strong Warning',
            message: 'Second violation recorded. Further violations may flag or submit your exam.',
            level: 'strong'
        };
    }

    return {
        title: 'Warning',
        message: 'First violation recorded. Follow the exam rules to avoid being flagged.',
        level: 'warning'
    };
}

function showViolationModal(title, message, level) {
    const modal = document.getElementById('violationModal');
    const panel = modal.querySelector('.exam-modal');

    document.getElementById('violationTitle').textContent = title;
    document.getElementById('violationMessage').textContent = message;
    document.getElementById('violationModalCount').textContent = 'Violations: ' + violations;

    panel.classList.remove('is-warning', 'is-strong', 'is-danger');
    panel.classList.add('is-' + level);
    modal.hidden = false;

    clearTimeout(modalCloseTimer);
    modalCloseTimer = setTimeout(function() {
        modal.hidden = true;
    }, 6500);
}

document.getElementById('violationCloseBtn').addEventListener('click', function() {
    document.getElementById('violationModal').hidden = true;
});

async function logEvent(type, details) {
    document.getElementById('proctorStatus').textContent = details;
    playWarningSound();

    violations += 1;

    try {
        const response = await fetch('proctor-event.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({
            attempt_id: attemptId,
            csrf_token: csrfToken,
            event_type: type,
            details: details
        })
        });

        if (response.ok) {
            const data = await response.json();
            if (data && data.ok && Number.isInteger(data.violations)) {
                violations = data.violations;
            }
        }
    } catch (error) {}

    document.getElementById('violationStatus').textContent = 'Violations: ' + violations;

    const stage = violationStage(violations);
    showViolationModal(stage.title, details + ' ' + stage.message, stage.level);

    if (violations >= 5 && !submitting) {
        submitting = true;
        document.getElementById('proctorStatus').textContent =
            'Too many suspicious events. Your exam will be submitted automatically.';
        playWarningSound();
        document.getElementById('examForm').submit();
    }
}

function logEventThrottled(type, details, cooldownMs) {
    const now = Date.now();

    if (lastEventLoggedAt[type] && now - lastEventLoggedAt[type] < cooldownMs) {
        return;
    }

    lastEventLoggedAt[type] = now;
    logEvent(type, details);
}

function stopFaceMonitoring() {
    if (faceMonitorTimer) {
        clearInterval(faceMonitorTimer);
        faceMonitorTimer = null;
    }
    movementStrikeCount = 0;
}

function isFaceLookingAway(landmarks, video) {
    if (!landmarks || landmarks.length < 264) {
        return true;
    }

    const leftEye = landmarks[33];
    const rightEye = landmarks[263];
    const nose = landmarks[1];
    const chin = landmarks[152];
    const eyeDistance = Math.abs(rightEye.x - leftEye.x);

    if (eyeDistance <= 0.02) {
        return true;
    }

    const eyeCenterX = (leftEye.x + rightEye.x) / 2;
    const noseOffset = Math.abs(nose.x - eyeCenterX) / eyeDistance;
    const faceCenterX = nose.x;
    const faceCenterY = (nose.y + chin.y) / 2;
    const faceHeight = Math.abs(chin.y - Math.min(leftEye.y, rightEye.y));

    return (
        noseOffset > 0.45 ||
        faceCenterX < 0.18 ||
        faceCenterX > 0.82 ||
        faceCenterY < 0.18 ||
        faceCenterY > 0.88 ||
        faceHeight < 0.16
    );
}

async function handleMovementCheck(results) {
    const video = document.getElementById('cameraPreview');
    const faces = results.multiFaceLandmarks || [];
    const isViolation = faces.length !== 1 || isFaceLookingAway(faces[0], video);

    if (isViolation) {
        movementStrikeCount += 1;
    } else {
        movementStrikeCount = 0;
        if (!document.getElementById('questionPanel').hidden) {
            document.getElementById('proctorStatus').textContent = 'Camera monitoring active.';
        }
    }

    const now = Date.now();
    if (movementStrikeCount >= 3 && now - lastMovementViolationAt > 5000) {
        lastMovementViolationAt = now;
        await logEvent(
            faces.length === 0 ? 'face_missing' : 'face_looking_away',
            faces.length === 0
                ? 'Candidate moved away from the camera.'
                : 'Candidate looked away or moved out of camera position.'
        );
    }
}

async function startFaceMonitoring() {
    const video = document.getElementById('cameraPreview');

    if (!window.FaceMesh || faceMonitorTimer) {
        return;
    }

    faceMesh = new FaceMesh({
        locateFile: function(file) {
            return 'https://cdn.jsdelivr.net/npm/@mediapipe/face_mesh/' + file;
        }
    });

    faceMesh.setOptions({
        maxNumFaces: 1,
        refineLandmarks: false,
        minDetectionConfidence: 0.6,
        minTrackingConfidence: 0.6
    });
    faceMesh.onResults(handleMovementCheck);

    faceMonitorTimer = setInterval(async function() {
        if (faceMonitorBusy || video.readyState < 2 || !faceMesh) {
            return;
        }

        faceMonitorBusy = true;
        try {
            await faceMesh.send({image: video});
        } catch (error) {
            stopFaceMonitoring();
            document.getElementById('proctorStatus').textContent =
                'Face movement monitoring could not start. Camera stream is still required.';
        } finally {
            faceMonitorBusy = false;
        }
    }, 800);
}

function requestFullscreen() {
    if (!document.fullscreenElement && document.documentElement.requestFullscreen) {
        return document.documentElement.requestFullscreen().catch(function(){});
    }

    return Promise.resolve();
}

function requireFullscreenLock() {
    if (!document.fullscreenElement) {
        setEnvironmentLock('fullscreen', 'Fullscreen mode is required. Click Return to Exam to continue.');
    }
}

async function returnToExam() {
    await requestFullscreen();

    if (!document.hidden && document.hasFocus()) {
        setEnvironmentLock('focus', '');
    }

    if (document.fullscreenElement) {
        setEnvironmentLock('fullscreen', '');
    } else {
        requireFullscreenLock();
    }
}

async function startCamera() {
    try {
        const stream = await navigator.mediaDevices.getUserMedia({video: true, audio: false});
        cameraStream = stream;
        document.getElementById('cameraPreview').srcObject = stream;
        setQuestionAccess(true, 'Camera monitoring active.');
        startFaceMonitoring();
        stream.getVideoTracks().forEach(function(track) {
            track.addEventListener('ended', function() {
                stopFaceMonitoring();
                setQuestionAccess(false, 'Camera is off. Exam questions are locked until camera monitoring resumes.');
                logEvent('camera_ended', 'Camera was turned off or disconnected.');
            });
            track.addEventListener('mute', function() {
                stopFaceMonitoring();
                setQuestionAccess(false, 'Camera is unavailable. Exam questions are locked until camera monitoring resumes.');
                logEvent('camera_muted', 'Camera stream became unavailable.');
            });
            track.addEventListener('unmute', function() {
                if (track.readyState === 'live') {
                    setQuestionAccess(true, 'Camera monitoring active.');
                    startFaceMonitoring();
                }
            });
        });
    } catch (error) {
        setQuestionAccess(false, 'Camera access is required before exam questions can be shown.');
        logEvent('camera_denied', 'Camera access is required during the exam.');
    }
}

document.addEventListener('visibilitychange', function() {
    if (document.hidden) {
        setEnvironmentLock('focus', 'The exam tab is not active. Return to this tab to continue.');
        logEventThrottled('tab_hidden', 'Tab switching or browser minimizing was detected.', 5000);
    } else if (document.hasFocus()) {
        setEnvironmentLock('focus', '');
    }
});

window.addEventListener('blur', function() {
    setEnvironmentLock('focus', 'The exam window lost focus. Return to the exam window to continue.');
    logEventThrottled('window_blur', 'Exam window lost focus.', 5000);
});

window.addEventListener('focus', function() {
    if (!document.hidden) {
        setEnvironmentLock('focus', '');
    }
    requireFullscreenLock();
});

document.addEventListener('fullscreenchange', function() {
    if (!document.fullscreenElement) {
        setEnvironmentLock('fullscreen', 'Fullscreen mode was exited. Click Return to Exam to continue.');
        logEventThrottled('fullscreen_exit', 'Fullscreen mode was exited.', 5000);
    } else {
        setEnvironmentLock('fullscreen', '');
    }
});

document.addEventListener('contextmenu', function(event) {
    event.preventDefault();
    logEventThrottled('right_click', 'Right-click is disabled during the exam. This is a violation of the exam rules.', 1500);
});

document.addEventListener('keydown', function(event) {
    const key = event.key.toLowerCase();
    const blockedCtrlKey = event.ctrlKey && ['c', 'v', 'x', 'u'].includes(key);
    const blockedDevToolsKey =
        event.key === 'F12' ||
        (event.ctrlKey && event.shiftKey && ['i', 'j', 'c'].includes(key));

    if (blockedCtrlKey || blockedDevToolsKey) {
        event.preventDefault();
        event.stopPropagation();
        logEventThrottled(
            blockedDevToolsKey ? 'developer_tools_shortcut' : 'restricted_shortcut',
            blockedDevToolsKey
                ? 'Developer tools shortcuts are restricted during the exam.'
                : 'Copy, paste, cut, and view-source shortcuts are restricted during the exam.',
            1500
        );
    }
});

['copy', 'cut', 'paste'].forEach(function(eventName) {
    document.addEventListener(eventName, function(event) {
        event.preventDefault();
        logEventThrottled(eventName, 'Restricted action detected: ' + eventName + '.', 1500);
    });
});

window.addEventListener('beforeunload', function(event) {
    if (!submitting) {
        event.preventDefault();
        event.returnValue = '';
    }
});

document.getElementById('resumeExamBtn').addEventListener('click', returnToExam);
document.getElementById('examForm').addEventListener('submit', function() {
    submitting = true;
});

setInterval(function() {
    const remaining = endsAt - Date.now();
    if (remaining <= 0 && !submitting) {
        submitting = true;
        document.getElementById('examForm').submit();
        return;
    }

    const minutes = Math.floor(remaining / 60000);
    const seconds = Math.floor((remaining % 60000) / 1000);
    document.getElementById('timer').textContent = minutes + ':' + String(seconds).padStart(2, '0');
}, 1000);

requestFullscreen();
requireFullscreenLock();
startCamera();
</script>
<script src="assets/js/app.js"></script>

</body>
</html>
