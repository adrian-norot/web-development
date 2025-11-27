<?php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

$DATA_DIR = __DIR__ . '/data';
if (!is_dir($DATA_DIR)) {
    @mkdir($DATA_DIR, 0775, true);
}

function username_valid($u) {
    return preg_match('/^[a-zA-Z0-9_-]{3,32}$/', $u);
}

function file_for_user($user) {
    global $DATA_DIR;
    if (!username_valid($user)) {
        $user = 'default';
    }
    return $DATA_DIR . '/data.' . $user . '.json';
}

function default_state() {
    return [
        'version' => 1,
        'targets' => [
            'Ambient' => 165,
            'Frozen' => 120,
            'Chilled' => 185,
            'Bigs' => 105,
            'Overall' => 160,
        ],
        'days' => [],
        'ongoing' => null,
        'lastUpdate' => time(),
    ];
}

function normalize_state($state) {
    $def = default_state();
    if (!isset($state['targets']) || !is_array($state['targets'])) {
        $state['targets'] = $def['targets'];
    } else {
        $state['targets'] = array_merge($def['targets'], $state['targets']);
    }
    if (!isset($state['days']) || !is_array($state['days'])) {
        $state['days'] = [];
    }
    if (!array_key_exists('ongoing', $state)) {
        $state['ongoing'] = null;
    }
    if (!isset($state['lastUpdate']) || !is_int($state['lastUpdate'])) {
        $state['lastUpdate'] = time();
    }
    return $state;
}

function load_state($user) {
    $file = file_for_user($user);
    if (!is_file($file)) {
        $state = default_state();
        save_state($user, $state);
        return $state;
    }
    $raw = @file_get_contents($file);
    if ($raw === false || $raw === '') {
        $state = default_state();
    } else {
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            $state = default_state();
        } else {
            $state = $decoded;
        }
    }
    return normalize_state($state);
}

function save_state($user, $state) {
    $file = file_for_user($user);
    $dir = dirname($file);
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    $state['lastUpdate'] = time();
    $tmp = $file . '.tmp';
    $encoded = json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    $fp = @fopen($tmp, 'c+');
    if (!$fp) {
        return false;
    }
    if (!flock($fp, LOCK_EX)) {
        fclose($fp);
        return false;
    }
    ftruncate($fp, 0);
    fwrite($fp, $encoded);
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
    @rename($tmp, $file);
    @chmod($file, 0664);
    return true;
}

function list_users() {
    global $DATA_DIR;
    if (!is_dir($DATA_DIR)) {
        return [];
    }
    $out = [];
    $files = scandir($DATA_DIR);
    foreach ($files as $f) {
        if (preg_match('/^data\.([a-zA-Z0-9_-]{3,32})\.json$/', $f, $m)) {
            $out[] = $m[1];
        }
    }
    sort($out, SORT_NATURAL | SORT_FLAG_CASE);
    return $out;
}

function ensure_day(&$state, $dayId) {
    if (!isset($state['days'][$dayId]) || !is_array($state['days'][$dayId])) {
        $state['days'][$dayId] = [
            'startedAt' => gmdate('c'),
            'finishedAt' => null,
            'picks' => [],
        ];
    } else {
        if (!isset($state['days'][$dayId]['picks']) || !is_array($state['days'][$dayId]['picks'])) {
            $state['days'][$dayId]['picks'] = [];
        }
        if (!array_key_exists('startedAt', $state['days'][$dayId])) {
            $state['days'][$dayId]['startedAt'] = gmdate('c');
        }
        if (!array_key_exists('finishedAt', $state['days'][$dayId])) {
            $state['days'][$dayId]['finishedAt'] = null;
        }
    }
}

$action = isset($_GET['action']) ? (string)$_GET['action'] : '';
if ($action === '') {
    http_response_code(400);
    echo json_encode(['error' => 'missing action']);
    exit;
}

switch ($action) {
    case 'list_users': {
        $users = list_users();
        echo json_encode(['users' => $users], JSON_UNESCAPED_SLASHES);
        break;
    }

    case 'add_user': {
        $raw = file_get_contents('php://input');
        $body = json_decode($raw, true);
        $user = '';
        if (is_array($body) && isset($body['user'])) {
            $user = (string)$body['user'];
        }
        if (!username_valid($user)) {
            http_response_code(400);
            echo json_encode(['status' => 'invalid']);
            break;
        }
        $file = file_for_user($user);
        if (!is_file($file)) {
            $ok = save_state($user, default_state());
            if (!$ok) {
                http_response_code(500);
                echo json_encode(['status' => 'failed']);
                break;
            }
        }
        $state = load_state($user);
        echo json_encode(['status' => 'ok', 'state' => $state], JSON_UNESCAPED_SLASHES);
        break;
    }

    case 'get': {
        $user = isset($_GET['user']) ? (string)$_GET['user'] : 'default';
        if (!username_valid($user)) {
            $user = 'default';
        }
        $state = load_state($user);
        echo json_encode($state, JSON_UNESCAPED_SLASHES);
        break;
    }

    case 'reset': {
        $user = isset($_GET['user']) ? (string)$_GET['user'] : 'default';
        if (!username_valid($user)) {
            $user = 'default';
        }
        $ok = save_state($user, default_state());
        echo json_encode(['status' => $ok ? 'ok' : 'failed']);
        break;
    }

    case 'start_pick': {
        $user = isset($_GET['user']) ? (string)$_GET['user'] : 'default';
        if (!username_valid($user)) {
            $user = 'default';
        }
        $raw = file_get_contents('php://input');
        $body = json_decode($raw, true);
        if (!is_array($body)) {
            http_response_code(400);
            echo json_encode(['error' => 'invalid body']);
            break;
        }
        $location = isset($body['location']) ? (string)$body['location'] : '';
        $items = isset($body['items']) ? (int)$body['items'] : 0;
        $day = isset($body['day']) ? (string)$body['day'] : '';
        $bags = null;
        if (array_key_exists('bags', $body)) {
            if ($body['bags'] === null || $body['bags'] === '') {
                $bags = null;
            } else {
                $bags = (int)$body['bags'];
                if ($bags < 0) {
                    $bags = 0;
                }
            }
        }
        $allowed_locations = ['Ambient', 'Frozen', 'Chilled', 'Bigs'];
        if (!in_array($location, $allowed_locations, true)) {
            http_response_code(400);
            echo json_encode(['error' => 'invalid location']);
            break;
        }
        if ($items <= 0) {
            http_response_code(400);
            echo json_encode(['error' => 'invalid items']);
            break;
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $day)) {
            http_response_code(400);
            echo json_encode(['error' => 'invalid day']);
            break;
        }
        $s = load_state($user);
        if (!empty($s['ongoing'])) {
            http_response_code(400);
            echo json_encode(['error' => 'ongoing_pick_exists']);
            break;
        }
        ensure_day($s, $day);
        if ($location === 'Bigs') {
            $bags = null;
        }
        $s['ongoing'] = [
            'location' => $location,
            'items' => $items,
            'bags' => $bags,
            'start' => gmdate('c'),
            'day' => $day,
        ];
        save_state($user, $s);
        echo json_encode($s, JSON_UNESCAPED_SLASHES);
        break;
    }

    case 'end_pick': {
        $user = isset($_GET['user']) ? (string)$_GET['user'] : 'default';
        if (!username_valid($user)) {
            $user = 'default';
        }
        $raw = file_get_contents('php://input');
        $body = json_decode($raw, true);
        if (!is_array($body)) {
            http_response_code(400);
            echo json_encode(['error' => 'invalid body']);
            break;
        }
        $day = isset($body['day']) ? (string)$body['day'] : '';
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $day)) {
            http_response_code(400);
            echo json_encode(['error' => 'invalid day']);
            break;
        }
        $s = load_state($user);
        if (empty($s['ongoing']) || !is_array($s['ongoing'])) {
            http_response_code(400);
            echo json_encode(['error' => 'no_ongoing_pick']);
            break;
        }
        $ongoing = $s['ongoing'];
        $dayId = isset($ongoing['day']) ? (string)$ongoing['day'] : $day;
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dayId)) {
            $dayId = $day;
        }
        ensure_day($s, $dayId);
        $location = isset($ongoing['location']) ? (string)$ongoing['location'] : 'Ambient';
        $items = isset($ongoing['items']) ? (int)$ongoing['items'] : 0;
        $bags = null;
        if (array_key_exists('bags', $ongoing)) {
            if ($ongoing['bags'] === null || $ongoing['bags'] === '') {
                $bags = null;
            } else {
                $bags = (int)$ongoing['bags'];
                if ($bags < 0) {
                    $bags = 0;
                }
            }
        }
        $startIso = isset($ongoing['start']) ? (string)$ongoing['start'] : gmdate('c');
        $endIso = gmdate('c');
        $s['days'][$dayId]['picks'][] = [
            'location' => $location,
            'items' => $items,
            'bags' => $bags,
            'start' => $startIso,
            'end' => $endIso,
        ];
        $s['ongoing'] = null;
        save_state($user, $s);
        echo json_encode($s, JSON_UNESCAPED_SLASHES);
        break;
    }

    case 'set_targets': {
        $user = isset($_GET['user']) ? (string)$_GET['user'] : 'default';
        if (!username_valid($user)) {
            $user = 'default';
        }
        $raw = file_get_contents('php://input');
        $body = json_decode($raw, true);
        if (!is_array($body)) {
            http_response_code(400);
            echo json_encode(['error' => 'invalid body']);
            break;
        }
        $s = load_state($user);
        $allowed = ['Ambient', 'Frozen', 'Chilled', 'Bigs', 'Overall'];
        foreach ($allowed as $k) {
            if (array_key_exists($k, $body)) {
                $v = (int)$body[$k];
                if ($v > 0) {
                    $s['targets'][$k] = $v;
                }
            }
        }
        save_state($user, $s);
        echo json_encode($s, JSON_UNESCAPED_SLASHES);
        break;
    }

    case 'edit_pick': {
        $user = isset($_GET['user']) ? (string)$_GET['user'] : 'default';
        if (!username_valid($user)) {
            $user = 'default';
        }
        $raw = file_get_contents('php://input');
        $body = json_decode($raw, true);
        if (!is_array($body)) {
            http_response_code(400);
            echo json_encode(['error' => 'invalid body']);
            break;
        }
        $day = isset($body['day']) ? (string)$body['day'] : '';
        $index = isset($body['index']) ? (int)$body['index'] : -1;
        $start = isset($body['start']) ? (string)$body['start'] : '';
        $end = isset($body['end']) ? (string)$body['end'] : '';
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $day)) {
            http_response_code(400);
            echo json_encode(['error' => 'invalid day']);
            break;
        }
        if ($index < 0) {
            http_response_code(400);
            echo json_encode(['error' => 'invalid index']);
            break;
        }
        if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $start)) {
            http_response_code(400);
            echo json_encode(['error' => 'invalid start']);
            break;
        }
        if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $end)) {
            http_response_code(400);
            echo json_encode(['error' => 'invalid end']);
            break;
        }
        $s = load_state($user);
        if (!isset($s['days'][$day]) || !isset($s['days'][$day]['picks']) || !is_array($s['days'][$day]['picks'])) {
            http_response_code(404);
            echo json_encode(['error' => 'day_not_found']);
            break;
        }
        if (!array_key_exists($index, $s['days'][$day]['picks'])) {
            http_response_code(404);
            echo json_encode(['error' => 'pick_not_found']);
            break;
        }
        $pick = $s['days'][$day]['picks'][$index];
        $datePart = $day;
        $startIso = $datePart . 'T' . $start . ':00Z';
        $endIso = $datePart . 'T' . $end . ':00Z';
        $pick['start'] = $startIso;
        $pick['end'] = $endIso;
        $s['days'][$day]['picks'][$index] = $pick;
        save_state($user, $s);
        echo json_encode($s, JSON_UNESCAPED_SLASHES);
        break;
    }

    case 'edit_ongoing': {
        $user = isset($_GET['user']) ? (string)$_GET['user'] : 'default';
        if (!username_valid($user)) {
            $user = 'default';
        }
        $raw = file_get_contents('php://input');
        $body = json_decode($raw, true);
        if (!is_array($body)) {
            http_response_code(400);
            echo json_encode(['error' => 'invalid body']);
            break;
        }
        $start = isset($body['start']) ? (string)$body['start'] : '';
        $items = isset($body['items']) ? (int)$body['items'] : 0;
        $bagsIn = null;
        if (array_key_exists('bags', $body)) {
            if ($body['bags'] === null || $body['bags'] === '') {
                $bagsIn = null;
            } else {
                $bagsIn = (int)$body['bags'];
                if ($bagsIn < 0) {
                    $bagsIn = 0;
                }
            }
        }
        if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $start)) {
            http_response_code(400);
            echo json_encode(['error' => 'invalid start']);
            break;
        }
        if ($items <= 0) {
            http_response_code(400);
            echo json_encode(['error' => 'invalid items']);
            break;
        }
        $s = load_state($user);
        if (empty($s['ongoing']) || !is_array($s['ongoing'])) {
            http_response_code(400);
            echo json_encode(['error' => 'no_ongoing_pick']);
            break;
        }
        $ongoing = $s['ongoing'];
        $dayId = isset($ongoing['day']) ? (string)$ongoing['day'] : gmdate('Y-m-d');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dayId)) {
            $dayId = gmdate('Y-m-d');
        }
        $startIso = $dayId . 'T' . $start . ':00Z';
        $ongoing['start'] = $startIso;
        $ongoing['items'] = $items;
        if (isset($ongoing['location']) && $ongoing['location'] === 'Bigs') {
            $ongoing['bags'] = null;
        } else {
            $ongoing['bags'] = $bagsIn;
        }
        $s['ongoing'] = $ongoing;
        save_state($user, $s);
        echo json_encode($s, JSON_UNESCAPED_SLASHES);
        break;
    }

    case 'ping': {
        echo json_encode(['status' => 'ok'], JSON_UNESCAPED_SLASHES);
        break;
    }

    case 'version': {
        echo json_encode(['version' => 1], JSON_UNESCAPED_SLASHES);
        break;
    }

    default: {
        http_response_code(400);
        echo json_encode(['error' => 'unknown action']);
        break;
    }
}

