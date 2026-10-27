<?php
// find_duplicates.php
declare(strict_types=1);

function readCsv(string $path): array {
    $rows = [];
    $fh = fopen($path, 'r');
    if ($fh === false) {
        throw new RuntimeException("Не удалось открыть $path");
    }
    $header = fgetcsv($fh, 0, ';', '"', '\\'); // явный $escape
    if ($header === false) {
        fclose($fh);
        return [];
    }
    while (($row = fgetcsv($fh, 0, ';', '"', '\\')) !== false) {
        if (count($row) !== count($header)) continue;
        $rows[] = array_combine($header, $row);
    }
    fclose($fh);
    return $rows;
}

function normUnp(?string $v): string {
    return preg_replace('/\D+/', '', (string)$v);
}

function normPhone(?string $v): string {
    $d = preg_replace('/\D+/', '', (string)$v);
    if (strlen($d) === 9)  $d = '375' . $d;
    if (strlen($d) === 10 && $d[0] === '8') $d = '375' . substr($d, 1);
    if (strlen($d) === 11 && str_starts_with($d, '80')) $d = '375' . substr($d, 2);
    return $d;
}

function normEmail(?string $v): string {
    return strtolower(trim((string)$v)); // без mbstring
}

function normName(?string $v): string {
    $s = strtolower(trim((string)$v)); // без mbstring
    $s = str_replace(['«', '»', '"', "'"], '', $s);
    $s = preg_replace('/\b(ооо|чтуп|чуп|одо|ип|унитарное предприятие|общество с ограниченной ответственностью)\b/u', '', $s);
    $s = preg_replace('/[^a-zа-я0-9]+/u', '', $s);
    return $s;
}

$clients = readCsv(__DIR__ . '/data/crm_clients.csv');
$tickets = readCsv(__DIR__ . '/data/tickets.csv');

$ticketsByClient = [];
foreach ($tickets as $t) {
    $ticketsByClient[$t['client_id']][] = $t;
}

$byUnp = $byPhone = $byEmail = $byName = [];
foreach ($clients as $c) {
    $id = $c['client_id'];
    if ($u = normUnp($c['unp']))       $byUnp[$u][] = $id;
    if ($p = normPhone($c['phone']))   $byPhone[$p][] = $id;
    if ($e = normEmail($c['email']))   $byEmail[$e][] = $id;
    if ($n = normName($c['name']))     $byName[$n][] = $id;
}

$parent = [];
$find = function (string $x) use (&$parent, &$find): string {
    if (!isset($parent[$x])) $parent[$x] = $x;
    return $parent[$x] === $x ? $x : ($parent[$x] = $find($parent[$x]));
};
$union = function (string $a, string $b) use (&$parent, &$find): void {
    $ra = $find($a); $rb = $find($b);
    if ($ra !== $rb) $parent[$ra] = $rb;
};
foreach ($clients as $c) $find($c['client_id']);
foreach ([$byUnp, $byPhone, $byEmail, $byName] as $index) {
    foreach ($index as $ids) {
        for ($i = 1; $i < count($ids); $i++) $union($ids[0], $ids[$i]);
    }
}

$groups = [];
foreach ($clients as $c) $groups[$find($c['client_id'])][] = $c;

$result = [];
foreach ($groups as $root => $members) {
    if (count($members) < 2) continue;

    $reasons = [];
    $strong = false; $medium = false; $weak = false;

    $unps = array_filter(array_map(fn($m) => normUnp($m['unp']), $members));
    if (count(array_unique($unps)) === 1 && count($unps) >= 2) {
        $reasons[] = 'совпадает УНП ' . reset($unps);
        $strong = true;
    }
    foreach ([['phone', 'телефон'], ['email', 'email'], ['name', 'название']] as [$f, $label]) {
        $norm = array_map(fn($m) => $f === 'phone' ? normPhone($m[$f]) : ($f === 'email' ? normEmail($m[$f]) : normName($m[$f])), $members);
        $norm = array_filter($norm);
        if (count($norm) >= 2 && count(array_unique($norm)) === 1) {
            $reasons[] = "совпадает $label";
            if ($f === 'phone' || $f === 'email') $medium = true; else $weak = true;
        }
    }

    $confidence = $strong ? 'точно' : ($medium ? 'вероятно' : 'проверить вручную');

    usort($members, function ($a, $b) use ($ticketsByClient) {
        $ta = count($ticketsByClient[$a['client_id']] ?? []);
        $tb = count($ticketsByClient[$b['client_id']] ?? []);
        if ($ta !== $tb) return $tb <=> $ta;
        if ($a['created_at'] !== $b['created_at']) return strcmp($a['created_at'], $b['created_at']);
        return (normUnp($b['unp']) !== '') <=> (normUnp($a['unp']) !== '');
    });
    $main = $members[0];
    $moved = 0;
    foreach (array_slice($members, 1) as $m) $moved += count($ticketsByClient[$m['client_id']] ?? []);

    $result[] = [
        'members' => $members,
        'reasons' => array_values(array_unique($reasons)),
        'confidence' => $confidence,
        'main' => $main,
        'moved' => $moved,
    ];
}

foreach ($result as $i => $g) {
    echo "Группа " . ($i + 1) . " (" . $g['confidence'] . ")\n";
    echo "  Причины: " . implode('; ', $g['reasons']) . "\n";
    foreach ($g['members'] as $m) {
        $cnt = count($ticketsByClient[$m['client_id']] ?? []);
        echo "  - {$m['client_id']} | {$m['name']} | УНП={$m['unp']} | тел={$m['phone']} | email={$m['email']} | обращений: $cnt\n";
    }
    echo "  Главная: {$g['main']['client_id']} ({$g['main']['name']}) (больше всего обращений / раньше создана / заполнен УНП)\n";
    echo "  Перейдёт обращений: {$g['moved']}\n\n";
}

echo "Похожие, но не дубли (проверить вручную):\n";
$names = [];
foreach ($clients as $c) $names[normName($c['name'])][] = $c;
foreach ($names as $n => $list) {
    if (count($list) < 2) continue;
    $unps = array_filter(array_map(fn($c) => normUnp($c['unp']), $list));
    $phones = array_filter(array_map(fn($c) => normPhone($c['phone']), $list));
    $emails = array_filter(array_map(fn($c) => normEmail($c['email']), $list));
    if (count(array_unique($unps)) > 1 && count(array_unique($phones)) > 1 && count(array_unique($emails)) > 1) {
        echo "  - одинаковое название, но разные УНП/телефоны/email: ";
        echo implode(', ', array_map(fn($c) => $c['client_id'] . ' (' . $c['name'] . ')', $list)) . "\n";
    }
}