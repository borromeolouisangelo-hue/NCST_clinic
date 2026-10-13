$rows = DB::select('SELECT id, complaint, diagnosis_image, treatment_image FROM consultations ORDER BY id DESC LIMIT 4');
foreach ($rows as $r) {
    echo $r->id . ' | ' . $r->complaint . ' | diag=' . var_export($r->diagnosis_image, true) . ' | treat=' . var_export($r->treatment_image, true) . PHP_EOL;
}
$files = glob(storage_path('app/private/consultations/*'));
echo 'private files: ' . count($files) . PHP_EOL;
foreach ($files as $f) {
    echo '  ' . basename($f) . ' (' . filesize($f) . ' bytes)' . PHP_EOL;
}
$public = glob(public_path('consultations/*')) + glob(public_path('assets/images/consultations/*'));
echo 'files under public/: ' . count($public) . PHP_EOL;
