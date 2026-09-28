<?php
$c = file_get_contents(__DIR__ . '/qa_api_test.php');
$ids = ['SEC-010','FILE-006','SEC-009','FUNC-002','FUNC-008','FILE-008','SEC-012','AUTH-010','VAL-006','FILE-001','FUNC-007','FILE-003','EDIT-001','AUTH-016'];
foreach ($ids as $id) {
    echo "$id: " . substr_count($c, $id) . "\n";
}
echo "Total R( calls: " . substr_count($c, "R('") . "\n";